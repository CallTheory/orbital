<?php

declare(strict_types=1);

namespace App\Services\Messaging\Contracts;

use App\Services\Messaging\FetchedMedia;
use App\Services\Messaging\InboundMessage;
use App\Services\Messaging\SendResult;
use Illuminate\Http\Request;

/**
 * A transport that can carry text-shaped messages.
 *
 * Kept narrow so adding Telnyx or an SMPP binding later is a new class
 * and a config entry rather than a change to the routing pipeline:
 *
 *   verify()      — is this webhook request genuinely from the provider
 *   parse()       — turn its payload into our InboundMessage shape
 *   send()        — hand an outbound message to the carrier
 *   fetchMedia()  — pull an MMS attachment down with the provider's own
 *                   credentials, which is the only way most of them are
 *                   readable at all
 *
 * Everything downstream of parse() is provider-agnostic. Everything
 * upstream of send() is too. Providers know about HTTP payloads and
 * carrier semantics; they know nothing about clients, queues, or
 * orchestrations.
 */
interface MessagingProvider
{
    /**
     * The driver key this provider registers under in
     * config/messaging.php, and the value stored on
     * messaging_endpoints.provider.
     */
    public function key(): string;

    /**
     * Does this transport require a sender pool?
     *
     * A sender pool is the carrier's grouping of numbers — Twilio's
     * Messaging Service, Telnyx's Messaging Profile, Bandwidth's
     * Application. Where one exists we require it and refuse to send
     * without it, because the alternative (naming a bare `From` number)
     * means no carrier-side STOP/HELP handling, no sticky sender, and a
     * number that will eventually be de-registered for ignoring an
     * opt-out it never saw.
     *
     * Returns false only for transports that genuinely have no such
     * concept — an SMPP bind, a WCTP pager gateway, the dev Log driver.
     */
    public function requiresSenderPool(): bool;

    /**
     * What this carrier calls its sender pool, for admin UI copy.
     * Nobody configuring Twilio is looking for a field called
     * "sender pool"; they are looking for "Messaging Service SID".
     */
    public function senderPoolLabel(): string;

    /**
     * Authenticate an inbound webhook request.
     *
     * MUST return false when it cannot positively verify the request.
     * This endpoint is public by necessity — the carrier has to reach
     * it — so it is the boundary where an attacker would try to inject
     * fabricated customer messages into a client's queue. Failing open
     * here means anyone on the internet can put words in a caller's
     * mouth.
     */
    public function verify(Request $request): bool;

    /**
     * Turn a verified webhook payload into normalised inbound messages.
     *
     * Returns a list because some providers batch, and an empty list
     * because some webhooks (delivery receipts, status callbacks) carry
     * no new message at all.
     *
     * @return array<int, InboundMessage>
     */
    public function parse(Request $request): array;

    /**
     * Delivery-status updates carried by this request, keyed by the
     * provider's own message id.
     *
     * Separate from parse() because a status callback is not a message:
     * it updates a row we already have. Conflating them is how you end
     * up with a thread showing the same reply three times.
     *
     * @return array<string, array{status: string, error: ?string}>
     */
    public function parseDeliveryReceipts(Request $request): array;

    /**
     * Send an outbound message.
     *
     * @param  array<string, mixed>  $options  endpoint provider_config
     * @param  array<int, string>  $media  URLs for MMS/RCS
     */
    public function send(
        string $from,
        string $to,
        string $body,
        array $options = [],
        array $media = [],
    ): SendResult;

    /**
     * Download one inbound media attachment.
     *
     * Providers hand us a URL, not bytes, and for most of them that URL
     * is authenticated with the same account credentials as the REST
     * API and expires on the provider's own schedule. So fetching has
     * to go through the driver — a bare HTTP GET from the job would
     * get a 401 from Twilio and a dead link from everyone else later.
     *
     * Returns null when the media can't be retrieved. That is a
     * recoverable state, not an exception: the caller keeps the
     * original URL and the operator can still open it manually.
     *
     * @param  array<string, mixed>  $options  endpoint provider_config
     */
    public function fetchMedia(string $url, array $options = []): ?FetchedMedia;

    /**
     * Delete media from the provider after we have stored our own copy.
     *
     * Opt-in (see config/messaging.php). Carriers retain MMS media
     * indefinitely and charge for it, and a customer's photograph
     * sitting on a third party's storage forever is a privacy problem
     * as much as a billing one — but deleting the only other copy is
     * irreversible, so it is never the default.
     *
     * @param  array<string, mixed>  $options  endpoint provider_config
     */
    public function deleteMedia(string $url, array $options = []): bool;
}
