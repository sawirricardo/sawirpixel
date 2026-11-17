<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Enums\TokenAbility;
use App\Mcp\GenerationArguments;
use App\Mcp\McpAccess;
use App\Services\Generations\GenerationLimiter;
use App\Services\Pricing\GenerationCostEstimator;
use Brick\Math\BigDecimal;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
final class EstimatePriceTool extends Tool
{
    protected string $name = 'estimate_price';

    protected string $description = <<<'MARKDOWN'
        Estimate what generating an image would cost, before generating it. Returns the price range in
        USD, whether the organization's credits cover the most it could cost, and, if the organization's
        limits would refuse it, why. Requires the generations:read scope.
        MARKDOWN;

    public function handle(Request $request, GenerationCostEstimator $estimator, GenerationLimiter $limiter): Response|ResponseFactory
    {
        $access = McpAccess::check($request, TokenAbility::GenerationsRead);

        if ($access instanceof Response) {
            return $access;
        }

        [$user, $organization] = $access;
        $arguments = GenerationArguments::from($request);
        $arguments->validate($arguments->generationRules(promptRequired: false));

        $estimate = $estimator->estimate(
            $arguments->prompt(),
            $arguments->size(),
            $arguments->quality(),
            count($arguments->referenceImages()) + ($arguments->parentId() === null ? 0 : 1),
        );

        return Response::structured([
            ...$estimate->toCustomerArray(),
            'affordable' => ! BigDecimal::of($organization->credit_balance)->isLessThan($estimate->chargeMax),
            'blocked' => $limiter->blockedReason($user, $organization, $arguments->size(), $arguments->quality(), $estimate->chargeMax),
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return GenerateImageTool::inputSchema($schema, promptRequired: false);
    }
}
