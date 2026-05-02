<?php

declare(strict_types=1);

namespace App\Services\Telephony\Realtime;

use App\Models\SipTrunk;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The "no Asterisk → carrier direct path" invariant detector.
 *
 * Phase 1a's load-bearing safety guarantee is that every outbound
 * INVITE traverses Kamailio + rtpengine on the way to a carrier.
 * If any Asterisk trunk endpoint is missing the
 * `outbound_proxy=sip:kamailio:...;lr` setting, that trunk's calls
 * bypass the edge and silently go un-recorded.
 *
 * The trunk's `ps_aors.contact` legitimately holds the carrier
 * address — that's the SIP R-URI, the carrier needs to address
 * itself. The safety property is on the endpoint's `outbound_proxy`:
 * with `;lr` (loose-routing) Asterisk hands every outbound INVITE
 * to Kamailio first, with R-URI still set to the carrier so
 * downstream routing stays correct.
 *
 * The auditor is a pure detector — it doesn't modify endpoints.
 * Remediation lives in `TrunkSyncer`, which auto-stamps
 * `outbound_proxy` on every endpoint it writes. This auditor
 * catches drift (manually-edited rows, stale rows from before
 * the outbound_proxy was added, etc.) and surfaces them as a
 * red Health card.
 */
class OutboundTrunkAuditor
{
    /**
     * Configured outbound proxy URI all trunk endpoints must
     * carry. Defaults match `TrunkSyncer`'s default; deployments
     * with multiple Kamailio sets use `telephony.outbound_proxy`
     * to override.
     */
    protected function expectedOutboundProxy(): string
    {
        return (string) config('telephony.outbound_proxy', 'sip:kamailio:5060;lr');
    }

    /**
     * Find every outbound trunk endpoint whose `outbound_proxy`
     * is missing or different from the configured edge value.
     * Returns one row per leaking trunk — empty array means the
     * invariant holds.
     *
     * @return array<int, array{
     *     trunk_id: int|null,
     *     trunk_name: string|null,
     *     endpoint_id: string,
     *     outbound_proxy: string,
     *     contact_host: string|null,
     * }>
     */
    public function findLeaks(): array
    {
        $expected = $this->expectedOutboundProxy();
        $leaks = [];

        $rows = DB::table('ps_endpoints')
            ->leftJoin('ps_aors', 'ps_aors.id', '=', 'ps_endpoints.id')
            ->select([
                'ps_endpoints.id',
                'ps_endpoints.outbound_proxy',
                'ps_aors.contact',
            ])
            ->whereLike('ps_endpoints.id', 'trunk_%')
            ->get();

        foreach ($rows as $row) {
            $proxy = (string) ($row->outbound_proxy ?? '');
            if ($proxy === $expected) {
                continue;
            }

            $leaks[] = [
                'trunk_id' => $this->trunkIdFromEndpointId((string) $row->id),
                'trunk_name' => $this->trunkNameFromEndpointId((string) $row->id),
                'endpoint_id' => (string) $row->id,
                'outbound_proxy' => $proxy === '' ? '(none)' : $proxy,
                'contact_host' => $this->extractContactHost((string) ($row->contact ?? '')),
            ];
        }

        if ($leaks !== []) {
            Log::warning('outbound-trunk auditor: leaks found', [
                'count' => count($leaks),
                'expected_proxy' => $expected,
                'leaks' => $leaks,
            ]);
        }

        return $leaks;
    }

    /**
     * Pull the host out of a `sip:host:port` / `sip:user@host:port`
     * AOR contact value, used for the "leaking trunk → carrier X"
     * summary line on the Health card.
     */
    protected function extractContactHost(string $contact): ?string
    {
        if (! preg_match('/^sip:(?:[^@]+@)?(?<host>[^:;\/?>]+)/i', $contact, $m)) {
            return null;
        }

        return strtolower($m['host']);
    }

    protected function trunkIdFromEndpointId(string $endpointId): ?int
    {
        if (! preg_match('/^trunk_(\d+)$/', $endpointId, $m)) {
            return null;
        }

        return (int) $m[1];
    }

    protected function trunkNameFromEndpointId(string $endpointId): ?string
    {
        $id = $this->trunkIdFromEndpointId($endpointId);
        if ($id === null) {
            return null;
        }

        return SipTrunk::query()
            ->withoutGlobalScope('team')
            ->where('id', $id)
            ->value('name');
    }
}
