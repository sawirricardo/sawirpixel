<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\DeleteOrganization;
use App\Actions\GrantableAccess;
use App\Enums\Permission as PermissionEnum;
use App\Http\Requests\DeleteOrganizationRequest;
use App\Http\Requests\UpdateOrganizationRequest;
use App\Models\Organization;
use App\Models\OrgInvitation;
use App\Models\OrgUser;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Pricing\Money;
use Brick\Math\BigDecimal;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The current organization's settings: its name, and its members and invitations.
 */
final class OrganizationSettingsController
{
    public function edit(#[CurrentUser] User $user, GrantableAccess $grantableAccess): Response|RedirectResponse
    {
        $organization = $user->currentOrganization;

        if (! $organization instanceof Organization) {
            return to_route('organizations.create');
        }

        Gate::authorize('viewMembers', $organization);

        $canInvite = Gate::allows('inviteMembers', $organization);
        $canManageRoles = Gate::allows('manageRoles', $organization);

        return Inertia::render('organizations/Settings', [
            'organization' => ['id' => $organization->id, 'name' => $organization->name],
            'canUpdate' => Gate::allows('update', $organization),
            'canDelete' => Gate::allows('delete', $organization),
            'creditBalance' => Money::toArray($organization->credit_balance),
            'hasCredits' => BigDecimal::of($organization->credit_balance)->isPositive(),
            'members' => $organization->orgUsers()
                ->with(['user:id,name,email', 'roles:id,name', 'permissions'])
                ->oldest('id')
                ->get()
                ->map(fn (OrgUser $member): array => [
                    'id' => $member->id,
                    'name' => $member->user->name ?? '',
                    'email' => $member->user->email ?? '',
                    'role_ids' => $member->roles->pluck('id')->all(),
                    'roles' => $member->roles->pluck('name')->all(),
                    'permissions' => $member->permissions->map(fn (Permission $permission): string => $permission->name->label())->all(),
                    'is_you' => $member->user_id === $user->id,
                ]),
            'invitations' => $canInvite ? $organization->pendingInvitations()
                ->with(['roles:id,name', 'permissions'])
                ->latest('id')
                ->get()
                ->map(fn (OrgInvitation $invitation): array => [
                    'id' => $invitation->id,
                    'email' => $invitation->email,
                    'roles' => $invitation->roles->pluck('name')->all(),
                    'permissions' => $invitation->permissions->map(fn (Permission $permission): string => $permission->name->label())->all(),
                    'expires_at' => $invitation->expires_at->toIso8601String(),
                ]) : [],
            'roles' => $organization->roles()->oldest('id')->get(['id', 'name'])->map(fn (Role $role): array => $role->only(['id', 'name'])),
            'grantable' => $canInvite || $canManageRoles ? [
                'roles' => array_map(fn (Role $role): array => $role->only(['id', 'name']), $grantableAccess->roles($user, $organization)),
                'permissions' => array_map(fn (PermissionEnum $permission): array => ['value' => $permission->value, 'label' => $permission->label()], $grantableAccess->permissions($user, $organization)),
            ] : ['roles' => [], 'permissions' => []],
            'can' => [
                'invite' => $canInvite,
                'remove' => Gate::allows('removeMembers', $organization),
                'manageRoles' => $canManageRoles,
            ],
        ]);
    }

    public function update(UpdateOrganizationRequest $request, #[CurrentUser] User $user): RedirectResponse
    {
        $organization = $user->currentOrganization;
        abort_unless($organization instanceof Organization, 404);
        Gate::authorize('update', $organization);

        $organization->update(['name' => $request->string('name')->value()]);

        Inertia::flash('success', 'Organization updated.');

        return back();
    }

    public function destroy(DeleteOrganizationRequest $request, #[CurrentUser] User $user, DeleteOrganization $deleteOrganization): RedirectResponse
    {
        $organization = $user->currentOrganization;
        abort_unless($organization instanceof Organization, 404);
        Gate::authorize('delete', $organization);

        $deleteOrganization->handle($organization);

        Inertia::flash('success', sprintf('%s was deleted.', $organization->name));

        return to_route('home');
    }
}
