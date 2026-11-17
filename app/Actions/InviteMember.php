<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\Permission;
use App\Mail\OrgInvitationMail;
use App\Models\Organization;
use App\Models\OrgInvitation;
use App\Models\Role;
use App\Models\User;
use App\Notifications\InvitedToOrganization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Invites an email address to an organization with roles and, optionally, extra permissions. A fresh invitation replaces any pending
 * one for the same address, so only the latest link works.
 */
final readonly class InviteMember
{
    /**
     * @param  list<Role>  $roles
     * @param  list<Permission>  $permissions  Granted directly, on top of the roles.
     */
    public function handle(User $inviter, Organization $organization, string $email, array $roles, array $permissions = []): OrgInvitation
    {
        $token = Str::random(40);
        $email = mb_strtolower($email);

        $invitation = DB::transaction(function () use ($inviter, $organization, $email, $roles, $permissions, $token): OrgInvitation {
            $organization->pendingInvitations()->where('email', $email)->get()->each->delete();

            $invitation = $organization->invitations()->create([
                'invited_by_id' => $inviter->id,
                'email' => $email,
                'token_hash' => OrgInvitation::hashToken($token),
                'expires_at' => now()->addDays(OrgInvitation::EXPIRES_AFTER_DAYS),
            ]);

            $invitation->assignRole(...$roles);
            $invitation->givePermissionTo(...$permissions);

            return $invitation;
        });

        Mail::to($email)->queue(new OrgInvitationMail($invitation, $token));
        $this->notifyExistingAccount($invitation);

        return $invitation;
    }

    /**
     * Send a pending invitation again with a new link and a new expiry; the old link stops working.
     */
    public function resend(OrgInvitation $invitation): OrgInvitation
    {
        $token = Str::random(40);

        $invitation->update([
            'token_hash' => OrgInvitation::hashToken($token),
            'expires_at' => now()->addDays(OrgInvitation::EXPIRES_AFTER_DAYS),
        ]);

        Mail::to($invitation->email)->queue(new OrgInvitationMail($invitation, $token));
        $this->notifyExistingAccount($invitation);

        return $invitation;
    }

    /**
     * Someone who already has an account also sees the invitation in the app, once per invitation.
     */
    private function notifyExistingAccount(OrgInvitation $invitation): void
    {
        $invitee = User::query()->where('email', $invitation->email)->first();

        if (! $invitee instanceof User) {
            return;
        }

        $alreadyNotified = $invitee->unreadNotifications()
            ->where('data->kind', InvitedToOrganization::KIND)
            ->where('data->invitation_id', $invitation->id)
            ->exists();

        if (! $alreadyNotified) {
            $invitee->notify(new InvitedToOrganization($invitation->load(['organization:id,name', 'invitedBy:id,name', 'roles:id,name'])));
        }
    }
}
