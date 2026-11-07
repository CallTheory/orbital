<?php

declare(strict_types=1);

namespace App\Services\Telephony;

use App\Models\ClientDid;
use App\Models\IntakeFlow;
use App\Models\RoutingRule;

/**
 * Keeps the RoutingRule behind a DID in step with the DID's own
 * destination (extension number, queue id, voicemail mailbox, or the
 * orchestration whose Inbound Phone channel takes the call). Saving
 * the rule fires TelephonyObserver, which regenerates the from-trunk
 * dispatcher and reloads the dialplan.
 */
class ClientDidRouting
{
    public const DESTINATION_ORCHESTRATION = 'orchestration';

    public const DESTINATION_TYPES = ['extension', 'queue', 'voicemail', self::DESTINATION_ORCHESTRATION];

    /**
     * The Inbound Phone channel of one of the client's orchestrations,
     * where a DID sent to that orchestration lands.
     */
    public static function inboundPhoneChannel(int $teamId, int $orchestrationId): ?IntakeFlow
    {
        return IntakeFlow::withoutGlobalScope('team')
            ->where('team_id', $teamId)
            ->where('orchestration_id', $orchestrationId)
            ->where('trigger_type', IntakeFlow::TRIGGER_INBOUND_PHONE)
            ->orderBy('id')
            ->first();
    }

    public function remove(ClientDid $did): void
    {
        RoutingRule::withoutGlobalScopes()->withTrashed()
            ->where('client_did_id', $did->id)
            ->get()
            ->each->forceDelete();
    }

    public function sync(ClientDid $did): void
    {
        $rule = RoutingRule::withoutGlobalScopes()->withTrashed()
            ->where('client_did_id', $did->id)
            ->first();

        $channel = $did->destination_type === self::DESTINATION_ORCHESTRATION && $did->destination_id !== null
            ? self::inboundPhoneChannel((int) $did->team_id, (int) $did->destination_id)
            : null;

        $routed = $did->is_active
            && in_array($did->destination_type, self::DESTINATION_TYPES, true)
            && $did->destination_id !== null
            && ($did->destination_type !== self::DESTINATION_ORCHESTRATION || $channel !== null);

        if (! $routed) {
            // Hard delete: the dispatcher reads rules without global
            // scopes, so a soft-deleted rule would keep routing.
            $rule?->forceDelete();

            return;
        }

        $attributes = [
            'team_id' => $did->team_id,
            'name' => 'DID '.$did->number,
            'sip_trunk_id' => $did->sip_trunk_id,
            'match_type' => 'did',
            'match_pattern' => $did->number,
            'destination_type' => $did->destination_type,
            'destination_id' => $did->destination_id,
            'intake_flow_id' => $channel?->id,
            'priority' => 10,
            'is_active' => true,
            'source' => 'did',
        ];

        if ($rule) {
            if ($rule->trashed()) {
                $rule->restore();
            }
            $rule->fill($attributes)->save();
        } else {
            RoutingRule::withoutGlobalScopes()->create($attributes + ['client_did_id' => $did->id]);
        }
    }
}
