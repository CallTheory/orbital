<?php

declare(strict_types=1);

namespace App\Services\Telephony;

use App\Models\AgentPersona;
use App\Models\Extension;
use App\Models\IntakeFlow;
use App\Models\RoutingRule;

/**
 * What an AI call was dialled to, worked out from the number Asterisk
 * dialled on the livekit endpoint (the worker reads it back out of the
 * room name, agent-_{target}_{random}):
 *
 *   1001 — an AI extension; its persona answers.
 *   r42  — routing rule 42, a DID sent to an orchestration's Inbound
 *          Phone channel; the persona set on that channel answers.
 */
final class AiCallTarget
{
    public const RULE_PREFIX = 'r';

    private function __construct(
        public readonly int $teamId,
        public readonly AgentPersona $persona,
        public readonly ?Extension $extension = null,
        public readonly ?RoutingRule $rule = null,
    ) {}

    /** The livekit dial target for a DID routed to an orchestration. */
    public static function forRule(RoutingRule $rule): string
    {
        return self::RULE_PREFIX.$rule->id;
    }

    public static function resolve(?string $dialled): ?self
    {
        if ($dialled === null || $dialled === '') {
            return null;
        }

        if (preg_match('/^'.self::RULE_PREFIX.'(\d+)$/', $dialled, $m)) {
            return self::fromRule((int) $m[1]);
        }

        $extension = Extension::withoutGlobalScopes()
            ->where('number', $dialled)
            ->where('type', 'ai_agent')
            ->where('is_active', true)
            ->first();

        $persona = $extension?->assignable;
        if (! $persona instanceof AgentPersona) {
            return null;
        }

        return new self((int) $extension->team_id, $persona, extension: $extension);
    }

    private static function fromRule(int $ruleId): ?self
    {
        $rule = RoutingRule::withoutGlobalScopes()
            ->where('is_active', true)
            ->where('destination_type', ClientDidRouting::DESTINATION_ORCHESTRATION)
            ->find($ruleId);

        $channel = $rule?->intake_flow_id
            ? IntakeFlow::withoutGlobalScope('team')->find($rule->intake_flow_id)
            : null;

        $persona = $channel?->agent_persona_id
            ? AgentPersona::withoutGlobalScopes()
                ->where('team_id', $rule->team_id)
                ->where('is_active', true)
                ->find($channel->agent_persona_id)
            : null;

        return $persona ? new self((int) $rule->team_id, $persona, rule: $rule) : null;
    }
}
