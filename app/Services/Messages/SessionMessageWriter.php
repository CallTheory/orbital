<?php

declare(strict_types=1);

namespace App\Services\Messages;

use App\Models\CallSessionState;
use App\Models\IntakeGoal;
use App\Models\Message;

/**
 * Turns an AI call session's captured fields into a Message.
 *
 * Lives in a service rather than the controller because three callers
 * need it: the agent worker's field/advance/end endpoints, the LiveKit
 * `room_finished` webhook (for workers that die without cleaning up),
 * and tests.
 */
class SessionMessageWriter
{
    /**
     * Marker written to Message.notes so a session's message can be
     * found again on retry. Exact match, never a LIKE.
     */
    public const SESSION_NOTE_PREFIX = 'Auto-captured from AI call session: ';

    public function __construct(
        private readonly PartialMessagePolicy $policy,
    ) {}

    /**
     * Persist the session's captured fields as a Message when there's
     * enough to be worth persisting.
     *
     * Two ways that happens:
     *
     *   - COMPLETE: name and reason are both present. Written as soon as
     *     they are, mid-call — a client shouldn't have to wait for a
     *     hangup to see a message that's already finished.
     *
     *   - PARTIAL: the call has ENDED with fields still missing, and the
     *     client's policy says keep it. Never written mid-call: while the
     *     caller is on the line the reason may yet arrive, and an early
     *     partial would end up sitting beside the complete version.
     *
     * Idempotent. The worker retries, and the room_finished webhook
     * races the worker's own end call by design.
     */
    public function write(CallSessionState $state): ?Message
    {
        if (! $state->team_id) {
            return null;
        }

        $captured = $this->normalise($state);

        if ($this->policy->isComplete($captured)) {
            return $this->persist($state, [
                'caller_name' => trim((string) $captured['caller_name']),
                'caller_phone' => $captured['caller_phone'],
                'reason' => trim((string) $captured['reason']),
                'status' => Message::STATUS_NEW,
                'urgency' => Message::URGENCY_NORMAL,
                'is_partial' => false,
                'partial_reason' => null,
                'missing_fields' => null,
            ]);
        }

        // Incomplete, and the caller may still be talking.
        if (! $state->hasEnded()) {
            return null;
        }

        if (! $this->policy->keepsPartials($state->team, $this->goalFor($state))) {
            return null;
        }

        if (! $this->policy->isWorthKeeping($captured)) {
            return null;
        }

        return $this->persist(
            $state,
            $this->policy->attributesFor(
                $captured,
                $state->end_reason ?: PartialMessagePolicy::REASON_CALLER_HUNG_UP,
            ),
        );
    }

    /**
     * Normalise whatever the LLM called its fields into the three things
     * a message needs.
     *
     * The agent is told the canonical keys from the intake goal, but
     * models improvise — `name` for `caller_name`, `details` for
     * `reason`. Accepting the common variants is much cheaper than
     * losing a message to a synonym.
     *
     * @return array{caller_name: ?string, caller_phone: ?string, reason: ?string}
     */
    public function normalise(CallSessionState $state): array
    {
        $fields = $state->fields ?? [];

        return [
            'caller_name' => $fields['caller_name']
                ?? $fields['name']
                ?? $fields['recipient']
                ?? $fields['customer_name']
                ?? $fields['caller']
                ?? null,

            'caller_phone' => $fields['caller_phone']
                ?? $fields['phone']
                ?? $fields['callback_number']
                ?? $fields['phone_number']
                ?? $fields['number']
                ?? null,

            'reason' => $fields['reason']
                ?? $fields['message']
                ?? $fields['reason_for_call']
                ?? $fields['notes']
                ?? $fields['details']
                ?? null,
        ];
    }

    /**
     * Write the message, or update the one already written for this
     * session.
     *
     * Keyed on the session, not on the content. The previous
     * implementation matched on caller name + reason text, which broke
     * the moment the agent rephrased something — and would now let a
     * hangup-time partial land beside the complete message it was
     * supposed to replace.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function persist(CallSessionState $state, array $attributes): ?Message
    {
        $note = self::SESSION_NOTE_PREFIX.$state->session_key;

        $existing = Message::withoutGlobalScopes()
            ->where('team_id', $state->team_id)
            ->where('notes', $note)
            ->first();

        if ($existing) {
            // A message already written as complete is never downgraded
            // to a partial by a later hangup event.
            if (! $existing->is_partial && ($attributes['is_partial'] ?? false)) {
                return $existing;
            }

            $existing->fill($attributes)->save();

            return $existing;
        }

        return Message::create($attributes + [
            'team_id' => $state->team_id,
            'agent_persona_id' => $state->agent_persona_id,
            'notes' => $note,
        ]);
    }

    /**
     * The intake goal driving this session — its per-goal override beats
     * the client-level default.
     *
     * Sessions don't currently carry a goal id (the worker reports
     * fields, not flow structure), so this returns null and the
     * client-level policy applies. Kept as an explicit seam rather than
     * dropped: when the worker starts reporting its active goal, this is
     * the only place that needs to change.
     */
    private function goalFor(CallSessionState $state): ?IntakeGoal
    {
        return null;
    }
}
