<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
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

        // Totals are summed by Postgres over numeric columns, which is exact. Days are UTC dates of completed_at.
        $billed = $this->billedGenerations($startDate, $endDate)->reorder()->toBase();
        $sums = 'count(*) as generations, sum(charge) as charged, sum(provider_cost) as provider_cost';
        $day = "to_char(completed_at, 'YYYY-MM-DD')";

        foreach ((clone $billed)->selectRaw($day.' as day, '.$sums)->groupByRaw($day)->get() as $row) {
            $days[is_string($row->day) ? $row->day : ''] = ProfitTally::fromSums($row->generations, $row->charged, $row->provider_cost);
        }

        $total = (clone $billed)->selectRaw($sums)->first();
        $totals = ProfitTally::fromSums($total?->generations, $total?->charged, $total?->provider_cost);

        $perUser = (clone $billed)->selectRaw('user_id, '.$sums)->groupBy('user_id')->orderByDesc('charged')->orderBy('user_id')->get();
        $people = User::query()->whereIn('id', $perUser->pluck('user_id')->all())->get(['id', 'name', 'email'])->keyBy('id');

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

        $userRows = [];

        foreach ($perUser as $row) {
            $userId = is_numeric($row->user_id) ? (int) $row->user_id : 0;
            $person = $people->get($userId);
            $userRows[] = [
                'user_id' => $userId,
                'name' => $person->name ?? '',
                'email' => $person->email ?? '',
                ...ProfitTally::fromSums($row->generations, $row->charged, $row->provider_cost)->toArray(),
            ];
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
