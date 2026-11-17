<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use App\Enums\GenerationStatus;
use App\Models\Generation;

/**
 * Exact lifetime totals of what users were charged and what Pixelforge charged for them. Summed in PHP
 * with brick/math rather than SQL, whose SUM returns floats on SQLite.
 */
final readonly class UserSpend
{
    /**
     * @param  list<int>  $userIds
     * @return array<int, ProfitTally> keyed by user id; users without billed generations get an empty tally
     */
    public function forUsers(array $userIds): array
    {
        $tallies = [];

        foreach ($userIds as $userId) {
            $tallies[$userId] = new ProfitTally;
        }

        // Summed by Postgres over numeric columns, which is exact.
        $rows = Generation::query()
            ->whereIn('user_id', $userIds)
            ->where('status', GenerationStatus::Succeeded)
            ->toBase()
            ->selectRaw('user_id, count(*) as generations, sum(charge) as charged, sum(provider_cost) as provider_cost')
            ->groupBy('user_id')
            ->get();

        foreach ($rows as $row) {
            $userId = is_numeric($row->user_id) ? (int) $row->user_id : 0;
            $tallies[$userId] = ProfitTally::fromSums($row->generations, $row->charged, $row->provider_cost);
        }

        return $tallies;
    }
}
