<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\OrgUser;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

/**
 * Switches the organization the user is working in.
 */
final class CurrentOrganizationController
{
    public function update(#[CurrentUser] User $user, Organization $organization): RedirectResponse
    {
        abort_unless($user->membershipIn($organization) instanceof OrgUser, 404);

        $user->currentOrganization()->associate($organization)->save();

        return to_route('home');
    }
}
