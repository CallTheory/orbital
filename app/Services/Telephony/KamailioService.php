<?php

declare(strict_types=1);

namespace App\Services\Telephony;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Client for Kamailio's JSON-RPC management interface.
 *
 * Wraps HTTP calls to the `jsonrpcs` + `xhttp` endpoint that
 * Kamailio exposes at `/jsonrpc` on port 8090. Used by the
 * SipProxy Filament page to show real-time proxy status and
 * control call draining, and by SystemHealthService for the
 * Kamailio health probe.
 *
 * Every method catches network exceptions and returns a safe
 * default (false / 0 / empty array) so callers don't need
 * try/catch for routine UI rendering. Failures are logged as
 * warnings — the health probe and UI both degrade gracefully
 * to "unreachable" rather than crashing.
 *
 * Dispatcher state vocabulary:
 *   active  → RPC state "ap" — accepts new calls, probing on
 *   drain   → RPC state "dp" — operator-disabled, probing on.
 *             Stops new calls, existing dialogs finish naturally,
 *             and the node STAYS disabled until an operator
 *             activates it again — which is what we want for
 *             planned maintenance.
 *   disable → RPC state "dx" — fully disabled, no probing either.
 *
 * Why `dp` for drain and not `ip`:
 *   Kamailio's `i` (inactive) state gets auto-cleared on the next
 *   successful SIP OPTIONS probe when `ds_probing_mode=1`. That
 *   flips the backend back to active ~30 seconds after a drain,
 *   which defeats the point of draining for maintenance. The `d`
 *   (disabled) state is operator-set and sticky: probing keeps
 *   running so we can watch the node come back up, but the
 *   disabled flag doesn't auto-clear. Operator must explicitly
 *   activate to bring it back into rotation.
 */
class KamailioService
{
    /** @var list<string> Non-empty JSON-RPC endpoints, one per Kamailio node. */
    private array $jsonrpcUrls;

    private const TIMEOUT_SECONDS = 2;

    public function __construct()
    {
        $urls = (array) config('telephony.kamailio.jsonrpc_urls', []);
        if (empty($urls)) {
            // Back-compat path: fall back to the single-URL legacy
            // config value so existing installs don't break when
            // KAMAILIO_JSONRPC_URLS isn't set.
            $single = (string) config('telephony.kamailio.jsonrpc_url');
            $urls = $single === '' ? [] : [$single];
        }
        $this->jsonrpcUrls = array_values(array_filter($urls, fn ($u) => is_string($u) && $u !== ''));
    }

    public function isHealthy(): bool
    {
        $result = $this->rpc('core.uptime');

        return $result !== null && isset($result['uptime']);
    }

    /**
     * @return array{uptime: int, uptime_str: string}|null
     */
    public function getUptime(): ?array
    {
        $result = $this->rpc('core.uptime');
        if ($result === null) {
            return null;
        }

        return [
            'uptime' => (int) ($result['uptime'] ?? 0),
            'uptime_str' => (string) ($result['up_since'] ?? ''),
        ];
    }

