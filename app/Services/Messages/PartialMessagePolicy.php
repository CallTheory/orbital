<?php

declare(strict_types=1);

namespace App\Services\Messages;

use App\Models\IntakeGoal;
use App\Models\Message;
use App\Models\Team;

/**
 * Decides what happens to a half-collected message when the caller
 * drops off before the script finishes.
 *
 * Two questions, deliberately kept separate:
 *
 *   1. keepsPartials()  — is this client willing to store partials at
 *      all? Client-level default, overridable per intake goal.
 *   2. isWorthKeeping() — is there actually enough here to act on?
 *
 * Both have to be true. A client can opt in to partial retention and
 * still not want a row containing nothing but a timestamp — "the caller
 * hung up before saying anything" is not a message, it's an abandoned
 * call, and the call log already records that.
 *
 * Applies identically to the AI flow runner and the human operator
 * console: same policy, same threshold, same reasons. An answering
 * service can't have "we keep partials, except when a human took it".
 */
class PartialMessagePolicy
{
    /** Reasons a message ended up partial. */
    public const REASON_CALLER_HUNG_UP = 'caller_hung_up';

    public const REASON_SESSION_ABANDONED = 'session_abandoned';

    public const REASON_OPERATOR_SAVED = 'operator_saved_partial';

    /**
     * Does this client keep partial messages?
     *
     * The goal-level column is three-state: null inherits from the
     * client, true/false override it. That matters — without "inherit",
     * every newly created goal would silently opt out of a client-wide
     * policy the moment it was saved with a default of false.
     */
    public function keepsPartials(?Team $team, ?IntakeGoal $goal = null): bool
    {
        if ($goal !== null && $goal->keep_partial_messages !== null) {
            return (bool) $goal->keep_partial_messages;
        }

        return (bool) ($team?->keep_partial_messages ?? false);
    }

    /**
     * Is there enough captured to be worth storing?
     *
     * The bar is "could a human follow up on this" — which means a way
     * to reach the caller (a phone number), or at minimum a name plus
     * something they said. A name on its own, with no number and no
     * reason, is not actionable and just clutters the client's inbox.
     *
     * @param  array<string, mixed>  $captured  normalised name/phone/reason
     */
    public function isWorthKeeping(array $captured): bool
    {
        $name = self::clean($captured['caller_name'] ?? null);
        $phone = self::clean($captured['caller_phone'] ?? null);
        $reason = self::clean($captured['reason'] ?? null);

        if ($phone !== null) {
            return true;
        }

        return $name !== null && $reason !== null;
    }

    /**
     * Which of the fields a complete message needs are still missing.
     * Stored on the row so the portal can say "no reason given" rather
     * than showing an unexplained blank.
     *
     * @param  array<string, mixed>  $captured
     * @return array<int, string>
     */
    public function missingFields(array $captured): array
    {
        $missing = [];

        foreach (['caller_name', 'caller_phone', 'reason'] as $field) {
            if (self::clean($captured[$field] ?? null) === null) {
                $missing[] = $field;
            }
        }

        return $missing;
    }

    public function isComplete(array $captured): bool
    {
        return self::clean($captured['caller_name'] ?? null) !== null
            && self::clean($captured['reason'] ?? null) !== null;
    }

    /**
     * Build the Message attributes for a partial capture. Callers merge
     * this into their own team/persona/user context.
     *
     * The placeholder reason is written into the row rather than left
     * null because `reason` is NOT NULL and, more importantly, because
     * whoever reads this in the portal needs to be told what happened.
     * A blank reason reads as an operator who forgot; "Caller hung up
     * before giving a reason" reads as what it was.
     *
     * @param  array<string, mixed>  $captured
     * @return array<string, mixed>
     */
    public function attributesFor(array $captured, string $reason): array
    {
        $missing = $this->missingFields($captured);

        return [
            'caller_name' => self::clean($captured['caller_name'] ?? null) ?? 'Unknown caller',
            'caller_phone' => self::clean($captured['caller_phone'] ?? null),
            'reason' => self::clean($captured['reason'] ?? null) ?? self::placeholderFor($reason),
            'status' => Message::STATUS_NEW,
            'urgency' => Message::URGENCY_NORMAL,
            'is_partial' => true,
            'partial_reason' => $reason,
            'missing_fields' => $missing,
        ];
    }

    private static function placeholderFor(string $reason): string
    {
        return match ($reason) {
            self::REASON_CALLER_HUNG_UP => 'Caller hung up before giving a reason.',
            self::REASON_SESSION_ABANDONED => 'Call ended before the reason was captured.',
            self::REASON_OPERATOR_SAVED => 'Operator saved this before the reason was captured.',
            default => 'Reason not captured.',
        };
    }

    private static function clean(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
