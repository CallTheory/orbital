<?php

declare(strict_types=1);

namespace App\Services\Messaging;

use App\Models\MessageQueue;
use App\Models\MessageThread;
use App\Models\MessagingEndpoint;

/**
 * Groups inbound messages into conversations.
 *
 * The email ThreadResolver walks RFC822 Message-ID / In-Reply-To /
 * References headers. Messaging has none of that — there is no header
 * chain, no subject, nothing but two addresses and a timestamp. So a
 * thread is identified by (endpoint, remote address), and the only real
 * decision is when to stop treating new traffic as part of the old
 * conversation.
 *
 * Rules:
 *
 *   - An OPEN thread with this pair always continues, however old.
 *     Someone replying to a conversation nobody closed is continuing it.
 *   - A CLOSED thread reopens if it was closed within the reopen window
 *     (config messaging.thread_reopen_hours, default 72). "Sorry, one
 *     more thing" the next morning belongs to the same conversation.
 *   - Otherwise a new thread starts. A text six weeks after a resolved
 *     complaint is new business, and stapling it onto the old thread
 *     buries it under history the operator has to scroll past.
 *
 * One consequence of sender pools worth being explicit about: an
 * endpoint backed by a pool covers every number in that pool, so a
 * customer who texts two of the client's numbers lands in ONE
 * conversation. That is the intended reading — a pool exists precisely
 * because its numbers are interchangeable, the carrier's sticky sender
 * treats customer↔pool as the unit, and an operator wants one thread
 * per person, not one per number the person happened to dial. A client
 * who needs the traffic genuinely separated needs a separate pool,
 * which is also what the carrier would tell them.
 */
class MessageThreadResolver
{
    public function resolve(
        InboundMessage $message,
        MessagingEndpoint $endpoint,
        ?MessageQueue $queue,
    ): MessageThread {
        $existing = $this->findExisting($message, $endpoint);

        if ($existing) {
            // A closed thread being reopened goes back to `new`, not
            // `in_progress`: whoever handled it last has moved on, and
            // leaving it assigned would hide a live customer message in
            // an off-shift operator's claimed list.
            if ($existing->status === MessageThread::STATUS_CLOSED) {
                $existing->forceFill([
                    'status' => MessageThread::STATUS_NEW,
                    'closed_at' => null,
                    'assigned_operator_id' => null,
                ]);
            } elseif ($existing->status === MessageThread::STATUS_AWAITING_REPLY) {
                // We asked something and they answered. Back into the
                // working set.
                $existing->status = MessageThread::STATUS_IN_PROGRESS;
            }

            // A queue assignment can arrive later than the thread — a
            // client configuring their first queue mid-conversation
            // shouldn't leave that conversation orphaned.
            if ($queue && ! $existing->message_queue_id) {
                $existing->message_queue_id = $queue->id;
            }

            $existing->last_message_at = now();
            $existing->last_inbound_at = now();
            $existing->save();

            return $existing;
        }

        return MessageThread::create([
            'team_id' => $endpoint->team_id,
            'messaging_endpoint_id' => $endpoint->id,
            'message_queue_id' => $queue?->id,
            'remote_address' => $message->from,
            'protocol' => $message->protocol,
            'status' => MessageThread::STATUS_NEW,
            'last_message_at' => now(),
            'last_inbound_at' => now(),
        ]);
    }

    private function findExisting(InboundMessage $message, MessagingEndpoint $endpoint): ?MessageThread
    {
        $variants = MessagingEndpoint::addressVariants($message->from);

        $candidate = MessageThread::withoutGlobalScopes()
            ->where('messaging_endpoint_id', $endpoint->id)
            ->whereIn('remote_address', $variants)
            ->orderByDesc('last_message_at')
            ->first();

        if (! $candidate) {
            return null;
        }

        if ($candidate->status !== MessageThread::STATUS_CLOSED) {
            return $candidate;
        }

        $window = (int) config('messaging.thread_reopen_hours', 72);

        $closedAt = $candidate->closed_at ?? $candidate->last_message_at;

        if ($closedAt === null) {
            return $candidate;
        }

        return $closedAt->greaterThan(now()->subHours($window)) ? $candidate : null;
    }
}
