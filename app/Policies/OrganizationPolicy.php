<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Organization;
use App\Models\OrgUser;
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

    /**
     * Any member can see the organization's credit history: it's the shared balance they spend from.
     */
    public function viewCredits(User $user, Organization $organization): bool
    {
        return $user->membershipIn($organization) instanceof OrgUser;
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
