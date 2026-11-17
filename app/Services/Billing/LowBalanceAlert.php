<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\Permission;
use App\Models\Organization;
use App\Models\OrgUser;
use App\Models\User;
use App\Notifications\LowCredits;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * Tells an organization's credit buyers when a charge takes its balance below the threshold. Only the
 * charge that crosses the line alerts, and not while an earlier alert is still unread, so a run of
 * generations near the threshold doesn't flood anyone.
 */
final readonly class LowBalanceAlert
{
    public function afterCharge(Organization $organization, BigDecimal $before, BigDecimal $after): void
    {
        $threshold = BigDecimal::of(is_string($value = config('credits.low_balance')) || is_numeric($value) ? (string) $value : '1');

        if ($before->isLessThan($threshold) || $after->isGreaterThanOrEqualTo($threshold)) {
            return;
        }

        $organizationId = $organization->id;

        // Sent once the charge is committed: a rolled-back charge never happened.
        DB::afterCommit(function () use ($organizationId): void {
            $organization = Organization::query()->find($organizationId);

            if (! $organization instanceof Organization) {
                return;
            }

            foreach ($this->buyers($organization) as $buyer) {
                $alreadyWarned = $buyer->unreadNotifications()
                    ->where('data->kind', LowCredits::KIND)
                    ->where('data->organization_id', $organizationId)
                    ->exists();

                if (! $alreadyWarned) {
                    $buyer->notify(new LowCredits($organization));
                }
            }
        });
    }

    /**
     * @return list<User>
     */
    private function buyers(Organization $organization): array
    {
        return array_values($organization->orgUsers()
            ->with(['user', 'roles.permissions', 'permissions'])
            ->get()
            ->filter(fn (OrgUser $membership): bool => $membership->hasPermission(Permission::BuyCredits))
            ->map(fn (OrgUser $membership): ?User => $membership->user)
            ->filter()
            ->all());
    }
}
