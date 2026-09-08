<?php

declare(strict_types=1);

namespace App\Services\Messaging;

use App\Models\MessageQueue;
use App\Models\MessagingEndpoint;
use Illuminate\Support\Facades\Log;

/**
 * Decides who an inbound message belongs to.
 *
 * The messaging sibling of App\Services\Mail\InboundRouter, and
 * deliberately the same two-step shape:
 *
 *   1. Which CLIENT — resolved from the endpoint the message arrived
 *      on. Email uses the account number in the local part; messaging
 *      prefers the carrier's SENDER POOL id and falls back to the
 *      number that was texted. Either way the destination identifies
 *      the client, because that's the only thing the sender can't spoof
 *      into another client's account.
 *
 *      Pool first, and not merely as an optimisation: a pool holds many
 *      numbers and the client adds to it through the carrier's console,
 *      not through Orbital. Matching on the pool means a number added
 *      this morning routes correctly this afternoon, where matching on
 *      an address list means it lands unrouted until somebody
 *      remembers to mirror the change here.
 *
 *   2. Which QUEUE within that client — the first active MessageQueue
 *      whose matched_addresses and matched_protocols admit this
 *      message. Falls back to the client's single queue when they only
 *      have one, because a client with one queue plainly means all
 *      traffic to go there and making them configure a match rule to
 *      say so is a trap.
 *
 * Like the mail router, this never sends, deletes, or transforms
 * anything. It answers "whose is this, and which bucket" and hands
 * back.
 */
class InboundMessageRouter
{
    /**
     * @return array{team_id: ?int, endpoint: ?MessagingEndpoint, queue: ?MessageQueue, status: string}
     */
    public function route(InboundMessage $message): array
    {
        $endpoint = null;

        if ($message->senderPoolId) {
            $endpoint = MessagingEndpoint::resolveByPool($message->provider, $message->senderPoolId);
        }

        if (! $endpoint) {
            $endpoint = MessagingEndpoint::resolve($message->to, $message->protocol);
        }

        // MMS arriving on an endpoint provisioned as SMS is the common
        // case, not an error — Twilio uses one number for both. Retry
        // the lookup as SMS before giving up, so a photo attached to a
        // reply doesn't vanish.
        if (! $endpoint && $message->protocol !== 'sms') {
            $endpoint = MessagingEndpoint::resolve($message->to, 'sms');
        }

        if (! $endpoint) {
            Log::warning('inbound message: no endpoint matched', [
                'to' => $message->to,
                'sender_pool_id' => $message->senderPoolId,
                'protocol' => $message->protocol,
                'provider' => $message->provider,
            ]);

            return ['team_id' => null, 'endpoint' => null, 'queue' => null, 'status' => 'unrouted'];
        }

        return [
            'team_id' => $endpoint->team_id,
            'endpoint' => $endpoint,
            'queue' => $this->resolveQueue($endpoint, $message),
            'status' => 'routed',
        ];
    }

    /**
     * Pick the client's queue for this message.
     *
     * Ordering is most-specific-first: a queue that names this exact
     * address beats one that only matches the protocol, which beats a
     * catch-all. Same precedence idea as EmailRoutingRule's priority
     * ordering — a client who bothered to name a number meant it.
     */
    private function resolveQueue(MessagingEndpoint $endpoint, InboundMessage $message): ?MessageQueue
    {
        $queues = MessageQueue::withoutGlobalScopes()
            ->where('team_id', $endpoint->team_id)
            ->where('is_active', true)
            ->orderBy('id')
            ->get();

        if ($queues->isEmpty()) {
            return null;
        }

        // Match on the number the customer actually texted, not on the
        // endpoint's own address. With a sender pool the endpoint covers
        // many numbers and its `address` may well be null, while a
        // client who wrote a number into a queue's match list meant the
        // number their customer dialled.
        $variants = MessagingEndpoint::addressVariants($message->to ?: (string) $endpoint->address);

        $addressMatches = $queues->filter(function (MessageQueue $queue) use ($variants): bool {
            $addresses = (array) ($queue->matched_addresses ?? []);

            if ($addresses === []) {
                return false;
            }

            foreach ($addresses as $candidate) {
                if (in_array((string) $candidate, $variants, true)) {
                    return true;
                }
            }

            return false;
        });

        $protocolAdmits = fn (MessageQueue $queue): bool => (
            ($queue->matched_protocols ?? []) === []
            || in_array($message->protocol, (array) $queue->matched_protocols, true)
        );

        // 1. Named this address AND accepts this protocol.
        if ($match = $addressMatches->first($protocolAdmits)) {
            return $match;
        }

        // 2. No address list, but explicitly accepts this protocol.
        $protocolOnly = $queues->first(fn (MessageQueue $queue): bool => (
            ($queue->matched_addresses ?? []) === []
            && in_array($message->protocol, (array) ($queue->matched_protocols ?? []), true)
        ));

        if ($protocolOnly) {
            return $protocolOnly;
        }

        // 3. A catch-all queue: no address list, no protocol list.
        $catchAll = $queues->first(fn (MessageQueue $queue): bool => (
            ($queue->matched_addresses ?? []) === []
            && ($queue->matched_protocols ?? []) === []
        ));

        if ($catchAll) {
            return $catchAll;
        }

        // 4. The client has exactly one queue and no rule matched. They
        //    plainly meant traffic to land there; requiring a match rule
        //    to express "my only queue" is a trap that ends with
        //    messages sitting unrouted while a queue sits empty.
        return $queues->count() === 1 ? $queues->first() : null;
    }
}
