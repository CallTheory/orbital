<?php

declare(strict_types=1);

namespace App\Services\Messaging;

use App\Models\AgentPersona;
use App\Models\MessageEntry;
use App\Models\MessageThread;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Sends a reply on a message thread and records it.
 *
 * Sibling of App\Services\Mail\OutboundReplyService. The important
 * difference: a failed send is RECORDED, not thrown away. SMTP either
 * accepts a mail or doesn't; SMS can be accepted and then quietly fail
 * at the handset minutes later. An operator who can't see that their
 * reply never landed will assume the customer was told something they
 * were never told, which is the single worst failure this channel has.
 *
 * So every attempt produces a MessageEntry, and its delivery_status
 * carries the truth as we currently know it — updated later by the
 * provider's delivery receipt through InboundMessageController.
 *
 * This is also the chokepoint for the suppression list. Every outbound
 * path — operator reply, AI reply, anything added later — comes through
 * send(), so the opt-out check sits here exactly once. Putting it in
 * the callers instead would mean the next caller is the one that
 * forgets.
 */
class OutboundMessageService
{
    public function __construct(
        private readonly ProviderRegistry $registry,
        private readonly OptOutRegistry $optOuts,
    ) {}

    /**
     * Reply on a thread as an operator.
     *
     * @param  array<int, string>  $media
     */
    public function replyAsOperator(
        MessageThread $thread,
        User $user,
        string $body,
        array $media = [],
    ): MessageEntry {
        return $this->send($thread, $body, $media, sentByUser: $user);
    }

    /**
     * Reply on a thread as an AI persona.
     *
     * @param  array<int, string>  $media
     */
    public function replyAsAgent(
        MessageThread $thread,
        AgentPersona $persona,
        string $body,
        array $media = [],
    ): MessageEntry {
        return $this->send($thread, $body, $media, persona: $persona);
    }

    /**
     * @param  array<int, string>  $media
     */
    private function send(
        MessageThread $thread,
        string $body,
        array $media = [],
        ?User $sentByUser = null,
        ?AgentPersona $persona = null,
    ): MessageEntry {
        // Consent first, before the endpoint is even resolved. A
        // customer who texted STOP is not someone we send to and then
        // apologise about — the send itself is the violation, and on a
        // US long code it is the kind that ends with the number
        // de-registered rather than with a complaint.
        if ($this->optOuts->isThreadSuppressed($thread)) {
            Log::warning('outbound message blocked by opt-out', [
                'thread' => $thread->id,
                'team_id' => $thread->team_id,
            ]);

            return $this->record(
                $thread,
                $body,
                $media,
                SendResult::failed(
                    'This person has opted out of text messages from this client, so nothing was sent. '
                    .'They can opt back in by texting START.'
                ),
                $thread->endpoint?->provider ?? 'unknown',
                $sentByUser,
                $persona,
            );
        }

        $endpoint = $thread->endpoint;

        if (! $endpoint) {
            // The endpoint was deleted out from under a live thread.
            // Record the failure rather than throwing so the operator
            // sees why their reply didn't go.
            return $this->record(
                $thread,
                $body,
                $media,
                SendResult::failed('This conversation\'s messaging number no longer exists.'),
                'unknown',
                $sentByUser,
                $persona,
            );
        }

        try {
            $provider = $this->registry->get($endpoint->provider);
        } catch (\InvalidArgumentException $e) {
            return $this->record(
                $thread,
                $body,
                $media,
                SendResult::failed($e->getMessage()),
                $endpoint->provider,
                $sentByUser,
                $persona,
            );
        }

        $result = $provider->send(
            from: (string) $endpoint->address,
            to: $thread->remote_address,
            body: $body,
            // providerOptions() folds the endpoint's sender pool in
            // under a fixed key, so a driver that requires a pool can
            // refuse rather than quietly falling back to a bare number.
            options: $endpoint->providerOptions(),
            media: $media,
        );

        if (! $result->accepted) {
            Log::warning('outbound message rejected by provider', [
                'thread' => $thread->id,
                'provider' => $endpoint->provider,
                'error' => $result->error,
            ]);
        }

        $entry = $this->record($thread, $body, $media, $result, $endpoint->provider, $sentByUser, $persona, $result->sender);

        // We've said something and are now waiting on them. Only move a
        // thread forward on a successful send — marking a failed reply
        // as "awaiting reply" would park a customer who was never
        // actually contacted in the least-watched status there is.
        if ($result->accepted) {
            $thread->forceFill([
                'status' => MessageThread::STATUS_AWAITING_REPLY,
                'last_message_at' => now(),
            ])->save();
        }

        return $entry;
    }

    /**
     * @param  array<int, string>  $media
     */
    private function record(
        MessageThread $thread,
        string $body,
        array $media,
        SendResult $result,
        string $provider,
        ?User $sentByUser,
        ?AgentPersona $persona,
        ?string $sender = null,
    ): MessageEntry {
        return MessageEntry::create([
            'message_thread_id' => $thread->id,
            'team_id' => $thread->team_id,
            'direction' => MessageEntry::DIRECTION_OUTBOUND,
            // What the carrier actually sent from, when it told us. With
            // a sender pool the number is the pool's choice, not ours,
            // and recording our guess instead would show the operator a
            // conversation the customer never had.
            'from_address' => $sender ?? $thread->endpoint?->address ?? '',
            'to_address' => $thread->remote_address,
            'body' => $body,
            'media' => $media ? array_map(fn (string $url): array => ['url' => $url, 'content_type' => null], $media) : null,
            'provider' => $provider,
            'provider_message_id' => $result->providerMessageId,
            'delivery_status' => $result->status,
            'delivery_error' => $result->error,
            'sent_by_user_id' => $sentByUser?->id,
            'agent_persona_id' => $persona?->id,
            'occurred_at' => now(),
        ]);
    }
}
