<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\OrgInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class OrgInvitationMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    /**
     * The plain token is carried only by this queued mail; the database keeps a hash.
     */
    public function __construct(
        public OrgInvitation $invitation,
        public string $token,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: sprintf('You have been invited to join %s', $this->invitation->organization->name ?? 'an organization'),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.org-invitation',
            with: [
                'organizationName' => $this->invitation->organization->name ?? '',
                'roleNames' => $this->invitation->roles()->pluck('name')->join(', ', ' and '),
                'inviterName' => $this->invitation->invitedBy?->name,
                'acceptUrl' => route('invitations.show', $this->token),
                'expiresAt' => $this->invitation->expires_at->toFormattedDayDateString(),
            ],
        );
    }
}
