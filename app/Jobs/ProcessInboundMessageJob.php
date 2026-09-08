<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\MessageEntry;
use App\Models\MessageThread;
use App\Models\MessagingEndpoint;
use App\Models\MessagingOptOut;
use App\Services\Messaging\InboundMessage;
use App\Services\Messaging\InboundMessageRouter;
use App\Services\Messaging\MessageThreadResolver;
use App\Services\Messaging\OptOutRegistry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Routes and persists one verified inbound message.
 *
 * Mirrors ProcessInboundEmailJob: the webhook returns fast, this does
 * the work. Runs on its own `inbound-messages` queue so a burst of SMS
 * can't starve telephony workers — the same reasoning behind the
 * separate `inbound-mail` queue.
 *
 * Flow:
 *   1. Route → which client, which queue (InboundMessageRouter)
 *   2. Resolve → which conversation (MessageThreadResolver)
 *   3. Persist the entry
 *   4. Apply consent keywords (STOP / START / HELP)
 *   5. Fetch any MMS media into our own object store
 *   6. Hand to the AI reply job when the queue is set up for it
 *
 * Step 4 runs AFTER the entry is written, not instead of it. A STOP is
 * a message the customer sent and the client is entitled to see it in
 * the conversation — swallowing it would leave a thread that simply
 * goes quiet with no record of why.
 *
 * Takes a plain array rather than an InboundMessage object because
 * queued payloads get serialised to JSON and back; a readonly value
 * object with a DateTimeInterface survives that badly.
 */
class ProcessInboundMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** 5s, 30s, 2min — same shape as the inbound mail job. */
    public array $backoff = [5, 30, 120];

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public array $payload,
    ) {
        $this->onQueue('inbound-messages');
    }

    public function handle(
        InboundMessageRouter $router,
        MessageThreadResolver $resolver,
        OptOutRegistry $optOuts,
    ): void {
        $message = new InboundMessage(
            from: (string) ($this->payload['from'] ?? ''),
            to: (string) ($this->payload['to'] ?? ''),
            body: $this->payload['body'] ?? null,
            protocol: (string) ($this->payload['protocol'] ?? 'sms'),
            providerMessageId: $this->payload['provider_message_id'] ?? null,
            provider: (string) ($this->payload['provider'] ?? 'unknown'),
            media: (array) ($this->payload['media'] ?? []),
            senderPoolId: $this->payload['sender_pool_id'] ?? null,
        );

        if ($message->from === '' || $message->to === '') {
            Log::warning('inbound message job: missing from/to', ['payload' => $this->payload]);

            return;
        }

        $routed = $router->route($message);

        if ($routed['status'] !== 'routed' || ! $routed['endpoint']) {
            // Unrouted messages are dropped with a log line rather than
            // stored. Unlike email — where an unrouted message is held
            // for an admin to reassign because the sender expects a
            // human reply eventually — an SMS to a number we don't serve
            // is almost always a wrong number or a spam blast, and
            // persisting it would mean storing arbitrary internet
            // strangers' content with no client to own it.
            Log::info('inbound message dropped: no client endpoint', [
                'to' => $message->to,
                'provider' => $message->provider,
            ]);

            return;
        }

        $endpoint = $routed['endpoint'];

        $thread = $resolver->resolve($message, $endpoint, $routed['queue']);

        // Consent BEFORE the duplicate-entry guard below, not after.
        // If a previous attempt died between writing the entry and
        // getting here, the retry would find its own row, treat the
        // delivery as already handled, and never record the STOP. Both
        // optOut() and closing an already-closed thread are idempotent,
        // so running this on every delivery of the same message is free
        // — and it is the one step whose omission is a compliance
        // failure rather than a cosmetic one.
        $intent = $this->applyConsentKeywords($optOuts, $thread, $endpoint, $message->body);

        // Idempotent on the provider's message id: carriers retry
        // webhooks, and a retry must not duplicate the customer's words
        // in the operator's view.
        $entry = DB::transaction(function () use ($message, $thread, $endpoint): ?MessageEntry {
            if ($message->providerMessageId) {
                $existing = MessageEntry::withoutGlobalScopes()
                    ->where('provider', $message->provider)
                    ->where('provider_message_id', $message->providerMessageId)
                    ->first();

                if ($existing) {
                    return null;
                }
            }

            return MessageEntry::create([
                'message_thread_id' => $thread->id,
                'team_id' => $endpoint->team_id,
                'direction' => MessageEntry::DIRECTION_INBOUND,
                'from_address' => $message->from,
                'to_address' => $message->to,
                'body' => $message->body,
                'media' => $message->media ?: null,
                'provider' => $message->provider,
                'provider_message_id' => $message->providerMessageId,
                'delivery_status' => MessageEntry::STATUS_RECEIVED,
                'occurred_at' => now(),
            ]);
        });

        if (! $entry) {
            return;
        }

        if ($entry->media) {
            // Provider URLs expire and need the carrier's credentials.
            // Queue our own copy immediately — the window in which the
            // link still works is the only one we get.
            FetchMessageMediaJob::dispatch($entry->id);
        }

        // STOP, START and HELP are all answered by the carrier's sender
        // pool with the registered campaign text. An LLM reply on top of
        // that is at best redundant and at worst contradicts the
        // mandated "reply STOP to opt out" line the carrier just sent.
        if ($intent !== 'none') {
            return;
        }

        $this->maybeAutoReply($thread->id);
    }

    /**
     * Apply STOP / START / HELP to the suppression list.
     *
     * Returns the intent so the caller knows whether to hand the
     * message on to the AI.
     */
    private function applyConsentKeywords(
        OptOutRegistry $optOuts,
        MessageThread $thread,
        MessagingEndpoint $endpoint,
        ?string $body,
    ): string {
        // Gates automatic keyword CAPTURE only. Suppressions already on
        // the list are still enforced on every send — an installation
        // that handles opt-out elsewhere still must not text somebody
        // Orbital has already been told to leave alone.
        if (! config('messaging.honour_opt_out_keywords', true)) {
            return 'none';
        }

        ['intent' => $intent, 'keyword' => $keyword] = MessagingOptOut::classify($body);

        if ($intent === 'stop') {
            $optOuts->optOut(
                teamId: $endpoint->team_id,
                address: $thread->remote_address,
                keyword: $keyword,
                source: MessagingOptOut::SOURCE_INBOUND,
                endpoint: $endpoint,
            );

            // Close the conversation. Leaving it open would sit in an
            // operator's inbox looking like work, and the only work
            // available on it is a reply we must not send.
            $thread->forceFill([
                'status' => MessageThread::STATUS_CLOSED,
                'closed_at' => now(),
                'assigned_operator_id' => null,
            ])->save();

            return $intent;
        }

        if ($intent === 'start') {
            $optOuts->optIn(
                teamId: $endpoint->team_id,
                address: $thread->remote_address,
                keyword: $keyword,
            );

            return $intent;
        }

        return $intent;
    }

    /**
     * Hand off to the AI when the thread's queue is configured for it.
     *
     * Gated twice — a platform-level switch and a per-queue persona —
     * because an AI that starts texting a client's customers without the
     * client having asked for it is a far worse failure than a slow
     * human reply. Both have to be deliberate.
     */
    private function maybeAutoReply(int $threadId): void
    {
        if (! config('messaging.auto_reply_enabled', false)) {
            return;
        }

        ProcessMessageWithAgentJob::dispatch($threadId);
    }
}
