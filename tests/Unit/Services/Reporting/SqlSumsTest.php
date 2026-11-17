<?php

declare(strict_types=1);

use App\Models\Generation;
use App\Models\Organization;
use App\Services\Billing\CreditLedger;
use App\Services\Reporting\ProfitReport;
use App\Services\Reporting\UserSpend;
use Carbon\CarbonImmutable;

test('sums money exactly, where floats would drift', function (): void {
    $user = organizationOwner();
    foreach (['0.1000000000', '0.2000000000'] as $charge) {
        Generation::factory()->succeeded()->for($user)->for($user->currentOrganization)->create(['charge' => $charge, 'provider_cost' => '0.080000000', 'completed_at' => '2026-11-17 10:00:00']);
    }

    $report = resolve(ProfitReport::class)->build(CarbonImmutable::parse('2026-11-17'), CarbonImmutable::parse('2026-11-17'), null);

    // As floats, 0.1 + 0.2 is 0.30000000000000004.
    expect($report['totals']['charged']['amount'])->toBe('0.3000000000');
    expect($report['totals']['provider_cost']['amount'])->toBe('0.160000000');
    expect((string) resolve(UserSpend::class)->forUsers([$user->id])[$user->id]->charged)->toBe('0.3000000000');
});

test('puts each generation in the UTC day it completed, right up to midnight', function (): void {
    $user = organizationOwner();
    Generation::factory()->succeeded()->for($user)->for($user->currentOrganization)->create(['charge' => '1.0000000000', 'completed_at' => '2026-11-16 23:59:59']);
    Generation::factory()->succeeded()->for($user)->for($user->currentOrganization)->create(['charge' => '2.0000000000', 'completed_at' => '2026-11-17 00:00:00']);

    $days = collect(resolve(ProfitReport::class)->build(CarbonImmutable::parse('2026-11-15'), CarbonImmutable::parse('2026-11-17'), null)['days'])->keyBy('date');

    expect($days['2026-11-16']['charged']['amount'])->toBe('1.0000000000');
    expect($days['2026-11-17']['charged']['amount'])->toBe('2.0000000000');
    expect($days['2026-11-15']['generations'])->toBe(0);
    expect($days['2026-11-15']['charged']['amount'])->toBe('0');
});

test('reports zero, not nothing, when there is nothing to sum', function (): void {
    $organization = Organization::factory()->create();
    $user = organizationOwner();

    expect((string) resolve(CreditLedger::class)->reserved($organization))->toBe('0');
    expect(resolve(UserSpend::class)->forUsers([$user->id])[$user->id]->generations)->toBe(0);
    expect(resolve(ProfitReport::class)->build(CarbonImmutable::parse('2026-11-17'), CarbonImmutable::parse('2026-11-17'), null)['totals']['charged']['amount'])->toBe('0');
});
