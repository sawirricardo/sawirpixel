<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\DefaultRole;
use App\Models\OrgUser;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Changes a member's role or removes them, keeping two rules: only Owners can grant or take away the
 * Owner role, and an organization always keeps at least one Owner.
 */
final readonly class ManageMembership
{
    /**
     * @throws ValidationException
     */
    public function changeRole(User $actor, OrgUser $member, Role $role): void
    {
        DB::transaction(function () use ($actor, $member, $role): void {
            $organization = $member->organization()->lockForUpdate()->sole();
            $isOwner = $member->hasRole(DefaultRole::Owner->value);
            $becomesOwner = $role->name === DefaultRole::Owner->value;

            if (($isOwner || $becomesOwner) && ! $this->actorIsOwner($actor, $member)) {
                throw ValidationException::withMessages(['role_id' => 'Only owners can grant or remove the Owner role.']);
            }

            if ($isOwner && ! $becomesOwner && $organization->ownerCount() <= 1) {
                throw ValidationException::withMessages(['role_id' => 'An organization needs at least one owner.']);
            }

            $member->syncRoles($role);
        });
    }

    /**
     * @throws ValidationException
     */
    public function remove(User $actor, OrgUser $member): void
    {
        DB::transaction(function () use ($actor, $member): void {
            $organization = $member->organization()->lockForUpdate()->sole();

            if ($member->user_id === $actor->id) {
                throw ValidationException::withMessages(['member' => "You can't remove yourself."]);
            }

            // Only an owner can remove an owner, and they can't remove themselves, so at least one owner always remains.
            if ($member->hasRole(DefaultRole::Owner->value) && ! $this->actorIsOwner($actor, $member)) {
                throw ValidationException::withMessages(['member' => 'Only owners can remove an owner.']);
            }

            $user = $member->user()->sole();
            $member->roles()->detach();
            $member->permissions()->detach();
            $member->delete();

            if ($user->current_organization_id === $organization->id) {
                $user->currentOrganization()->associate($user->orgUsers()->oldest('id')->first()?->organization_id)->save();
            }
        });
    }

    private function actorIsOwner(User $actor, OrgUser $member): bool
    {
        return $actor->orgUsers()
            ->where('organization_id', $member->organization_id)
            ->whereHas('roles', fn (Builder $query): Builder => $query->where('name', DefaultRole::Owner->value))
            ->exists();
    }
}
