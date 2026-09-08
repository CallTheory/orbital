<?php

declare(strict_types=1);

namespace App\Services\Messaging\Providers;

use App\Models\MessageEntry;
use App\Services\Messaging\Contracts\MessagingProvider;
use App\Services\Messaging\FetchedMedia;
use App\Services\Messaging\InboundMessage;
use App\Services\Messaging\SendResult;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Twilio Programmable Messaging.
 *
 * Talks to the REST API directly rather than pulling in twilio/sdk: the
 * two calls we make are a form POST and a signature check, and the SDK
 * would add a dependency (plus its own HTTP client) for no benefit.
 *
 * Signature verification follows Twilio's documented scheme — the full
 * request URL, then every POST parameter appended in key-sorted order,
 * HMAC-SHA1'd with the account auth token, base64'd, compared to the
 * X-Twilio-Signature header. Getting this wrong in the permissive
 * direction means anyone can POST fabricated customer messages into a
 * client's queue, so it fails closed on every uncertainty.
 */
class TwilioProvider implements MessagingProvider
{
    public function key(): string
    {
        return 'twilio';
    }

    /**
     * Yes, and this is the whole compliance story for the channel.
     *
     * A Messaging Service handles STOP/HELP/START for every number in
     * it, keeps a customer on one sticky sender, and carries the A2P
     * 10DLC campaign registration a US long code needs to deliver at
     * all. A bare `From` has none of that, and the failure is not a
     * bounced message — it is a number the carriers stop trusting.
     */
    public function requiresSenderPool(): bool
    {
        return true;
    }

    public function senderPoolLabel(): string
    {
        return 'Messaging Service SID';
    }

    public function verify(Request $request): bool
    {
        $token = (string) config('messaging.providers.twilio.auth_token');

        if ($token === '') {
            Log::warning('twilio webhook rejected: no auth token configured');

            return false;
        }

        $signature = (string) $request->header('X-Twilio-Signature', '');

        if ($signature === '') {
            return false;
        }

        return hash_equals($this->expectedSignature($request, $token), $signature);
    }

    /**
     * Twilio's signature: the full URL, then each POST param as
     * key+value concatenated in key-sorted order, HMAC-SHA1 with the
     * auth token, base64 encoded.
     *
     * The URL must be the one Twilio actually called. Behind nginx that
     * means the forwarded scheme and host, which Laravel already
     * reconstructs correctly because trustProxies is configured — see
     * bootstrap/app.php. Without that trust, every signature would fail
     * on http-vs-https alone.
     */
    private function expectedSignature(Request $request, string $token): string
    {
        $data = $request->fullUrl();

        $params = $request->post();
        ksort($params);

        foreach ($params as $key => $value) {
            $data .= $key.(is_array($value) ? implode('', $value) : (string) $value);
        }

        return base64_encode(hash_hmac('sha1', $data, $token, true));
    }

    public function parse(Request $request): array
    {
        // A status callback carries MessageStatus but no Body/From pair
        // to act on — those are handled by parseDeliveryReceipts().
        if ($request->filled('MessageStatus') && ! $request->filled('Body') && ! $request->filled('NumMedia')) {
            return [];
        }

        $from = (string) $request->input('From', '');
        $to = (string) $request->input('To', '');

        if ($from === '' || $to === '') {
            return [];
        }

        $media = [];
        $mediaCount = (int) $request->input('NumMedia', 0);

        for ($i = 0; $i < $mediaCount; $i++) {
            $url = $request->input("MediaUrl{$i}");

            if (is_string($url) && $url !== '') {
                $media[] = [
                    'url' => $url,
                    'content_type' => $request->input("MediaContentType{$i}"),
                ];
            }
        }

        return [new InboundMessage(
            from: $from,
            to: $to,
            body: $request->input('Body'),
            // Twilio uses one webhook for both; the presence of media is
            // what makes it an MMS.
            protocol: $media === [] ? 'sms' : 'mms',
            providerMessageId: $request->input('MessageSid'),
            provider: $this->key(),
            media: $media,
            raw: $request->post(),
            // Present on every message that arrived through a Messaging
            // Service, which — since we refuse to send without one — is
            // every message on a correctly configured endpoint. It's the
            // routing key we prefer precisely because it survives the
            // client adding a number to their pool without telling us.
            senderPoolId: $request->input('MessagingServiceSid'),
        )];
    }

    public function parseDeliveryReceipts(Request $request): array
    {
        $sid = (string) $request->input('MessageSid', '');
        $status = (string) $request->input('MessageStatus', '');

        if ($sid === '' || $status === '') {
            return [];
        }

        $mapped = match ($status) {
            'queued', 'accepted', 'scheduled' => MessageEntry::STATUS_QUEUED,
            'sending', 'sent' => MessageEntry::STATUS_SENT,
            'delivered' => MessageEntry::STATUS_DELIVERED,
            'undelivered' => MessageEntry::STATUS_UNDELIVERED,
            'failed' => MessageEntry::STATUS_FAILED,
            // 'received' arrives on inbound callbacks; not a receipt.
            default => null,
        };

        if ($mapped === null) {
            return [];
        }

        return [$sid => [
            'status' => $mapped,
            'error' => $request->input('ErrorMessage') ?? $request->input('ErrorCode'),
        ]];
    }

