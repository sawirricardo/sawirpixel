<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\Permission as PermissionEnum;
use App\Models\Organization;
use App\Models\OrgInvitation;
use App\Models\OrgUser;
use App\Models\Permission;
use App\Models\User;
use App\Notifications\InvitedToOrganization;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Adds the invited user to the organization with the invited roles and permissions, and switches them to it.
 * Someone who is already a member keeps their current access.
 */
final readonly class AcceptInvitation
{
    /**
     * @throws ValidationException when the invitation is no longer valid or was sent to another address
     */
    public function handle(User $user, OrgInvitation $invitation): Organization
    {
        return DB::transaction(function () use ($user, $invitation): Organization {
            $invitation = OrgInvitation::query()->whereKey($invitation->id)->lockForUpdate()->sole();
            $organization = $invitation->organization()->sole();

            if (! $invitation->isPending()) {
                throw ValidationException::withMessages(['invitation' => 'This invitation has expired or was already used.']);
            }

            if (mb_strtolower($user->email) !== mb_strtolower($invitation->email)) {
                throw ValidationException::withMessages(['invitation' => sprintf('This invitation was sent to %s. Log in with that email to accept it.', $invitation->email)]);
            }

            if (! $user->membershipIn($organization) instanceof OrgUser) {
                $membership = $organization->orgUsers()->create(['user_id' => $user->id]);
                $membership->assignRole(...$invitation->roles()->get()->all());
                $membership->givePermissionTo(...$invitation->permissions()->get()->map(fn (Permission $permission): PermissionEnum => $permission->name)->all());
            }

            $invitation->update(['accepted_at' => now()]);
            $user->unreadNotifications()
                ->where('data->kind', InvitedToOrganization::KIND)
                ->where('data->invitation_id', $invitation->id)
                ->update(['read_at' => now()]);
            $user->currentOrganization()->associate($organization)->save();

            return $organization;
        });
    }
}
