<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The lifecycle of an invitation to an organization:
 *
 *     Pending ──accept──▶ Accepted
 *        │
 *        └──time──▶ Expired
 *
 * Worked out from accepted_at and expires_at rather than stored, so it can't go stale as time passes.
 * Revoking an invitation, or sending a new one to the same address, deletes it: a deleted invitation has
 * no status, its link simply stops working. Accepted and Expired are final.
 */
enum InvitationStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Expired = 'expired';

    public function canBeAccepted(): bool
    {
        return $this === self::Pending;
    }

    /**
     * Why it can't be accepted, for the person trying to.
     */
    public function refusal(): ?string
    {
        return match ($this) {
            self::Pending => null,
            self::Accepted => 'This invitation has already been used.',
            self::Expired => 'This invitation has expired. Ask whoever invited you to send a new one.',
        };
    }
}
