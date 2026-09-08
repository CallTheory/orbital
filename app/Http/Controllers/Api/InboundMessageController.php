<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessInboundMessageJob;
use App\Models\MessageEntry;
use App\Services\Messaging\ProviderRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Inbound messaging webhook: POST /api/messaging/inbound/{provider}
 *
 * Public by necessity — the carrier has to reach it — which makes it
 * the most exposed surface in the messaging channel. Two consequences
 * shape this controller:
 *
 *   1. Signature verification happens FIRST, before anything is read
 *      out of the payload, and a failure returns 403 without touching
 *      the database. Failing open here would let anyone on the internet
 *      inject fabricated customer messages into a client's queue —
 *      words attributed to a real caller, in a real conversation, that
 *      a real operator would act on.
 *
 *   2. The response is fast and shallow. Carriers retry on non-2xx and
 *      time out aggressively, so the actual work (routing, threading,
 *      AI reply) goes to a queue. This mirrors InboundMailController's
 *      stub-then-queue shape.
 *
 * Delivery receipts are handled inline rather than queued: they're a
 * single indexed update on a row we already have, and an operator
 * watching a failed send wants to know now.
 */
class InboundMessageController extends Controller
{
    public function __invoke(Request $request, string $provider): JsonResponse
    {
        $registry = app(ProviderRegistry::class);

        if (! $registry->has($provider)) {
            // 404, not 400: an unknown provider key shouldn't confirm to
            // a prober which keys do exist.
            return response()->json(['error' => 'unknown provider'], 404);
        }

        $driver = $registry->get($provider);

        if (! $driver->verify($request)) {
            Log::warning('inbound message webhook: signature verification failed', [
                'provider' => $provider,
                'remote' => $request->ip(),
            ]);

            return response()->json(['error' => 'unauthorized'], 403);
        }

        $receipts = $driver->parseDeliveryReceipts($request);

        foreach ($receipts as $providerMessageId => $receipt) {
            $this->applyDeliveryReceipt($provider, (string) $providerMessageId, $receipt);
        }

        $messages = $driver->parse($request);
        $accepted = 0;

        foreach ($messages as $message) {
            if (! $message->hasContent()) {
                continue;
            }

            ProcessInboundMessageJob::dispatch([
                'from' => $message->from,
                'to' => $message->to,
                'body' => $message->body,
                'protocol' => $message->protocol,
                'provider' => $message->provider,
                'provider_message_id' => $message->providerMessageId,
                'media' => $message->media,
                // The primary routing key. Dropping it here would make
                // every pool-routed message fall back to matching on the
                // number that was texted — which is exactly the number
                // list that pools exist to stop us maintaining, so a
                // number the client added to their pool would arrive
                // unrouted.
                'sender_pool_id' => $message->senderPoolId,
            ]);

            $accepted++;
        }

        return response()->json([
            'ok' => true,
            'accepted' => $accepted,
            'receipts' => count($receipts),
        ]);
    }

    /**
     * Update an outbound entry's delivery state.
     *
     * Scoped by provider as well as id: provider message ids are only
     * unique within a provider, and the unique index on
     * (provider, provider_message_id) says so.
     *
     * @param  array{status: string, error: ?string}  $receipt
     */
    private function applyDeliveryReceipt(string $provider, string $providerMessageId, array $receipt): void
    {
        $entry = MessageEntry::withoutGlobalScopes()
            ->where('provider', $provider)
            ->where('provider_message_id', $providerMessageId)
            ->first();

        if (! $entry) {
            // Common and harmless: a receipt for a message sent by
            // something other than Orbital, or one that arrived before
            // our own insert committed. The carrier will retry.
            return;
        }

        // Receipts can arrive out of order — 'sent' after 'delivered'
        // happens. Never walk a message backwards to a less final state
        // or the operator watches a delivered reply turn pending again.
        if ($this->finality($receipt['status']) < $this->finality($entry->delivery_status)) {
            return;
        }

        $entry->forceFill([
            'delivery_status' => $receipt['status'],
            'delivery_error' => $receipt['error'] ? (string) $receipt['error'] : null,
        ])->save();
    }

    /**
     * How final a delivery state is. Higher wins.
     */
    private function finality(string $status): int
    {
        return match ($status) {
            MessageEntry::STATUS_QUEUED => 1,
            MessageEntry::STATUS_SENT => 2,
            MessageEntry::STATUS_DELIVERED,
            MessageEntry::STATUS_UNDELIVERED,
            MessageEntry::STATUS_FAILED => 3,
            default => 0,
        };
    }
}
