<?php

declare(strict_types=1);

namespace App\Services\Messaging\Providers;

use App\Services\Messaging\Contracts\MessagingProvider;
use App\Services\Messaging\FetchedMedia;
use App\Services\Messaging\InboundMessage;
use App\Services\Messaging\SendResult;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Development / offline provider.
 *
 * Accepts a simple JSON body so the whole inbound pipeline — routing,
 * threading, queue assignment, the operator inbox — can be exercised on
 * a laptop with no carrier account and no internet, which the
 * offline-first constraint makes a requirement rather than a
 * convenience. Outbound sends are logged instead of transmitted.
 *
 * Verification is a shared secret, and it is NOT optional even here.
 * A dev-only provider that fails open is exactly the kind of thing that
 * gets left enabled on a staging box with a real DNS name.
 *
 * Inbound payload:
 *
 *   { "from": "+15551234567", "to": "+15559876543",
 *     "body": "hello", "protocol": "sms" }
 */
class LogProvider implements MessagingProvider
{
    public function key(): string
    {
        return 'log';
    }

    /**
     * No. There is no carrier here to hold a pool, and requiring one
     * would make the offline development path impossible to use —
     * which is the entire reason this driver exists.
     */
    public function requiresSenderPool(): bool
    {
        return false;
    }

    public function senderPoolLabel(): string
    {
        return 'Sender pool';
    }

    public function verify(Request $request): bool
    {
        $secret = (string) config('messaging.providers.log.secret');

        if ($secret === '') {
            Log::warning('log messaging provider rejected a webhook: no secret configured');

            return false;
        }

        return hash_equals($secret, (string) $request->bearerToken());
    }

    public function parse(Request $request): array
    {
        $from = (string) $request->input('from', '');
        $to = (string) $request->input('to', '');

        if ($from === '' || $to === '') {
            return [];
        }

        return [new InboundMessage(
            from: $from,
            to: $to,
            body: $request->input('body'),
            protocol: (string) $request->input('protocol', 'sms'),
            providerMessageId: $request->input('id', 'log-'.Str::uuid()->toString()),
            provider: $this->key(),
            media: (array) $request->input('media', []),
            raw: (array) $request->all(),
            // Not a real carrier concept here, but accepted so the
            // pool-routing path can be exercised offline exactly as a
            // carrier would drive it.
            senderPoolId: $request->input('sender_pool_id'),
        )];
    }

    public function parseDeliveryReceipts(Request $request): array
    {
        $id = (string) $request->input('delivery_id', '');
        $status = (string) $request->input('delivery_status', '');

        if ($id === '' || $status === '') {
            return [];
        }

        return [$id => ['status' => $status, 'error' => $request->input('delivery_error')]];
    }

    public function send(
        string $from,
        string $to,
        string $body,
        array $options = [],
        array $media = [],
    ): SendResult {
        Log::info('messaging(log): outbound message', [
            'from' => $from,
            'to' => $to,
            'body' => $body,
            'media' => $media,
        ]);

        return SendResult::accepted('log-'.Str::uuid()->toString(), sender: $from);
    }

    /**
     * Reads from the local filesystem so the media pipeline — fetch,
     * store to the object store, rewrite the entry, render in the
     * operator UI — can be exercised end to end with no carrier and no
     * internet. Anything that isn't a readable local file returns null,
     * the same as an unfetchable carrier URL.
     */
    public function fetchMedia(string $url, array $options = []): ?FetchedMedia
    {
        if (! str_starts_with($url, 'file://')) {
            Log::info('messaging(log): media fetch skipped, not a local file', ['url' => $url]);

            return null;
        }

        $path = substr($url, strlen('file://'));

        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            return null;
        }

        return new FetchedMedia(
            contents: $contents,
            contentType: mime_content_type($path) ?: null,
            filename: basename($path),
        );
    }

    public function deleteMedia(string $url, array $options = []): bool
    {
        Log::info('messaging(log): would delete provider media', ['url' => $url]);

        return false;
    }
}
