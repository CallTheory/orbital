<?php

declare(strict_types=1);

namespace App\Services\Telephony\Realtime;

use App\Models\Extension;
use Illuminate\Support\Facades\DB;

/**
 * Translates an Eloquent {@see Extension} into one row each in
 * `ps_endpoints`, `ps_auths`, and `ps_aors`. Asterisk's pjsip
 * sorcery+realtime backend reads these on every call setup, so a
 * sync here is enough to make the endpoint live without any
 * Asterisk reload — that's the whole point of the ARA architecture.
 *
 * Endpoint id is `t{team_id}_{number}` for client extensions
 * (collision-safe across clients) and unprefixed for platform-staff
 * extensions. Same id is used for the auth and aor rows so the
 * three tables join naturally on `id`.
 *
 * Type-specific behavior:
 *   - **webrtc**: webrtc=yes, dtls auto-generated, ice support,
 *     rtcp_mux, transport=transport-wss. Required for SIP.js and
 *     other WebRTC softphones.
 *   - **sip_phone / ata / softphone / staff_softphone**: standard
 *     UDP transport, ulaw/alaw/g722 codecs.
 *   - **ai_agent**: virtual endpoint that acts as a SIP target for
 *     LiveKit's outbound call. Uses the WSS transport so LiveKit
 *     can reach it.
 *   - **virtual**: no real endpoint at all — skipped (the sync is
 *     a no-op so the extension can exist without polluting Asterisk).
 *
 * Idempotent — repeated sync calls update the existing rows.
 */
class EndpointSyncer
{
    /**
     * Write or refresh the ARA rows for a single extension. Skips
     * inactive or `virtual` extensions (those don't have an
     * Asterisk presence). Wraps the three table writes in a
     * transaction so a partial failure can't leave half-applied
     * state behind.
     */
    public function sync(Extension $extension): void
    {
        if (! $extension->is_active || $extension->type === 'virtual') {
            $this->delete($extension);
            return;
        }

        $endpointId = $extension->realtimeEndpointId();
        $isWebrtc = in_array($extension->type, ['webrtc', 'webrtc_client', 'staff_softphone'], true);
        $isAiAgent = $extension->type === 'ai_agent';
        $context = $extension->team_id
            ? 'tenant_'.$extension->team_id
            : ($extension->context ?: 'default');

        // Asterisk transport names are namespaced (`transport-udp`,
        // `transport-wss`, etc.); our Extension table stores the
        // raw protocol (`udp` / `tcp` / `tls` / `wss`). Translate
        // here so callers don't have to remember the prefix.
        //
        // WebRTC + AI-agent endpoints intentionally leave `transport`
        // unset. Asterisk auto-picks the transport from the active
        // client contact for registration-based endpoints — pinning
        // it to `transport-wss` makes the AOR qualify loop log
        // "Unsupported transport" whenever the contact is absent,
        // because WSS is client-initiated and Asterisk can't open
        // an outbound WSS socket to OPTIONS-ping a stale contact.
        $rawTransport = $extension->transport ?: 'udp';
        $endpointRow = [
            'id' => $endpointId,
            'transport' => $isWebrtc || $isAiAgent ? null : 'transport-'.$rawTransport,
            'aors' => $endpointId,
            'auth' => $endpointId,
            'context' => $context,
            'disallow' => 'all',
            'allow' => $isWebrtc ? 'opus,ulaw' : 'ulaw,alaw,g722,opus',
            'direct_media' => 'no',
            'force_rport' => 'yes',
            'rewrite_contact' => 'yes',
            'rtp_symmetric' => 'yes',
            'trust_id_inbound' => 'yes',
            'device_state_busy_at' => '1',
            'dtmf_mode' => 'rfc4733',
            'callerid' => '"'.($extension->label ?? 'Ext '.$extension->number).'" <'.$extension->number.'>',
            'webrtc' => $isWebrtc ? 'yes' : 'no',
        ];

        if ($isWebrtc) {
            $endpointRow['media_encryption'] = 'dtls';
            $endpointRow['dtls_verify'] = 'fingerprint';
            $endpointRow['dtls_setup'] = 'actpass';
            $endpointRow['dtls_auto_generate_cert'] = 'yes';
            $endpointRow['ice_support'] = 'yes';
            $endpointRow['media_use_received_transport'] = 'yes';
            $endpointRow['rtcp_mux'] = 'yes';
            $endpointRow['use_avpf'] = 'yes';
        }

        $authRow = [
            'id' => $endpointId,
            'auth_type' => 'userpass',
            'username' => $extension->sip_username ?: $extension->number,
            'password' => $extension->sip_password ?: 'changeme',
        ];

        $aorRow = [
            'id' => $endpointId,
            'max_contacts' => $isWebrtc ? 5 : 1,
            'qualify_frequency' => 30,
            'remove_existing' => $isWebrtc ? 'no' : 'yes',
            // support_path lets the Asterisk that receives a REGISTER
            // store a Path header pointing at itself, so a sibling
            // Asterisk looking up this contact in the shared ARA
            // table knows to route any inbound INVITE back through
            // the registering node's WSS session. Without this,
            // only the node that handled the REGISTER can ring the
            // endpoint — which breaks the drain model for idle
            // operators on the drained node.
            'support_path' => 'yes',
        ];

        DB::transaction(function () use ($endpointId, $endpointRow, $authRow, $aorRow) {
            $this->upsert('ps_endpoints', $endpointId, $endpointRow);
            $this->upsert('ps_auths', $endpointId, $authRow);
            $this->upsert('ps_aors', $endpointId, $aorRow);
        });
    }

    /**
     * Remove the ARA rows for an extension. Called when an extension
     * is deleted, deactivated, or re-typed to `virtual`. Asterisk
     * picks up the absence on the next call query — no reload.
     */
    public function delete(Extension $extension): void
    {
        $endpointId = $extension->realtimeEndpointId();

        DB::transaction(function () use ($endpointId) {
            DB::table('ps_endpoints')->where('id', $endpointId)->delete();
            DB::table('ps_auths')->where('id', $endpointId)->delete();
            DB::table('ps_aors')->where('id', $endpointId)->delete();
        });
    }

    /**
     * Tiny upsert helper — Postgres-portable insert-or-update keyed
     * by id. Avoids the model layer entirely because ARA tables
     * have no Eloquent model and we want the sync path to be a
     * single SQL round trip per row.
     */
    protected function upsert(string $table, string $id, array $row): void
    {
        $existing = DB::table($table)->where('id', $id)->exists();
        if ($existing) {
            DB::table($table)->where('id', $id)->update($row);
        } else {
            DB::table($table)->insert($row);
        }
    }
}
