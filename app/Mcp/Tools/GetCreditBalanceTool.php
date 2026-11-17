<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Enums\TokenAbility;
use App\Mcp\McpAccess;
use App\Services\Billing\CreditLedger;
use App\Services\Pricing\Money;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
final class GetCreditBalanceTool extends Tool
{
    protected string $name = 'get_credit_balance';

    protected string $description = <<<'MARKDOWN'
        The organization's credit balance in USD, and how much of it is set aside for images still in
        progress. Requires the credits:read scope.
        MARKDOWN;

    public function handle(Request $request, CreditLedger $ledger): Response|ResponseFactory
    {
        $access = McpAccess::check($request, TokenAbility::CreditsRead);

        if ($access instanceof Response) {
            return $access;
        }

        [, $organization] = $access;

        return Response::structured([
            'organization' => $organization->name,
            'balance' => Money::toArray($organization->credit_balance),
            'reserved' => Money::toArray($ledger->reserved($organization)),
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
