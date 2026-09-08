<?php

declare(strict_types=1);

namespace App\Services\Messaging;

/**
 * One inbound message, normalised out of whatever shape the provider
 * sent it in.
 *
 * The boundary object between provider-specific parsing and the
 * provider-agnostic routing pipeline. Everything downstream —
 * InboundMessageRouter, MessageThreadResolver, the orchestration
 * runner — sees only this.
 */
final class InboundMessage
{
    /**
     * @param  string  $from  the customer's address
     * @param  string  $to  the client's number that was texted
     * @param  string|null  $senderPoolId  the carrier's sender pool this
     *                                     arrived on — Twilio's
     *                                     MessagingServiceSid and its
     *                                     equivalents. The primary
     *                                     routing key where present,
     *                                     because it identifies the
     *                                     client without us having to
     *                                     keep a number list in sync
     *                                     with the carrier.
     * @param  array<int, array{url: string, content_type: ?string}>  $media
     * @param  array<string, mixed>  $raw  the original payload, for debugging
     */
    public function __construct(
        public readonly string $from,
        public readonly string $to,
        public readonly ?string $body,
        public readonly string $protocol = 'sms',
        public readonly ?string $providerMessageId = null,
        public readonly string $provider = 'unknown',
        public readonly array $media = [],
        public readonly array $raw = [],
        public readonly ?\DateTimeInterface $occurredAt = null,
        public readonly ?string $senderPoolId = null,
    ) {}

    public function hasContent(): bool
    {
        return trim((string) $this->body) !== '' || $this->media !== [];
    }
}
