<?php

declare(strict_types=1);

namespace App\Services\Messaging;

use App\Models\MessageEntry;

/**
 * The outcome of handing an outbound message to a provider.
 *
 * A value object rather than a thrown exception on failure: a failed
 * send still has to be recorded on the thread, because an operator
 * needs to see that their reply didn't go out. Throwing would leave the
 * conversation looking like nothing was ever attempted.
 */
final class SendResult
{
    private function __construct(
        public readonly bool $accepted,
        public readonly ?string $providerMessageId,
        public readonly string $status,
        public readonly ?string $error = null,
        public readonly ?string $sender = null,
    ) {}

    /**
     * $sender is the number the carrier actually sent from. With a
     * sender pool we don't choose it — the pool does, and sticky sender
     * means the same customer keeps talking to the same number while a
     * different customer on the same endpoint gets a different one.
     * Recording what the carrier picked is the only way the thread can
     * show the conversation the customer actually saw.
     */
    public static function accepted(
        ?string $providerMessageId,
        string $status = MessageEntry::STATUS_SENT,
        ?string $sender = null,
    ): self {
        return new self(true, $providerMessageId, $status, null, $sender);
    }

    public static function failed(string $error): self
    {
        return new self(false, null, MessageEntry::STATUS_FAILED, $error);
    }
}
