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
 *   active  → RPC state "a"  — accepts new calls + probing
 *   drain   → RPC state "ip" — inactive + probing; stops new
 *             calls, existing dialogs finish naturally
 *   disable → RPC state "dx" — fully disabled, no probing
 */
class KamailioService
{
    private string $jsonrpcUrl;

    private const TIMEOUT_SECONDS = 2;

    public function __construct()
    {
        $this->jsonrpcUrl = (string) config('telephony.kamailio.jsonrpc_url');
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
     * Change a backend's runtime state.
     *
     * @param  string  $state  One of: 'active', 'drain', 'disable'
     */
    public function setBackendState(int $setId, string $address, string $state): bool
    {
        $rpcState = match ($state) {
            'active' => 'ap',
            'drain' => 'ip',
            'disable' => 'dx',
            default => throw new \InvalidArgumentException("Unknown state: {$state}"),
        };

        // dispatcher.set_state expects positional params in some
        // Kamailio versions: [state, group, address]
        $result = $this->rpc('dispatcher.set_state', [$rpcState, $setId, "sip:{$address}"]);

        return $result !== null;
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
     * @return array<string, mixed>|null
     */
    private function rpc(string $method, array $params = []): ?array
    {
        if ($this->jsonrpcUrl === '') {
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
                ->post($this->jsonrpcUrl, $payload);

            if (! $response->successful()) {
                Log::warning('Kamailio JSON-RPC returned non-2xx', [
                    'method' => $method,
                    'status' => $response->status(),
                ]);
                return null;
            }

            $body = $response->json();
            if (isset($body['error'])) {
                Log::warning('Kamailio JSON-RPC error', [
                    'method' => $method,
                    'error' => $body['error'],
                ]);
                return null;
            }

            return $body['result'] ?? null;
        } catch (\Throwable $e) {
            Log::warning('Kamailio JSON-RPC call failed', [
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
        // others as an integer bitmask. Normalize.
        $flags = strtoupper((string) ($dest['FLAGS'] ?? ''));

        if (str_contains($flags, 'AX') || str_contains($flags, 'DX')) {
            return 'disabled';
        }
        if (str_contains($flags, 'IP') || str_contains($flags, 'I')) {
            return 'draining';
        }
        if (str_contains($flags, 'AP') || str_contains($flags, 'A')) {
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
