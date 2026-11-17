<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\GrantableAccess;
use App\Actions\ManageMembership;
use App\Enums\Permission as PermissionEnum;
use App\Http\Requests\UpdateMemberRequest;
use App\Models\Organization;
use App\Models\OrgInvitation;
use App\Models\OrgUser;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class MemberController
{
    public function index(#[CurrentUser] User $user, GrantableAccess $grantableAccess): Response|RedirectResponse
    {
        $organization = $user->currentOrganization;

        if (! $organization instanceof Organization) {
            return to_route('organizations.create');
        }

        Gate::authorize('viewMembers', $organization);

        $canInvite = Gate::allows('inviteMembers', $organization);

        return Inertia::render('organizations/Members', [
            'organization' => ['id' => $organization->id, 'name' => $organization->name],
            'members' => $organization->orgUsers()
                ->with(['user:id,name,email', 'roles:id,name', 'permissions'])
                ->oldest('id')
                ->get()
                ->map(fn (OrgUser $member): array => [
                    'id' => $member->id,
                    'name' => $member->user->name ?? '',
                    'email' => $member->user->email ?? '',
                    'role' => $member->roles->first()?->only(['id', 'name']),
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
            'grantable' => $canInvite ? [
                'roles' => array_map(fn (Role $role): array => $role->only(['id', 'name']), $grantableAccess->roles($user, $organization)),
                'permissions' => array_map(fn (PermissionEnum $permission): array => ['value' => $permission->value, 'label' => $permission->label()], $grantableAccess->permissions($user, $organization)),
            ] : ['roles' => [], 'permissions' => []],
            'can' => [
                'invite' => $canInvite,
                'remove' => Gate::allows('removeMembers', $organization),
                'manageRoles' => Gate::allows('manageRoles', $organization),
            ],
        ]);
    }

    public function update(UpdateMemberRequest $request, #[CurrentUser] User $user, OrgUser $member, ManageMembership $membership): RedirectResponse
    {
        $this->ensureInCurrentOrganization($user, $member);
        Gate::authorize('manageRoles', $member->organization);

        $membership->changeRole($user, $member, $request->role());

        return back();
    }

    public function destroy(#[CurrentUser] User $user, OrgUser $member, ManageMembership $membership): RedirectResponse
    {
        $this->ensureInCurrentOrganization($user, $member);
        Gate::authorize('removeMembers', $member->organization);

        $membership->remove($user, $member);

        return back();
    }

    /**
     * Members of other organizations are reported as missing rather than forbidden.
     */
    private function ensureInCurrentOrganization(User $user, OrgUser $member): void
    {
        abort_unless($member->organization_id === $user->current_organization_id, 404);
    }
}
