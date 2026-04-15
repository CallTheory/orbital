<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

/**
 * Mailable used by OutboundReplyService to send threaded
 * replies to an inbound email message.
 *
 * The important thing this class does is set the In-Reply-To
 * and References headers so replies thread correctly in
 * standard mail clients (Gmail, Outlook, Apple Mail, etc.).
 * Without those headers, every reply we send would show up as
 * a fresh conversation on the other side.
 */
class ThreadReply extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $toAddress,
        public string $fromAddress,
        public ?string $fromName,
        public string $replySubject,
        public string $bodyText,
        public ?string $bodyHtml,
        public ?string $inReplyTo,
        /** @var array<int, string> */
        public array $references = [],
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address($this->fromAddress, $this->fromName ?: ''),
            to: [new Address($this->toAddress)],
            subject: $this->replySubject,
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

    public function headers(): Headers
    {
        $text = [];
        if ($this->inReplyTo) {
            // RFC5322 requires angle brackets on Message-ID
            // references. Stored bare; wrap at send time.
            $text['In-Reply-To'] = '<'.trim($this->inReplyTo, '<>').'>';
        }
        if (! empty($this->references)) {
            $text['References'] = implode(' ', array_map(
                fn ($ref) => '<'.trim($ref, '<>').'>',
                $this->references,
            ));
        }

        return new Headers(text: $text);
    }
}
