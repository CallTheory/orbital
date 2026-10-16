<?php

declare(strict_types=1);

namespace App\Services\Telephony\Realtime;

use Illuminate\Support\Facades\DB;

/**
 * The PJSIP endpoints every install needs, as realtime rows.
 *
 * sorcery.conf reads endpoints from Postgres only, so the static
 * docker/asterisk/pjsip_livekit.conf is never loaded; without these
 * rows Dial(PJSIP/{ext}@livekit) has no endpoint and AI calls fail.
 *
 *  - `livekit`: the LiveKit SIP bridge. Asterisk dials AI calls to it,
 *    and the AI's transfers come back as REFERs on that leg, so its
 *    context is from-livekit.
 *  - `anonymous` (seeded by a migration): only its context changes,
 *    to telephony.anonymous_context — from-trunk behind the Kamailio
 *    edge, unknown-reject otherwise.
 *
 * Idempotent; run by `orbital:sync-livekit-sip` on every deploy.
 */
class InfrastructureEndpointSyncer
{
    public function sync(): void
    {
        $host = (string) config('telephony.livekit.sip.host', 'livekit-sip');
        $port = (int) config('telephony.livekit.sip.port', 5069);

        DB::transaction(function () use ($host, $port) {
            $this->upsert('ps_endpoints', 'livekit', [
                'transport' => 'transport-udp',
                'aors' => 'livekit',
                'context' => 'from-livekit',
                'disallow' => 'all',
                'allow' => 'ulaw,alaw',
                'direct_media' => 'no',
                'rtp_symmetric' => 'yes',
                'force_rport' => 'yes',
                'dtmf_mode' => 'rfc4733',
            ]);
            $this->upsert('ps_aors', 'livekit', [
                'contact' => "sip:{$host}:{$port}",
            ]);

            DB::table('ps_endpoints')->where('id', 'anonymous')->update([
                'context' => (string) config('telephony.anonymous_context', 'unknown-reject'),
            ]);
        });
    }

    /** @param array<string, mixed> $row */
    protected function upsert(string $table, string $id, array $row): void
    {
        DB::table($table)->updateOrInsert(['id' => $id], $row);
    }
}
