<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\DefaultRole;
use App\Enums\Permission;
use App\Models\Organization;
use App\Models\OrgUser;
use App\Models\Permission as PermissionModel;
use App\Models\Role;
use App\Models\User;

/**
 * What a member may hand out: only permissions they hold themselves, and the Owner role only if they
 * are an Owner. This keeps invitations from being used to escalate privileges.
 */
final readonly class GrantableAccess
{
    /**
     * @return list<Permission>
     */
    public function permissions(User $user, Organization $organization): array
    {
        $membership = $user->membershipIn($organization);

        return $membership instanceof OrgUser
            ? array_values(array_filter(Permission::cases(), $membership->hasPermission(...)))
            : [];
    }

    /**
     * Roles whose every permission the user holds (and Owner only for Owners).
     *
     * @return list<Role>
     */
    public function roles(User $user, Organization $organization): array
    {
        $grantable = $this->permissions($user, $organization);
        $isOwner = $user->membershipIn($organization)?->hasRole(DefaultRole::Owner->value) ?? false;

        return array_values($organization->roles()
            ->with('permissions')
            ->oldest('id')
            ->get()
            ->filter(fn (Role $role): bool => ($role->name !== DefaultRole::Owner->value || $isOwner)
                && $role->permissions->every(fn (PermissionModel $permission): bool => in_array($permission->name, $grantable, true)))
            ->all());
    }
}
