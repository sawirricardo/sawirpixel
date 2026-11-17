<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\AcceptInvitation;
use App\Models\OrgInvitation;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The page behind an emailed invitation link. Guests are asked to log in or register and come back;
 * a logged-in user with the invited email can accept.
 */
final class InvitationAcceptanceController
{
    public function show(string $token, #[CurrentUser] ?User $user): Response
    {
        $invitation = $this->find($token);

        if (! $user instanceof User) {
            redirect()->setIntendedUrl(route('invitations.show', $token));
        }

        return Inertia::render('invitations/Show', [
            'token' => $token,
            'invitation' => $invitation instanceof OrgInvitation ? [
                'email' => $invitation->email,
                'organization' => $invitation->organization->name ?? '',
                'roles' => $invitation->roles->pluck('name')->all(),
                'permissions' => $invitation->permissions->map(fn (Permission $permission): string => $permission->name->label())->all(),
                'inviter' => $invitation->invitedBy?->name,
                'is_pending' => $invitation->isPending(),
            ] : null,
            'emailMatches' => $user instanceof User && $invitation instanceof OrgInvitation
                && mb_strtolower($user->email) === mb_strtolower($invitation->email),
        ]);
    }

    public function store(string $token, #[CurrentUser] User $user, AcceptInvitation $acceptInvitation): RedirectResponse
    {
        $invitation = $this->find($token);
        abort_unless($invitation instanceof OrgInvitation, 404);

        $organization = $acceptInvitation->handle($user, $invitation);

        Inertia::flash('success', sprintf('Welcome to %s!', $organization->name));

        return to_route('home');
    }

    private function find(string $token): ?OrgInvitation
    {
        return OrgInvitation::query()
            ->with(['organization:id,name', 'roles:id,name', 'permissions', 'invitedBy:id,name'])
            ->where('token_hash', OrgInvitation::hashToken($token))
            ->first();
    }
}
