<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Enums\TokenAbility;
use App\Mcp\McpAccess;
use App\Models\Generation;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
final class ListGenerationsTool extends Tool
{
    public const int PER_PAGE = 20;

    protected string $name = 'list_generations';

    protected string $description = <<<'MARKDOWN'
        List your generations in the token's organization, newest first, 20 per page. Requires the
        generations:read scope.
        MARKDOWN;

    public function handle(Request $request): Response|ResponseFactory
    {
        $access = McpAccess::check($request, TokenAbility::GenerationsRead);

        if ($access instanceof Response) {
            return $access;
        }

        [$user, $organization] = $access;
        $request->validate(['page' => ['nullable', 'integer', 'min:1']]);

        $page = $request->filled('page') ? $request->integer('page') : 1;
        $generations = Generation::query()
            ->whereBelongsTo($user)
            ->whereBelongsTo($organization)
            ->latest('id')
            ->paginate(self::PER_PAGE, page: $page);

        return Response::structured([
            'generations' => array_map(GetGenerationTool::present(...), $generations->items()),
            'page' => $generations->currentPage(),
            'has_more' => $generations->hasMorePages(),
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'page' => $schema->integer()->description('Page number, from 1.')->min(1),
        ];
    }
}
