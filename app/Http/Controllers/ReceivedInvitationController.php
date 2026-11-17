<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\AcceptInvitation;
use App\Models\OrgInvitation;
use App\Models\User;
use App\Services\Invitations\InvitationPage;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * An invitation opened from an in-app notification. Without the emailed token, it's found by id and
 * only for the account it was sent to, so nobody can look up someone else's invitation.
 */
final class ReceivedInvitationController
{
    public function show(string $invitation, #[CurrentUser] User $user): Response
    {
        $invitation = $this->find($invitation, $user);

        return Inertia::render('invitations/Show', InvitationPage::props($invitation, $user, route('invitations.received.accept', $invitation, absolute: false)));
    }

    public function store(string $invitation, #[CurrentUser] User $user, AcceptInvitation $acceptInvitation): RedirectResponse
    {
        $organization = $acceptInvitation->handle($user, $this->find($invitation, $user));

        Inertia::flash('success', sprintf('Welcome to %s!', $organization->name));

        return to_route('home');
    }

    private function find(string $id, User $user): OrgInvitation
    {
        return OrgInvitation::query()
            ->with(['organization:id,name', 'roles:id,name', 'permissions', 'invitedBy:id,name'])
            ->where('email', mb_strtolower($user->email))
            ->findOrFail($id);
    }
}
