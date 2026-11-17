<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\OrgInvitation;
use App\Models\Role;
use Illuminate\Notifications\Notification;

/**
 * Someone with an account was invited to an organization. Links to the invitation by id rather than by
 * its emailed token, which is never stored.
 */
final class InvitedToOrganization extends Notification
{
    public const string KIND = 'invitation';

    public function __construct(public OrgInvitation $invitation) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array{kind: string, invitation_id: int, title: string, body: string, url: string}
     */
    public function toArray(object $notifiable): array
    {
        $organization = $this->invitation->organization->name ?? 'an organization';
        $inviter = $this->invitation->invitedBy?->name;

        return [
            'kind' => self::KIND,
            'invitation_id' => $this->invitation->id,
            'title' => $inviter === null ? sprintf("You're invited to %s", $organization) : sprintf('%s invited you to %s', $inviter, $organization),
            'body' => 'Roles: '.implode(', ', array_map(fn (Role $role): string => $role->name, $this->invitation->roles->all())),
            'url' => route('invitations.received', $this->invitation, absolute: false),
        ];
    }
}
