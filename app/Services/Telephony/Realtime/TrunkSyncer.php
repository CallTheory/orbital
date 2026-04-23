<?php

declare(strict_types=1);

namespace App\Services\Telephony\Realtime;

use App\Models\SipTrunk;
use Illuminate\Support\Facades\DB;

/**
 * Translates a {@see SipTrunk} into the four ARA rows pjsip needs to
 * accept inbound traffic from a SIP provider AND originate outbound
 * calls through it:
 *
 *   - **ps_endpoints**: the trunk endpoint, configured for the
 *     provider's transport / codecs / context (`from-trunk` so
 *     inbound calls land in the global dispatcher).
 *   - **ps_auths**: outbound credentials (`outbound_auth` on the
 *     endpoint references this row by id).
 *   - **ps_aors**: a static AOR whose `contact` is the provider's
 *     SIP URI, so Asterisk knows where to send outbound calls.
 *   - **ps_endpoint_id_ips**: identify-by-IP rule so unauthenticated
 *     inbound traffic from the provider's source IP is matched to
 *     this endpoint (and thus its from-trunk context) instead of
 *     getting rejected as anonymous.
 *
 * Endpoint id is `trunk_{id}` — globally unique because trunks
 * have a global integer PK (no per-client numbering). Client
 * association is implicit via routing rules in the dialplan, not
 * via the trunk endpoint itself.
 */
class TrunkSyncer
{
    public function sync(SipTrunk $trunk): void
    {
        if (! $trunk->is_active) {
            $this->delete($trunk);
            return;
        }

        $endpointId = 'trunk_'.$trunk->id;
        $codecs = $this->codecsAsString($trunk->codecs ?? []);

        $endpointRow = [
            'id' => $endpointId,
            'transport' => 'transport-'.($trunk->transport ?: 'udp'),
            'aors' => $endpointId,
            'auth' => $trunk->username ? $endpointId : null,
            'outbound_auth' => $trunk->username ? $endpointId : null,
            'context' => $trunk->inbound_context ?: 'from-trunk',
            'disallow' => 'all',
            'allow' => $codecs,
            'direct_media' => 'no',
            'force_rport' => 'yes',
            'rewrite_contact' => 'yes',
            'rtp_symmetric' => 'yes',
            'dtmf_mode' => 'rfc4733',
            'identify_by' => 'ip,username',
        ];

        $aorRow = [
            'id' => $endpointId,
            'contact' => 'sip:'.$trunk->host.':'.$trunk->port,
            'qualify_frequency' => 30,
        ];

        $identifyRow = [
            'id' => $endpointId,
            'endpoint' => $endpointId,
            'match' => $trunk->host,
            'srv_lookups' => 'yes',
        ];

        DB::transaction(function () use ($trunk, $endpointId, $endpointRow, $aorRow, $identifyRow) {
            $this->upsert('ps_endpoints', $endpointId, $endpointRow);
            $this->upsert('ps_aors', $endpointId, $aorRow);
            $this->upsert('ps_endpoint_id_ips', $endpointId, $identifyRow);

            if ($trunk->username) {
                $this->upsert('ps_auths', $endpointId, [
                    'id' => $endpointId,
                    'auth_type' => 'userpass',
                    'username' => $trunk->username,
                    'password' => $trunk->password ?: '',
                ]);
            } else {
                DB::table('ps_auths')->where('id', $endpointId)->delete();
            }
        });
    }

    public function delete(SipTrunk $trunk): void
    {
        $endpointId = 'trunk_'.$trunk->id;

        DB::transaction(function () use ($endpointId) {
            DB::table('ps_endpoints')->where('id', $endpointId)->delete();
            DB::table('ps_auths')->where('id', $endpointId)->delete();
            DB::table('ps_aors')->where('id', $endpointId)->delete();
            DB::table('ps_endpoint_id_ips')->where('id', $endpointId)->delete();
        });
    }

    /**
     * Codec list comes off the SipTrunk as an array; ARA wants a
     * comma-separated string. Falls back to a sensible default if
     * the trunk has nothing configured.
     *
     * @param  array<int, string>  $codecs
     */
    protected function codecsAsString(array $codecs): string
    {
        if (empty($codecs)) {
            return 'ulaw,alaw';
        }
        return implode(',', $codecs);
    }

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
