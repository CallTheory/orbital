<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Mailable for forwarding thread messages to external addresses.
 * No In-Reply-To / References — forwarded messages start a new
 * conversation in the recipient's mail client by design.
 */
class ThreadForward extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $toAddress,
        public string $fromAddress,
        public ?string $fromName,
        string $subject,
        public string $bodyText,
        public ?string $bodyHtml,
    ) {
        $this->subject = $subject;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address($this->fromAddress, $this->fromName ?: ''),
            to: [new Address($this->toAddress)],
            subject: $this->subject,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.thread-reply-text',
            htmlString: $this->bodyHtml,
            text: null,
            with: [
                'bodyText' => $this->bodyText,
            ],
        );
    }
}
