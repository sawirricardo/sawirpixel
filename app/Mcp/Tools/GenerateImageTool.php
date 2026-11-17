<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Actions\CreateGeneration;
use App\Enums\Pixelforge\ImageQuality;
use App\Enums\Pixelforge\ImageSize;
use App\Enums\TokenAbility;
use App\Mcp\GenerationArguments;
use App\Mcp\McpAccess;
use App\Models\Generation;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

final class GenerateImageTool extends Tool
{
    protected string $name = 'generate_image';

    protected string $description = <<<'MARKDOWN'
        Generate an image from a prompt, spending the organization's credits. The most it can cost is set
        aside straight away; once the image finishes you're charged what it actually cost, or nothing if
        it fails. Images take seconds to minutes: call get_generation with the returned id until status
        is succeeded (then image_url is set, valid until image_url_expires_at) or failed. Pass parent_id to
        continue from one of your finished images. Requires the generations:create scope and a verified
        email address.
        MARKDOWN;

    /**
     * The generation inputs, shared with estimate_price.
     *
     * @return array<string, Type>
     */
    public static function inputSchema(JsonSchema $schema, bool $promptRequired): array
    {
        $prompt = $schema->string()->description('What to draw, up to '.GenerationArguments::MAX_PROMPT_LENGTH.' characters.')->max(GenerationArguments::MAX_PROMPT_LENGTH);

        return [
            'prompt' => $promptRequired ? $prompt->required() : $prompt,
            'size' => $schema->string()->enum(array_map(fn (ImageSize $size): string => $size->value, ImageSize::cases()))
                ->description('1024x1024 (square), 1536x1024 (landscape) or 1024x1536 (portrait).')->required(),
            'quality' => $schema->string()->enum(array_map(fn (ImageQuality $quality): string => $quality->value, ImageQuality::cases()))
                ->description('low is fastest and cheapest; high has the most detail.')->required(),
            'reference_images' => $schema->array()->items($schema->string())
                ->description('Up to '.GenerationArguments::MAX_REFERENCE_IMAGES.' http(s) image URLs to guide the result (one fewer when continuing from a parent).'),
            'parent_id' => $schema->integer()->description('The id of one of your finished generations to continue from.'),
        ];
    }

    public function handle(Request $request, CreateGeneration $createGeneration): Response|ResponseFactory
    {
        $access = McpAccess::check($request, TokenAbility::GenerationsCreate);

        if ($access instanceof Response) {
            return $access;
        }

        [$user, $organization] = $access;

        if (Gate::forUser($user)->denies('create', [Generation::class, $organization])) {
            return Response::error(sprintf("Your role in %s doesn't allow generating images.", $organization->name));
        }

        if (! $user->hasVerifiedEmail()) {
            return Response::error('Verify your email address before generating images.');
        }

        $arguments = GenerationArguments::from($request);
        $arguments->validate($arguments->generationRules(promptRequired: true));

        try {
            $generation = $createGeneration->handle(
                $user,
                $organization,
                $arguments->prompt(),
                $arguments->size(),
                $arguments->quality(),
                $arguments->referenceImages(),
                $arguments->parentId(),
            );
        } catch (ValidationException $validationException) {
            // Limits, credits or an unusable parent: say why, the same way the API does.
            return Response::error(implode(' ', $validationException->validator->errors()->all()));
        }

        return Response::structured(GetGenerationTool::present($generation));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return self::inputSchema($schema, promptRequired: true);
    }
}
