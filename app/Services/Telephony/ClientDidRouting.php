<?php

declare(strict_types=1);

namespace App\Services\Telephony;

use App\Models\ClientDid;
use App\Models\RoutingRule;

/**
 * Keeps the RoutingRule behind a DID in step with the DID's own
 * destination (extension number, queue id or voicemail mailbox). Saving
 * the rule fires TelephonyObserver, which regenerates the from-trunk
 * dispatcher and reloads the dialplan.
 */
class ClientDidRouting
{
    public const DESTINATION_TYPES = ['extension', 'queue', 'voicemail'];

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

        $routed = $did->is_active
            && in_array($did->destination_type, self::DESTINATION_TYPES, true)
            && $did->destination_id !== null;

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
