<?php

declare(strict_types=1);

namespace App\Actions;

use App\Mail\OrgInvitationMail;
use App\Models\Organization;
use App\Models\OrgInvitation;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Invites an email address to an organization with a role. A fresh invitation replaces any pending
 * one for the same address, so only the latest link works.
 */
final readonly class InviteMember
{
    public function handle(User $inviter, Organization $organization, string $email, Role $role): OrgInvitation
    {
        $token = Str::random(40);
        $email = mb_strtolower($email);

        $invitation = DB::transaction(function () use ($inviter, $organization, $email, $role, $token): OrgInvitation {
            $organization->pendingInvitations()->where('email', $email)->delete();

            return $organization->invitations()->create([
                'role_id' => $role->id,
                'invited_by_id' => $inviter->id,
                'email' => $email,
                'token_hash' => OrgInvitation::hashToken($token),
                'expires_at' => now()->addDays(OrgInvitation::EXPIRES_AFTER_DAYS),
            ]);
        });

        Mail::to($email)->queue(new OrgInvitationMail($invitation, $token));

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

        return $invitation;
    }
}
