<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\UpdateOrganizationLimitsRequest;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * The current organization's generation limits, set from its settings page.
 */
final class OrganizationLimitsController
{
    public function update(UpdateOrganizationLimitsRequest $request, #[CurrentUser] User $user): RedirectResponse
    {
        $organization = $user->currentOrganization;
        abort_unless($organization instanceof Organization, 404);
        Gate::authorize('update', $organization);

        $organization->update($request->limits());

        Inertia::flash('success', 'Limits saved.');

        return back();
    }
}
