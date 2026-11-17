<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Services\Pixelforge\Data\UsageReport;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * What users were charged, what Pixelforge charged us, and the difference, per UTC day and per user.
 *
 * Only succeeded generations are billed (failed ones cost nothing on either side), and each is counted
 * on the UTC day it completed, which is how Pixelforge groups GET /v1/usage. Amounts are exact and
 * summed with brick/math, so our provider cost per day should equal Pixelforge's to the last digit.
 */
final readonly class ProfitReport
{
    /**
     * @return array{
     *     totals: array<string, mixed>,
     *     days: list<array<string, mixed>>,
     *     users: list<array<string, mixed>>,
     *     reconciliation: array{available: bool, matched_days: int, mismatched_days: int}
     * }
     */
    public function build(CarbonImmutable $startDate, CarbonImmutable $endDate, ?UsageReport $pixelforge): array
    {
        /** @var array<string, ProfitTally> $days */
        $days = [];

        for ($day = $startDate->utc()->startOfDay(); $day->lte($endDate); $day = $day->addDay()) {
            $days[$day->toDateString()] = new ProfitTally;
        }

        /** @var array<int, array{name: string, email: string, tally: ProfitTally}> $users */
        $users = [];
        $totals = new ProfitTally;

        foreach ($this->billedGenerations($startDate, $endDate)->with('user:id,name,email')->get() as $generation) {
            // The query only returns generations that completed inside the range, so every date has a bucket.
            $date = (string) $generation->completed_at?->utc()->toDateString();
            $days[$date] ??= new ProfitTally;

            $charged = BigDecimal::of($generation->charge ?? '0');
            $providerCost = BigDecimal::of($generation->provider_cost ?? '0');

            $users[$generation->user_id] ??= [
                'name' => $generation->user->name ?? '',
                'email' => $generation->user->email ?? '',
                'tally' => new ProfitTally,
            ];

            $days[$date]->add($charged, $providerCost);
            $users[$generation->user_id]['tally']->add($charged, $providerCost);
            $totals->add($charged, $providerCost);
        }

        $dayRows = [];
        $matched = 0;
        $mismatched = 0;

        foreach ($days as $date => $tally) {
            $reported = $pixelforge?->days[$date] ?? null;
            $comparison = null;

            if ($reported !== null) {
                $difference = BigDecimal::of($reported->costUsd)->minus($tally->providerCost);
                $matches = $difference->isZero() && $reported->generations === $tally->generations;
                $matches ? $matched++ : $mismatched++;

                $comparison = [
                    'generations' => $reported->generations,
                    'cost' => ProviderCost::toArray($reported->costUsd),
                    'difference' => ProviderCost::toArray($difference),
                    'matches' => $matches,
                ];
            }

            $dayRows[] = ['date' => $date, ...$tally->toArray(), 'pixelforge' => $comparison];
        }

        uasort($users, fn (array $a, array $b): int => $b['tally']->charged->compareTo($a['tally']->charged));

        $userRows = [];

        foreach ($users as $userId => $user) {
            $userRows[] = ['user_id' => $userId, 'name' => $user['name'], 'email' => $user['email'], ...$user['tally']->toArray()];
        }

        return [
            'totals' => [
                ...$totals->toArray(),
                'pixelforge_cost' => $pixelforge instanceof UsageReport ? ProviderCost::toArray($pixelforge->total->costUsd) : null,
            ],
            'days' => array_reverse($dayRows),
            'users' => $userRows,
            'reconciliation' => [
                'available' => $pixelforge instanceof UsageReport,
                'matched_days' => $matched,
                'mismatched_days' => $mismatched,
            ],
        ];
    }

    /**
     * Succeeded generations that completed within the UTC date range, oldest first.
     *
     * @return Builder<Generation>
     */
    public function billedGenerations(CarbonImmutable $startDate, CarbonImmutable $endDate): Builder
    {
        return Generation::query()
            ->where('status', GenerationStatus::Succeeded)
            ->where('completed_at', '>=', $startDate->utc()->startOfDay())
            ->where('completed_at', '<=', $endDate->utc()->endOfDay())
            ->oldest('completed_at')
            ->orderBy('id');
    }
}
