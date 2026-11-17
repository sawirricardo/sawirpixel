<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\InviteMember;
use App\Http\Requests\StoreInvitationRequest;
use App\Models\Organization;
use App\Models\OrgInvitation;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

final class InvitationController
{
    public function store(StoreInvitationRequest $request, #[CurrentUser] User $user, InviteMember $inviteMember): RedirectResponse
    {
        $organization = $user->currentOrganization;
        abort_unless($organization instanceof Organization, 404);
        Gate::authorize('inviteMembers', $organization);

        $invitation = $inviteMember->handle($user, $organization, $request->string('email')->value(), $request->roles(), $request->permissions());

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
