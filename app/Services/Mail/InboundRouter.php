<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Models\EmailMessage;
use App\Models\EmailRoutingRule;
use App\Models\Team;
use Illuminate\Support\Facades\Log;

/**
 * Matches an inbound email to a client + destination.
 *
 * Flow (called from ProcessInboundEmailJob after the MIME
 * parse has populated header + body fields on the message):
 *
 *   1. Pull the recipient addresses from the RFC822 envelope
 *      metadata stamped by InboundMailController
 *   2. For each recipient, parse the local-part into
 *      account_number + function via LocalPartParser
 *   3. First recipient whose account_number resolves to a
 *      real client wins. The resolved `Team` becomes the
 *      message's `team_id`.
 *   4. Evaluate that client's active `EmailRoutingRule` rows:
 *      - `function` rules first (highest specificity) when
 *        the local-part had a function suffix
 *      - `from_pattern` and `subject_pattern` rules apply
 *        regardless of whether a function was present
 *      - `default` catches anything that didn't match
 *      Order is by `priority` ASC, then by id.
 *   5. Stamp the result on the message and return.
 *
 * The router never sends, deletes, or transforms messages —
 * it only decides who owns the message and which destination
 * applies. The job then hands off to downstream consumers
 * (queue, operator, AI) based on the stamped destination.
 */
class InboundRouter
{
    public function __construct(
        private readonly LocalPartParser $parser,
    ) {}

    /**
     * Result shape returned to the job:
     *
     *   team_id          — resolved client, or null if unrouted
     *   destination_type — queue/operator/agent_persona/discard/null
     *   destination_id   — pointer, or null
     *   matched_rule_id  — the EmailRoutingRule that fired
     *   function         — the function string from the local-part
     *   status           — routed|unrouted (final routing_status)
     *
     * @return array{team_id: ?int, destination_type: ?string, destination_id: ?int, matched_rule_id: ?int, function: ?string, status: string}
     */
    public function route(EmailMessage $message): array
    {
        $envelope = $this->envelopeRecipients($message);
        if (empty($envelope)) {
            Log::warning('inbound router: no envelope recipients', ['id' => $message->id]);

            return $this->unrouted();
        }

        // Walk every recipient and take the first one whose
        // account_number maps to a real client. The rest of the
        // recipients (Cc, Bcc, group aliases) are informational.
        foreach ($envelope as $address) {
            $parts = $this->parser->parse($address);
            if ($parts['account_number'] === null) {
                continue;
            }

            $team = Team::query()
                ->where('account_number', $parts['account_number'])
                ->where('personal_team', false)
                ->first();
            if (! $team) {
                continue;
            }

            $rule = $this->matchRule($team->id, $parts['function'], $message);

            return [
                'team_id' => $team->id,
                'destination_type' => $rule?->destination_type,
                'destination_id' => $rule?->destination_id,
                'matched_rule_id' => $rule?->id,
                'function' => $parts['function'],
                'status' => $rule ? 'routed' : 'unrouted',
            ];
        }

        return $this->unrouted();
    }

    /**
     * Route a message into a specific client's rules — used by the
     * admin "Assign to Client" action when manually routing an
     * unrouted message. Skips client resolution (the admin already
     * chose the client) and goes straight to rule matching.
     *
     * @return array{destination_type: ?string, destination_id: ?int, matched_rule_id: ?int}
     */
    public function routeForTeam(EmailMessage $message, Team $team): array
    {
        $rule = $this->matchRule($team->id, null, $message);

        return [
            'destination_type' => $rule?->destination_type,
            'destination_id' => $rule?->destination_id,
            'matched_rule_id' => $rule?->id,
        ];
    }

    /**
     * Extract the list of envelope recipient addresses from the
     * message's metadata. Falls back to parsed `to_addresses` if
     * the envelope wasn't stamped (shouldn't happen in production
     * — the webhook controller always stamps it — but the guard
     * keeps tests resilient).
     *
     * @return array<int, string>
     */
    private function envelopeRecipients(EmailMessage $message): array
    {
        $metadata = $message->metadata ?? [];
        $envelope = $metadata['envelope_to'] ?? null;
        if (is_array($envelope) && ! empty($envelope)) {
            return array_values(array_map('strval', $envelope));
        }

        // Fallback: the parsed To: header, normalized to an
        // array of address strings by ProcessInboundEmailJob.
        $parsed = $message->to_addresses ?? [];

        return array_values(array_filter(array_map(
            fn ($r) => is_array($r) ? ($r['address'] ?? null) : (is_string($r) ? $r : null),
            $parsed,
        )));
    }

    /**
     * Walk a client's active routing rules in priority order and
     * return the first one that matches this message. Returns
     * null if nothing matches.
     */
    private function matchRule(int $teamId, ?string $function, EmailMessage $message): ?EmailRoutingRule
    {
        $rules = EmailRoutingRule::query()
            ->where('team_id', $teamId)
            ->where('is_active', true)
            ->orderBy('priority')
            ->orderBy('id')
            ->get();

        foreach ($rules as $rule) {
            if ($this->ruleMatches($rule, $function, $message)) {
                return $rule;
            }
        }

        return null;
    }

    private function ruleMatches(EmailRoutingRule $rule, ?string $function, EmailMessage $message): bool
    {
        switch ($rule->match_type) {
            case EmailRoutingRule::MATCH_FUNCTION:
                // Exact match against the function suffix. If the
                // recipient didn't have a function, function-type
                // rules can't fire.
                return $function !== null && $function === $rule->match_pattern;

            case EmailRoutingRule::MATCH_FROM_PATTERN:
                return $this->regexMatch($rule->match_pattern, $message->from_address ?? '');

            case EmailRoutingRule::MATCH_SUBJECT_PATTERN:
                return $this->regexMatch($rule->match_pattern, $message->subject ?? '');

            case EmailRoutingRule::MATCH_DEFAULT:
                // Catch-all. Always matches — the priority sort
                // guarantees it runs last for its priority tier.
                return true;
        }

        return false;
    }

    /**
     * Safe regex match — wraps pattern in delimiters if the
     * admin didn't and suppresses PCRE warnings so a malformed
     * rule pattern can't break the whole routing pass.
     */
    private function regexMatch(?string $pattern, string $subject): bool
    {
        if ($pattern === null || $pattern === '') {
            return false;
        }
        // Allow admins to write bare patterns without `/.../`
        // delimiters. If the first char isn't an obvious
        // delimiter, wrap it.
        $first = substr($pattern, 0, 1);
        if (! in_array($first, ['/', '#', '~', '@'], true)) {
            $pattern = '/'.str_replace('/', '\/', $pattern).'/i';
        }
        $result = @preg_match($pattern, $subject);

        return $result === 1;
    }

    /**
     * @return array{team_id: null, destination_type: null, destination_id: null, matched_rule_id: null, function: null, status: string}
     */
    private function unrouted(): array
    {
        return [
            'team_id' => null,
            'destination_type' => null,
            'destination_id' => null,
            'matched_rule_id' => null,
            'function' => null,
            'status' => 'unrouted',
        ];
    }
}
