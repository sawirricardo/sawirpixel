<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Enums\TokenAbility;
use App\Mcp\McpAccess;
use App\Models\Generation;
use App\Services\Generations\CustomerGeneration;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
final class GetGenerationTool extends Tool
{
    protected string $name = 'get_generation';

    protected string $description = <<<'MARKDOWN'
        Get one of your generations by id: its status (pending, queued, processing, succeeded or failed),
        estimated and actual charge, and, once succeeded, image_url (valid until image_url_expires_at).
        Requires the generations:read scope.
        MARKDOWN;

    /**
     * A generation as customers see it, the same fields as the API's.
     *
     * @return array<string, mixed>
     */
    public static function present(Generation $generation): array
    {
        return ['id' => $generation->id, ...CustomerGeneration::attributes($generation)];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $access = McpAccess::check($request, TokenAbility::GenerationsRead);

        if ($access instanceof Response) {
            return $access;
        }

        [$user, $organization] = $access;
        $request->validate(['id' => ['required', 'integer']]);

        $generation = Generation::query()->whereBelongsTo($user)->whereBelongsTo($organization)->find($request->integer('id'));

        return $generation instanceof Generation
            ? Response::structured(self::present($generation))
            : Response::error('No generation with that id in this organization.');
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('The generation id, as returned by generate_image or list_generations.')->required(),
        ];
    }
}
