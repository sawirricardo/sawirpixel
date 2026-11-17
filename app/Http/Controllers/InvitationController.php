<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\InviteMember;
use App\Enums\DefaultRole;
use App\Http\Requests\StoreInvitationRequest;
use App\Models\Organization;
use App\Models\OrgInvitation;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

final class InvitationController
{
    public function store(StoreInvitationRequest $request, #[CurrentUser] User $user, InviteMember $inviteMember): RedirectResponse
    {
        $organization = $user->currentOrganization;
        abort_unless($organization instanceof Organization, 404);
        Gate::authorize('inviteMembers', $organization);

        $role = $request->role();

        if ($role->name === DefaultRole::Owner->value && ! $user->membershipIn($organization)?->hasRole(DefaultRole::Owner->value)) {
            throw ValidationException::withMessages(['role_id' => 'Only owners can invite someone as an owner.']);
        }

        $invitation = $inviteMember->handle($user, $organization, $request->string('email')->value(), $role);

        Inertia::flash('success', sprintf('Invitation sent to %s.', $invitation->email));

        return back();
    }

    public function update(#[CurrentUser] User $user, OrgInvitation $invitation, InviteMember $inviteMember): RedirectResponse
    {
        $this->authorizeInvitation($user, $invitation);

        $inviteMember->resend($invitation);

        Inertia::flash('success', sprintf('Invitation re-sent to %s.', $invitation->email));

        return back();
    }

    public function destroy(#[CurrentUser] User $user, OrgInvitation $invitation): RedirectResponse
    {
        $this->authorizeInvitation($user, $invitation);

        $invitation->delete();

        return back();
    }

    private function authorizeInvitation(User $user, OrgInvitation $invitation): void
    {
        abort_unless($invitation->organization_id === $user->current_organization_id && $invitation->isPending(), 404);
        Gate::authorize('inviteMembers', $invitation->organization);
    }
}
