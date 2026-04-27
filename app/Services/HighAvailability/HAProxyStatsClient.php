<?php

declare(strict_types=1);

namespace App\Services\HighAvailability;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Client for HAProxy's stats endpoint (`/stats;csv` on port 8404).
 * Used to surface per-frontend / per-server state on the Failover
 * Central admin page and to toggle servers enabled/disabled.
 *
 * Control-plane actions go through the admin-enabled stats page
 * with a POST body of:
 *   `s=<server>&action=<action>&b=<backend>`
 * where action ∈ {disable, enable, drain, ready, stop, start}.
 * `stats admin if TRUE` in haproxy.cfg enables this globally.
 */
class HAProxyStatsClient
{
    /** @param list<string> $haproxies Hostnames, e.g. ['haproxy-1','haproxy-2'] */
    public function __construct(
        protected array $haproxies = ['haproxy-1', 'haproxy-2'],
        protected int $port = 8404,
        protected float $timeout = 2.0,
    ) {}

    /**
     * Return per-server status across every frontend/backend.
     * Each row:
     *   ['haproxy' => 'haproxy-1', 'pxname' => 'pgsql_rw_be',
     *    'svname' => 'patroni-1', 'status' => 'UP', 'check' => 'L7OK']
     *
     * Stats from the first reachable HAProxy — both serve the
     * same state since they're stateless and independent.
     *
     * @return list<array<string,string>>
     */
    public function servers(): array
    {
        foreach ($this->haproxies as $host) {
            try {
                $csv = Http::timeout($this->timeout)
                    ->get("http://{$host}:{$this->port}/stats;csv")
                    ->body();

                return $this->parseCsv($csv, $host);
            } catch (\Throwable $e) {
                Log::warning('haproxy.stats: {host} unreachable', [
                    'host' => $host,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [];
    }

    /**
     * Disable a specific server in a backend (stops new connections
     * but lets existing ones drain via normal timeouts).
     *
     * @return array{0: bool, 1: string}
     */
    public function disableServer(string $backend, string $server): array
    {
        return $this->action($backend, $server, 'disable');
    }

    /**
     * Re-enable a previously disabled server.
     *
     * @return array{0: bool, 1: string}
     */
    public function enableServer(string $backend, string $server): array
    {
        return $this->action($backend, $server, 'enable');
    }

    /**
     * Apply the action to EVERY HAProxy node — not just the first
     * reachable one. Both nodes in the VRRP pair run independently
     * and each makes its own load-balancing decisions; if we only
     * disable the server on one, new connections via the other
     * keep landing on the drained backend. Caller treats the drain
     * as successful only when all nodes accepted the command.
     *
     * @return array{0: bool, 1: string}
     */
    protected function action(string $backend, string $server, string $action): array
    {
        $results = [];
        $allOk = true;
        foreach ($this->haproxies as $host) {
            try {
                $resp = Http::timeout(5)
                    ->asForm()
                    ->post("http://{$host}:{$this->port}/stats", [
                        'b' => $backend,
                        's' => $server,
                        'action' => $action,
                        's_total' => '',
                    ]);
                $ok = $resp->status() >= 200 && $resp->status() < 400;
                $allOk = $allOk && $ok;
                $results[] = "{$host}: HTTP {$resp->status()}";
            } catch (\Throwable $e) {
                $allOk = false;
                $results[] = "{$host}: {$e->getMessage()}";
                Log::warning('haproxy.action: {host} failed', [
                    'host' => $host,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [$allOk, "{$action} {$backend}/{$server} — ".implode('; ', $results)];
    }

    /**
     * Parse HAProxy's CSV stats output, skipping the leading
     * `# ` comment and FRONTEND/BACKEND aggregate rows.
     *
     * @return list<array<string,string>>
     */
    protected function parseCsv(string $csv, string $sourceHost): array
    {
        $rows = [];
        $lines = preg_split('/\r?\n/', trim($csv)) ?: [];
        $header = null;
        foreach ($lines as $line) {
            if ($line === '' || str_starts_with($line, '#') === false && $header === null) {
                continue;
            }
            if (str_starts_with($line, '# ')) {
                $header = str_getcsv(substr($line, 2), ',', '"', '\\');

                continue;
            }
            if ($header === null) {
                continue;
            }
            $fields = str_getcsv($line, ',', '"', '\\');
            if (count($fields) < count($header)) {
                continue;
            }
            $row = array_combine($header, array_slice($fields, 0, count($header)));
            if (! is_array($row)) {
                continue;
            }
            // Skip aggregate FRONTEND/BACKEND lines — only
            // surface per-server rows.
            if (in_array($row['svname'] ?? '', ['FRONTEND', 'BACKEND'], true)) {
                continue;
            }
            $rows[] = [
                'haproxy' => $sourceHost,
                'pxname' => $row['pxname'] ?? '',
                'svname' => $row['svname'] ?? '',
                'status' => $row['status'] ?? '',
                'check_status' => $row['check_status'] ?? '',
                'weight' => $row['weight'] ?? '',
                'addr' => $row['addr'] ?? '',
            ];
        }

        return $rows;
    }
}