    /**
     * Returns the dispatcher set entries with their runtime state.
     *
     * Each entry has: SET, DEST (sip:host:port), FLAGS, PRIORITY,
     * ATTRS, and critically DSTATE — the runtime state that changes
     * when the admin drains/activates a backend.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listDispatchers(): array
    {
        $result = $this->rpc('dispatcher.list');
        if ($result === null) {
            return [];
        }

        // Kamailio 5.5 dispatcher.list returns:
        //   result.RECORDS[].SET.ID                   — set id
        //   result.RECORDS[].SET.TARGETS[].DEST.URI   — backend address
        //   result.RECORDS[].SET.TARGETS[].DEST.FLAGS — state flags (AP/IP/DX)
        //   result.RECORDS[].SET.TARGETS[].DEST.ATTRS — {BODY, WEIGHT, ...}
        $records = $result['RECORDS'] ?? [];
        if (! is_array($records)) {
            return [];
        }

        $entries = [];
        foreach ($records as $record) {
            $set = $record['SET'] ?? [];
            $setId = (int) ($set['ID'] ?? 0);
            $targets = $set['TARGETS'] ?? [];
            if (! is_array($targets)) {
                continue;
            }
            foreach ($targets as $target) {
                $dest = $target['DEST'] ?? [];
                $entries[] = [
                    'set_id' => $setId,
                    'address' => (string) ($dest['URI'] ?? ''),
                    'flags' => (string) ($dest['FLAGS'] ?? ''),
                    'priority' => (int) ($dest['PRIORITY'] ?? 0),
                    'attrs' => (string) ($dest['ATTRS']['BODY'] ?? ''),
                    'state' => $this->parseState($dest),
                ];
            }
        }

        return $entries;
    }

    /**
     * Change a backend's runtime state on EVERY Kamailio node.
     *
     * Fan-out rather than relying on DMQ: dispatcher state lives
     * per-process, so a drain applied to kamailio-1 only is invisible
     * to kamailio-2 and a VRRP failover would put a kamailio with
     * stale state in charge. Mirroring the HAProxyStatsClient pattern
     * (see feedback_haproxy_action_all_nodes) — each node gets the
     * RPC, caller treats it successful only when all accepted.
     *
     * @param  string  $state  One of: 'active', 'drain', 'disable'
     */
    public function setBackendState(int $setId, string $address, string $state): bool
    {
        $rpcState = match ($state) {
            'active' => 'ap',
            // `dp` not `ip`: see class docblock for why. `ip` gets
            // auto-cleared by the next successful OPTIONS probe;
            // `dp` stays put until an operator manually activates.
            'drain' => 'dp',
            'disable' => 'dx',
            default => throw new \InvalidArgumentException("Unknown state: {$state}"),
        };

        $allOk = true;
        foreach ($this->jsonrpcUrls as $url) {
            $result = $this->rpcAt($url, 'dispatcher.set_state', [$rpcState, $setId, "sip:{$address}"]);
            if ($result === null) {
                $allOk = false;
            }
        }

        return $allOk;
    }

    /**
     * Reload the TLS profile on every Kamailio node. Mirrors the
     * `setBackendState` fan-out shape — every node has to reload
     * for the cert rotation to actually take effect; if we only
     * reloaded the first reachable node, the second would keep
     * serving the old cert until restart.
     *
     * Called from `ReloadServicesAfterCertRenewalJob` after acme.sh
     * writes a new cert chain to the shared `tls-certs` volume.
     *
     * @return array{ok: bool, results: array<string, bool>, output: string}
     */
    public function reloadTls(): array
    {
        $results = [];
        $allOk = true;
        foreach ($this->jsonrpcUrls as $url) {
            $resp = $this->rpcAt($url, 'tls.reload', []);
            $ok = $resp !== null;
            $allOk = $allOk && $ok;
            $results[$url] = $ok;
        }
        $summary = implode('; ', array_map(
            fn ($url, $ok) => $url.' '.($ok ? 'ok' : 'FAILED'),
            array_keys($results),
            $results,
        ));

        return [
            'ok' => $allOk,
            'results' => $results,
            'output' => "tls.reload — {$summary}",
        ];
    }

    /**
     * Returns the number of active SIP dialogs (in-progress calls)
     * tracked by the dialog module. This is the key metric the
     * admin watches during drain — when it reaches 0, Asterisk
     * can be safely restarted.
     */
    public function getActiveDialogCount(): int
    {
        // Try dlg.stats_active first (cleaner), fall back to
        // stats.get_statistics with the dialog group.
        $result = $this->rpc('dlg.stats_active');
        if ($result !== null && isset($result['active'])) {
            return (int) $result['active'];
        }

        // Fallback: parse stats.get_statistics for dialog group
        $result = $this->rpc('stats.get_statistics', ['dialog:']);
        if ($result !== null && is_array($result)) {
            foreach ($result as $stat) {
                if (is_string($stat) && str_contains($stat, 'active_dialogs')) {
                    $parts = explode(' = ', $stat);

                    return (int) ($parts[1] ?? 0);
                }
            }
        }

        return 0;
    }

