<?php

declare(strict_types=1);

use App\Enums\TokenAbility;
use App\Models\Generation;
use App\Services\Billing\CreditLedger;
use Brick\Math\BigDecimal;

test('shows the token organization balance and what is set aside', function (): void {
    $user = organizationOwner();
    fundOrganization($user->currentOrganization, '10');
    $ledger = resolve(CreditLedger::class);
    $ledger->hold($ledger->lockOrganization($user->current_organization_id), Generation::factory()->for($user)->for($user->currentOrganization)->create(), BigDecimal::of('0.25'));

    $this->withToken(apiToken($user, [TokenAbility::CreditsRead]))->getJson(route('api.credits'))->assertOk()->assertJson(['data' => [
        'type' => 'credit-balances',
        'id' => (string) $user->current_organization_id,
        'attributes' => [
            'organization' => 'Acme Studio',
            'balance' => ['amount' => '9.7500000000', 'formatted' => '$9.75'],
            'reserved' => ['amount' => '0.2500000000', 'formatted' => '$0.25'],
        ],
    ]]);
});

test('needs the credits:read scope', function (): void {
    $this->withToken(apiToken(organizationOwner(), [TokenAbility::GenerationsRead]))->getJson(route('api.credits'))->assertForbidden();
});
