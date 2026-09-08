<?php

declare(strict_types=1);

namespace App\Services\Messaging;

/**
 * The bytes of one MMS attachment, pulled down from the provider.
 *
 * Held in memory rather than streamed. Carriers cap MMS well below the
 * point where that matters — Twilio accepts 5MB and most US carriers
 * transcode to under 1MB before it ever reaches us — and the email
 * attachment path this mirrors does the same. If a transport ever
 * carries genuinely large media this becomes a stream and the job's
 * write becomes writeStream(); nothing else changes.
 */
final class FetchedMedia
{
    public function __construct(
        public readonly string $contents,
        public readonly ?string $contentType = null,
        public readonly ?string $filename = null,
    ) {}

    public function size(): int
    {
        return strlen($this->contents);
    }
}
