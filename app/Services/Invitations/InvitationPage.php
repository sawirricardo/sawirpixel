<?php

declare(strict_types=1);

namespace App\Services\Invitations;

use App\Models\OrgInvitation;
use App\Models\Permission;
use App\Models\User;

/**
 * Props for the invitation page, which is reached from the emailed link or from an in-app notification.
 */
final class InvitationPage
{
    /**
     * @return array{acceptUrl: string, invitation: array{email: string, organization: string, roles: list<mixed>, permissions: list<string>, inviter: string|null, is_pending: bool}|null, emailMatches: bool}
     */
    public static function props(?OrgInvitation $invitation, ?User $user, string $acceptUrl): array
    {
        return [
            'acceptUrl' => $acceptUrl,
            'invitation' => $invitation instanceof OrgInvitation ? [
                'email' => $invitation->email,
                'organization' => $invitation->organization->name ?? '',
                'roles' => array_values($invitation->roles->pluck('name')->all()),
                'permissions' => array_values($invitation->permissions->map(fn (Permission $permission): string => $permission->name->label())->all()),
                'inviter' => $invitation->invitedBy?->name,
                'is_pending' => $invitation->isPending(),
            ] : null,
            'emailMatches' => $user instanceof User && $invitation instanceof OrgInvitation
                && mb_strtolower($user->email) === mb_strtolower($invitation->email),
        ];
    }
}
