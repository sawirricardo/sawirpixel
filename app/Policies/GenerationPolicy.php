<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Organization;
use App\Models\User;

final class GenerationPolicy
{
    /**
     * Determine whether the user can create generations in the organization.
     */
    public function create(User $user, Organization $organization): bool
    {
        return $user->membershipIn($organization)?->hasPermission(Permission::CreateGenerations) ?? false;
    }
}
