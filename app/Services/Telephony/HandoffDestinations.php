<?php

declare(strict_types=1);

namespace App\Services\Telephony;

use App\Models\AgentPersona;
use App\Models\CallSessionState;
use App\Models\ClientDid;
use App\Models\IntakeFlow;
use App\Models\IntakeFlowStep;
use App\Models\SipTrunk;
use App\Support\PhoneNumber;
use Illuminate\Support\Collection;

/**
 * Outside phone numbers the AI may hand a caller off to.
 *
 * A handoff number is a Transfer Call step whose destination type is
 * `external`, on one of the client's flows. Only those numbers can be
 * dialled: the AI's transfer_to_operator tool takes no number (Laravel
 * picks it), and the from-livekit dialplan only has an entry per
 * configured (client, number) — so a caller can't talk the AI, or a
 * forged REFER, into dialling anywhere else.
 */
class HandoffDestinations
{
    /**
     * Every configured handoff, optionally for one client.
     *
     * @return Collection<int, array{team_id: int, number: string, flow_id: int, orchestration_id: ?int}>
     */
    public function all(?int $teamId = null): Collection
    {
        return IntakeFlowStep::query()
            ->whereHas('intakeGoal', fn ($q) => $q->where('key', 'transfer_call'))
            ->get()
            ->filter(fn (IntakeFlowStep $step) => ($step->step_params['destination_type'] ?? null) === 'external')
            ->map(function (IntakeFlowStep $step) {
                $flow = IntakeFlow::withoutGlobalScopes()->find($step->flow_id);

                return [
                    'team_id' => (int) ($flow?->team_id ?? 0),
                    'number' => (string) PhoneNumber::toE164((string) ($step->step_params['destination'] ?? '')),
                    'flow_id' => (int) $step->flow_id,
                    'orchestration_id' => $flow?->orchestration_id,
                ];
            })
            ->filter(fn (array $d) => $d['team_id'] > 0 && $d['number'] !== '')
            ->when($teamId !== null, fn (Collection $c) => $c->where('team_id', $teamId))
            ->unique(fn (array $d) => $d['team_id'].'|'.$d['number'])
            ->values();
    }

    /**
     * The number a session hands off to: one configured in the persona's
     * flow, else in that flow's orchestration, else the client's first.
     */
    public function forSession(CallSessionState $session): ?string
    {
        if (! $session->team_id) {
            return null;
        }

        $candidates = $this->all($session->team_id);

        $flowId = $session->agent_persona_id
            ? AgentPersona::withoutGlobalScopes()->whereKey($session->agent_persona_id)->value('default_flow_id')
            : null;
        $orchestrationId = $flowId
            ? IntakeFlow::withoutGlobalScopes()->whereKey($flowId)->value('orchestration_id')
            : null;

        // Templates applied to the same client share its Default
        // orchestration, so the persona's own flow comes first.
        $preferred = ($flowId ? $candidates->firstWhere('flow_id', (int) $flowId) : null)
            ?? ($orchestrationId ? $candidates->firstWhere('orchestration_id', $orchestrationId) : null);

        return ($preferred ?? $candidates->first())['number'] ?? null;
    }

    /** The from-livekit extension that dials a handoff: handoff-{team}-{digits}. */
    public function dialplanExtension(int $teamId, string $e164): string
    {
        return 'handoff-'.$teamId.'-'.ltrim($e164, '+');
    }

    /**
     * The carrier trunk a client's handoffs leave through: the trunk of
     * one of its DIDs, else any active trunk.
     */
    public function trunkFor(int $teamId): ?SipTrunk
    {
        $trunkId = ClientDid::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('is_active', true)
            ->whereNotNull('sip_trunk_id')
            ->value('sip_trunk_id');

        $query = SipTrunk::withoutGlobalScopes()->where('is_active', true);

        return ($trunkId ? (clone $query)->whereKey($trunkId)->first() : null) ?? $query->orderBy('id')->first();
    }
}
