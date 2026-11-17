<?php

declare(strict_types=1);

use App\Enums\CreditTransactionType;
use App\Exceptions\CreditLedgerConflictException;
use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\Organization;
use App\Services\Billing\CreditLedger;
use Brick\Math\BigDecimal;

function heldGeneration(Organization $organization, string $hold): Generation
{
    $ledger = resolve(CreditLedger::class);
    $generation = Generation::factory()->queued()->for($organization)->create(['estimated_charge_max' => $hold]);
    $ledger->hold($ledger->lockOrganization($organization->id), $generation, BigDecimal::of($hold));

    return $generation;
}

test('returns the hold and charges the actual cost when a generation succeeds', function (): void {
    $organization = Organization::factory()->create();
    fundOrganization($organization, '1');
    $generation = heldGeneration($organization, '0.4');

    resolve(CreditLedger::class)->settle($generation, BigDecimal::of('0.3818875'));

    expect($organization->refresh()->credit_balance)->toBe('0.6181125000');
    expect(CreditTransaction::query()->where('generation_id', $generation->id)->orderBy('id')->pluck('type')->map->value->all())
        ->toBe(['hold', 'hold_release', 'charge']);
    expect((string) resolve(CreditLedger::class)->reserved($organization))->toBe('0');
    expectBalanceMatchesLedger($organization);
});

test('returns the whole hold when a generation fails', function (): void {
    $organization = Organization::factory()->create();
    fundOrganization($organization, '1');
    $generation = heldGeneration($organization, '0.4');

    resolve(CreditLedger::class)->settle($generation, null);

    expect($organization->refresh()->credit_balance)->toBe('1.0000000000');
    expectBalanceMatchesLedger($organization);
});

test('settles a generation only once however often it is asked', function (): void {
    $organization = Organization::factory()->create();
    fundOrganization($organization, '1');
    $generation = heldGeneration($organization, '0.4');
    $ledger = resolve(CreditLedger::class);

    $ledger->settle($generation, BigDecimal::of('0.3'));
    $ledger->settle($generation, BigDecimal::of('0.3'));
    $ledger->settle($generation, null);

    expect($organization->refresh()->credit_balance)->toBe('0.7000000000');
    expect(CreditTransaction::query()->where('generation_id', $generation->id)->count())->toBe(3);
});

test('charges more than was held, going negative, if the actual cost exceeds the estimate', function (): void {
    $organization = Organization::factory()->create();
    fundOrganization($organization, '0.4');
    $generation = heldGeneration($organization, '0.4');

    resolve(CreditLedger::class)->settle($generation, BigDecimal::of('0.45'));

    expect($organization->refresh()->credit_balance)->toBe('-0.0500000000');
    expectBalanceMatchesLedger($organization);
});

test('charges a generation from before credits existed, which has no hold', function (): void {
    $organization = Organization::factory()->create();
    fundOrganization($organization, '1');
    $generation = Generation::factory()->queued()->for($organization)->create();

    resolve(CreditLedger::class)->settle($generation, BigDecimal::of('0.25'));

    expect($organization->refresh()->credit_balance)->toBe('0.7500000000');
    expect(CreditTransaction::query()->where('generation_id', $generation->id)->sole()->type)->toBe(CreditTransactionType::Charge);
});

test('refuses to hold the same generation twice', function (): void {
    $organization = Organization::factory()->create();
    fundOrganization($organization, '1');
    $generation = heldGeneration($organization, '0.4');
    $ledger = resolve(CreditLedger::class);

    expect(fn () => $ledger->hold($ledger->lockOrganization($organization->id), $generation, BigDecimal::of('0.4')))
        ->toThrow(CreditLedgerConflictException::class);
    expect($organization->refresh()->credit_balance)->toBe('0.6000000000');
});

test('reports what is set aside by unfinished generations', function (): void {
    $organization = Organization::factory()->create();
    fundOrganization($organization, '1');
    heldGeneration($organization, '0.1');
    $finished = heldGeneration($organization, '0.2');
    resolve(CreditLedger::class)->settle($finished, null);

    expect((string) resolve(CreditLedger::class)->reserved($organization))->toBe('0.1000000000');
});