    /**
     * Read calls: try each Kamailio in turn, return the first
     * successful result. Dispatcher/dialog state mirrors closely
     * enough across nodes that whichever answers is representative.
     *
     * @return array<string, mixed>|null
     */
    private function rpc(string $method, array $params = []): ?array
    {
        foreach ($this->jsonrpcUrls as $url) {
            $result = $this->rpcAt($url, $method, $params);
            if ($result !== null) {
                return $result;
            }
        }

        return null;
    }

    /**
     * Send one JSON-RPC call to a specific Kamailio endpoint.
     *
     * @return array<string, mixed>|null
     */
    private function rpcAt(string $url, string $method, array $params = []): ?array
    {
        if ($url === '') {
            return null;
        }

        try {
            $payload = [
                'jsonrpc' => '2.0',
                'method' => $method,
                'id' => 1,
            ];

            if (! empty($params)) {
                $payload['params'] = $params;
            }

            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->withHeaders(['Content-Type' => 'application/json'])
                ->post($url, $payload);

            if (! $response->successful()) {
                Log::warning('Kamailio JSON-RPC returned non-2xx', [
                    'url' => $url,
                    'method' => $method,
                    'status' => $response->status(),
                ]);

                return null;
            }

            $body = $response->json();
            if (isset($body['error'])) {
                Log::warning('Kamailio JSON-RPC error', [
                    'url' => $url,
                    'method' => $method,
                    'error' => $body['error'],
                ]);

                return null;
            }

            return $body['result'] ?? null;
        } catch (\Throwable $e) {
            Log::warning('Kamailio JSON-RPC call failed', [
                'url' => $url,
                'method' => $method,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Parse the runtime state from a dispatcher destination entry.
     * Kamailio encodes the state as a bitmask; we simplify to a
     * human-readable string for the admin UI.
     */
    private function parseState(array $dest): string
    {
        // Some versions report FLAGS as a string like "AP" (active + probing),
        // others as an integer bitmask. Normalize to uppercase.
        //
        // FLAGS letters we care about:
        //   A = active       D = disabled (operator-set, sticky)
        //   I = inactive     T = trying (auto-retrying, temporary)
        //   P = probing on   X = probing off
        //
        // Our state labels:
        //   active   — normal rotation (AP)
        //   draining — operator took it out; stays out until
        //              explicitly reactivated (DP)
        //   disabled — fully off, probing off too (DX)
        $flags = strtoupper((string) ($dest['FLAGS'] ?? ''));

        // Operator-set inactive states — both Drain (`dp` RPC) and
        // Disable (`dx` RPC) land at FLAGS "DX" in Kamailio 5.5.2
        // (the `p` hint gets collapsed on display). We treat any
        // `D`-prefixed flag as "draining" because that's the
        // operator-intent label — the node is not taking calls and
        // will stay that way until Activate is clicked.
        if (str_starts_with($flags, 'D')) {
            return 'draining';
        }
        // Legacy `ip` drain that still auto-reverts on the next
        // successful probe. Shouldn't happen after the `dp` switch
        // but we keep the mapping so we never surface as "unknown".
        if (str_contains($flags, 'IP') || str_starts_with($flags, 'I')) {
            return 'draining';
        }
        if (str_starts_with($flags, 'A')) {
            return 'active';
        }

        // Fall back to numeric DSTATE if FLAGS is not text
        $dstate = (int) ($dest['DSTATE'] ?? 0);

        return match ($dstate) {
            0 => 'active',
            1, 4 => 'draining',
            default => 'disabled',
        };
    }
}
