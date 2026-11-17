<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Organization;
use App\Models\User;

/**
 * Member and invitation management, decided by the permissions the user's membership holds.
 */
final class OrganizationPolicy
{
    public function viewMembers(User $user, Organization $organization): bool
    {
        return $this->allows($user, $organization, Permission::ViewMembers);
    }

    public function inviteMembers(User $user, Organization $organization): bool
    {
        return $this->allows($user, $organization, Permission::InviteMembers);
    }

    public function removeMembers(User $user, Organization $organization): bool
    {
        return $this->allows($user, $organization, Permission::RemoveMembers);
    }

    public function manageRoles(User $user, Organization $organization): bool
    {
        return $this->allows($user, $organization, Permission::ManageRoles);
    }

    public function buyCredits(User $user, Organization $organization): bool
    {
        return $this->allows($user, $organization, Permission::BuyCredits);
    }

    private function allows(User $user, Organization $organization, Permission $permission): bool
    {
        return $user->membershipIn($organization)?->hasPermission($permission) ?? false;
    }
}
