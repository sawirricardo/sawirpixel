<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\Organization;
use App\Models\OrgInvitation;
use App\Models\OrgUser;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Removes an organization for everyone. Members and pending invitations go, and anyone working in it
 * moves to another organization they belong to. The organization itself is soft deleted so its
 * generations and credit ledger survive as billing records.
 */
final readonly class DeleteOrganization
{
    /**
     * @throws ValidationException while any of its images are still in progress
     */
    public function handle(Organization $organization): void
    {
        DB::transaction(function () use ($organization): void {
            $organization = Organization::query()->whereKey($organization->id)->lockForUpdate()->sole();

            $inProgress = Generation::query()
                ->whereBelongsTo($organization)
                ->whereIn('status', GenerationStatus::unfinished())
                ->count();

            if ($inProgress > 0) {
                throw ValidationException::withMessages(['confirm_name' => sprintf(
                    'Wait for the %d image%s still in progress to finish before deleting %s.',
                    $inProgress,
                    $inProgress === 1 ? '' : 's',
                    $organization->name,
                )]);
            }

            $memberIds = $organization->orgUsers()->pluck('user_id')->all();

            $organization->orgUsers()->get()->each(function (OrgUser $membership): void {
                $membership->roles()->detach();
                $membership->permissions()->detach();
                $membership->delete();
            });

            $organization->invitations()->get()->each(fn (OrgInvitation $invitation): ?bool => $invitation->delete());

            $organization->delete();

            User::query()
                ->whereIn('id', $memberIds)
                ->where('current_organization_id', $organization->id)
                ->get()
                ->each(fn (User $user): bool => $user->currentOrganization()
                    ->associate($user->orgUsers()->oldest('id')->first()?->organization_id)
                    ->save());
        });
    }
}
