<?php

declare(strict_types=1);

namespace App\Services\Generations;

use App\Enums\GenerationStatus;
use App\Enums\Permission;
use App\Enums\Pixelforge\ImageQuality;
use App\Enums\Pixelforge\ImageSize;
use App\Models\Generation;
use App\Models\Organization;
use App\Models\OrgUser;
use App\Models\User;
use App\Services\Pricing\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * An organization's limits on generating: what it may spend and how many images it may make per UTC day
 * and month, the same for each member, and which qualities and sizes members may use.
 *
 * Usage is worked out from the generations themselves: a finished image counts what it was charged, one
 * in progress counts the most it can cost (what's held for it), and a failed one counts nothing. So a late
 * failure frees its share by itself. Members who can change the organization's settings are exempt from
 * the member limits, since they could lift them anyway; the organization's own limits apply to everyone.
 *
 * Called without a lock to explain a block before submitting, and with the organization's row locked to
 * enforce it, so concurrent submissions can't both squeeze under a limit.
 */
final readonly class GenerationLimiter
{
    /**
     * Why this generation can't be made right now, or null if it can.
     */
    public function blockedReason(User $user, Organization $organization, ImageSize $size, ImageQuality $quality, BigDecimal $chargeMax): ?string
    {
        $isMemberLimited = ! $this->isExempt($user, $organization);

        if ($isMemberLimited && ! in_array($quality->value, $this->allowedQualities($user, $organization), true)) {
            return sprintf('%s quality is turned off for members of %s. Ask an owner or admin if you need it.', ucfirst($quality->value), $organization->name);
        }

        if ($isMemberLimited && ! in_array($size->value, $this->allowedSizes($user, $organization), true)) {
            return sprintf('The %s size is turned off for members of %s. Ask an owner or admin if you need it.', mb_strtolower($size->name), $organization->name);
        }

        $now = CarbonImmutable::now('UTC');
        $thisMonth = $this->countedGenerations($organization, $now->startOfMonth());
        $today = $thisMonth->filter(fn (Generation $generation): bool => $generation->created_at !== null && $generation->created_at->greaterThanOrEqualTo($now->startOfDay()));
        $fix = Gate::forUser($user)->allows('update', $organization) ? 'Change it in organization settings.' : 'Ask an owner or admin to raise it.';

        $checks = [
            [$organization->spend_limit_monthly, $organization->generation_limit_monthly, $thisMonth, 'month', false],
            [$organization->spend_limit_daily, $organization->generation_limit_daily, $today, 'day', false],
        ];

        if ($isMemberLimited) {
            $mine = fn (Collection $generations): Collection => $generations->where('user_id', $user->id);
            $checks[] = [$organization->member_spend_limit_monthly, $organization->member_generation_limit_monthly, $mine($thisMonth), 'month', true];
            $checks[] = [$organization->member_spend_limit_daily, $organization->member_generation_limit_daily, $mine($today), 'day', true];
        }

        foreach ($checks as [$spendLimit, $countLimit, $generations, $period, $perMember]) {
            $who = $perMember ? sprintf('You can %%s in %s', $organization->name) : $organization->name.' can %s';
            $resets = $period === 'day' ? 'at midnight UTC' : 'on '.$now->startOfMonth()->addMonth()->format('j F');

            if ($countLimit !== null && $generations->count() + 1 > $countLimit) {
                return sprintf(
                    '%s, and %s. The limit resets %s. %s',
                    sprintf($who, sprintf('generate up to %d %s a %s', $countLimit, $countLimit === 1 ? 'image' : 'images', $period)),
                    $perMember ? "you've used them all" : 'all of them have been used',
                    $resets,
                    $fix,
                );
            }

            if ($spendLimit !== null) {
                $spent = $this->spent($generations);

                if ($spent->plus($chargeMax)->isGreaterThan(BigDecimal::of($spendLimit))) {
                    return sprintf(
                        '%s. %s is used, and this image could cost up to %s. The limit resets %s. %s',
                        sprintf($who, sprintf('spend up to %s a %s on images', Money::format($spendLimit, RoundingMode::Down), $period)),
                        Money::format($spent, RoundingMode::Up),
                        Money::format($chargeMax, RoundingMode::Up),
                        $resets,
                        $fix,
                    );
                }
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function allowedQualities(User $user, Organization $organization): array
    {
        $all = array_map(fn (ImageQuality $quality): string => $quality->value, ImageQuality::cases());

        return $organization->member_qualities === null || $this->isExempt($user, $organization)
            ? $all
            : array_values(array_intersect($all, $organization->member_qualities));
    }

    /**
     * @return list<string>
     */
    public function allowedSizes(User $user, Organization $organization): array
    {
        $all = array_map(fn (ImageSize $size): string => $size->value, ImageSize::cases());

        return $organization->member_sizes === null || $this->isExempt($user, $organization)
            ? $all
            : array_values(array_intersect($all, $organization->member_sizes));
    }

    /**
     * What the organization has used today and this month, for its settings page.
     *
     * @return array{day: array{spent: BigDecimal, generations: int}, month: array{spent: BigDecimal, generations: int}}
     */
    public function usage(Organization $organization): array
    {
        $now = CarbonImmutable::now('UTC');
        $thisMonth = $this->countedGenerations($organization, $now->startOfMonth());
        $today = $thisMonth->filter(fn (Generation $generation): bool => $generation->created_at !== null && $generation->created_at->greaterThanOrEqualTo($now->startOfDay()));

        return [
            'day' => ['spent' => $this->spent($today), 'generations' => $today->count()],
            'month' => ['spent' => $this->spent($thisMonth), 'generations' => $thisMonth->count()],
        ];
    }

    public function isExempt(User $user, Organization $organization): bool
    {
        $membership = $user->membershipIn($organization);

        return $membership instanceof OrgUser && $membership->hasPermission(Permission::UpdateOrganization);
    }

    /**
     * Generations since the start of the period that count against limits: everything but failures.
     *
     * @return Collection<int, Generation>
     */
    private function countedGenerations(Organization $organization, CarbonImmutable $since): Collection
    {
        return Generation::query()
            ->where('organization_id', $organization->id)
            ->where('created_at', '>=', $since)
            ->where('status', '!=', GenerationStatus::Failed)
            ->get(['id', 'user_id', 'status', 'charge', 'estimated_charge_max', 'created_at']);
    }

    /**
     * @param  Collection<int, Generation>  $generations
     */
    private function spent(Collection $generations): BigDecimal
    {
        return $generations->reduce(
            fn (BigDecimal $sum, Generation $generation): BigDecimal => $sum->plus(
                $generation->status === GenerationStatus::Succeeded ? ($generation->charge ?? '0') : $generation->estimated_charge_max,
            ),
            BigDecimal::zero(),
        );
    }
}
