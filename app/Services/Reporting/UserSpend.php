<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use Brick\Math\BigDecimal;

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

        Generation::query()
            ->whereIn('user_id', $userIds)
            ->where('status', GenerationStatus::Succeeded)
            ->select(['id', 'user_id', 'charge', 'provider_cost'])
            ->lazyById()
            ->each(function (Generation $generation) use (&$tallies): void {
                $tallies[$generation->user_id]->add(
                    BigDecimal::of($generation->charge ?? '0'),
                    BigDecimal::of($generation->provider_cost ?? '0'),
                );
            });

        return $tallies;
    }
}