    public function send(
        string $from,
        string $to,
        string $body,
        array $options = [],
        array $media = [],
    ): SendResult {
        $sid = (string) ($options['account_sid'] ?? config('messaging.providers.twilio.account_sid'));
        $token = (string) ($options['auth_token'] ?? config('messaging.providers.twilio.auth_token'));

        if ($sid === '' || $token === '') {
            return SendResult::failed('Twilio credentials are not configured.');
        }

        $pool = trim((string) ($options['sender_pool_id'] ?? ''));

        // No fallback to a bare `From`. This used to fall back and that
        // was a mistake: a message sent from a naked number bypasses
        // Twilio's opt-out enforcement entirely, so a customer who
        // texted STOP would keep receiving messages — the exact
        // violation that gets a number de-registered. Refusing to send
        // is a visible failure an operator can escalate; sending
        // non-compliantly is an invisible one that surfaces as a carrier
        // ban weeks later.
        if ($pool === '') {
            return SendResult::failed(
                'This number has no Messaging Service configured, so the message was not sent. '
                .'Add the Messaging Service SID to the messaging number before replying.'
            );
        }

        $payload = [
            'To' => $to,
            'Body' => $body,
            'MessagingServiceSid' => $pool,
        ];

        foreach (array_values($media) as $index => $url) {
            $payload["MediaUrl[{$index}]"] = $url;
        }

        if (! empty($options['status_callback'])) {
            $payload['StatusCallback'] = $options['status_callback'];
        }

        try {
            $response = Http::asForm()
                ->withBasicAuth($sid, $token)
                ->timeout((int) config('messaging.providers.twilio.timeout', 10))
                ->post(
                    rtrim((string) config('messaging.providers.twilio.base_url'), '/')
                        ."/Accounts/{$sid}/Messages.json",
                    $payload,
                );
        } catch (\Throwable $e) {
            return SendResult::failed($e->getMessage());
        }

        if (! $response->successful()) {
            return SendResult::failed(
                (string) ($response->json('message') ?? 'Twilio returned HTTP '.$response->status()),
            );
        }

        return SendResult::accepted(
            $response->json('sid'),
            sender: $response->json('from'),
        );
    }

    /**
     * Twilio media URLs are not public. They sit under the account's
     * REST namespace and need the same basic auth as any other API
     * call, so this is the only way to get the bytes.
     */
    public function fetchMedia(string $url, array $options = []): ?FetchedMedia
    {
        $sid = (string) ($options['account_sid'] ?? config('messaging.providers.twilio.account_sid'));
        $token = (string) ($options['auth_token'] ?? config('messaging.providers.twilio.auth_token'));

        if ($sid === '' || $token === '') {
            Log::warning('twilio media fetch skipped: credentials not configured');

            return null;
        }

        if (! $this->isOwnMediaUrl($url)) {
            return null;
        }

        try {
            $response = Http::withBasicAuth($sid, $token)
                ->timeout((int) config('messaging.media.timeout', 30))
                ->get($url);
        } catch (\Throwable $e) {
            Log::warning('twilio media fetch failed', ['url' => $url, 'error' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('twilio media fetch returned an error', [
                'url' => $url,
                'status' => $response->status(),
            ]);

            return null;
        }

        return new FetchedMedia(
            contents: $response->body(),
            contentType: $response->header('Content-Type') ?: null,
        );
    }

    public function deleteMedia(string $url, array $options = []): bool
    {
        $sid = (string) ($options['account_sid'] ?? config('messaging.providers.twilio.account_sid'));
        $token = (string) ($options['auth_token'] ?? config('messaging.providers.twilio.auth_token'));

        if ($sid === '' || $token === '' || ! $this->isOwnMediaUrl($url)) {
            return false;
        }

        try {
            // Twilio's media URLs carry an optional format extension
            // (.jpg, .png). The DELETE has to go to the bare resource.
            $resource = preg_replace('/\.[A-Za-z0-9]+$/', '', $url) ?? $url;

            $response = Http::withBasicAuth($sid, $token)
                ->timeout((int) config('messaging.media.timeout', 30))
                ->delete($resource);
        } catch (\Throwable $e) {
            Log::warning('twilio media delete failed', ['url' => $url, 'error' => $e->getMessage()]);

            return false;
        }

        return $response->successful();
    }

    /**
     * Refuse to fetch anything that isn't on Twilio's own API host.
     *
     * The URL originates in a signature-verified webhook, so this is
     * defence in depth rather than the primary control — but it is the
     * control that matters if the auth token ever leaks, because
     * without it a forged webhook turns this worker into an
     * SSRF proxy that helpfully attaches the account's basic-auth
     * credentials to whatever host it is pointed at.
     */
    private function isOwnMediaUrl(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        $scheme = parse_url($url, PHP_URL_SCHEME);

        if (! is_string($host) || $scheme !== 'https') {
            return false;
        }

        $allowed = (array) config('messaging.providers.twilio.media_hosts', ['api.twilio.com']);

        foreach ($allowed as $candidate) {
            $candidate = strtolower(trim((string) $candidate));

            if ($candidate === '') {
                continue;
            }

            if (strtolower($host) === $candidate || str_ends_with(strtolower($host), '.'.$candidate)) {
                return true;
            }
        }

        Log::warning('twilio media fetch refused: host not allowed', ['url' => $url]);

        return false;
    }
}
