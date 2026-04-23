<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\ClientInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Outbound invitation email for a ClientInvitation.
 *
 * The CTA is a signed URL to /invite/{token} that handles every
 * downstream case:
 *   - email matches an existing User → sign-in page → accept
 *   - email doesn't match any User → single signup form → accept
 *   - user already signed in as the invited email → one-click accept
 *   - user signed in as a different email → sign-out-first prompt
 *
 * One link, four outcomes, zero "create your account first, then
 * come back to the email" round-trips.
 */
class ClientInvitationMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public ClientInvitation $invitation,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "You've been invited to {$this->invitation->team->name}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.client-invitation',
            with: [
                'invitation' => $this->invitation,
                'client' => $this->invitation->team,
                'inviter' => $this->invitation->invitedBy,
                'acceptUrl' => route('invitation.show', ['token' => $this->invitation->token]),
            ],
        );
    }
}
