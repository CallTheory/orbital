<?php

declare(strict_types=1);

namespace App\Services\Health;

use App\Models\SipTrunk;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Runs the suite of system health checks the dashboard displays.
 *
 * Each check is independent and catches its own failures so a broken probe
 * never takes the whole page down. Network checks use short timeouts so the
 * page stays responsive even when something is hung.
 */
class SystemHealthService
{
    /** Probe deadline for non-blocking parallel TCP checks. */
    private const PROBE_TIMEOUT = 1.5;

    /**
     * @return array<int, HealthCheck>
     */
    public function runAll(bool $useCache = true): array
    {
        if ($useCache) {
            $cached = Cache::get('system_health:checks');
            if ($cached) {
                return $cached;
            }
        }

        // Run all the slow network probes concurrently. Returns a map of
        // probe key → ['ok' => bool, 'error' => string|null, 'elapsed_ms' => int].
        //
        // Asterisk lands as four separate probes rather than one. A single
        // AMI check used to hide the case where the process was up but SIP
        // transports had failed to bind (TLS cert mismatch, port conflict,
        // misconfigured http.conf). Probing AMI + SIP/TCP + TLS + WSS lets
        // the dashboard call out partial failure.
        $asteriskHost = config('telephony.asterisk.ami.host') ?: 'asterisk';
        $probes = $this->runParallelProbes([
            'asterisk_ami' => ['host' => $asteriskHost, 'port' => (int) (config('telephony.asterisk.ami.port') ?: 5038)],
            'asterisk_sip_tcp' => ['host' => $asteriskHost, 'port' => 5060],
            'asterisk_sip_tls' => ['host' => $asteriskHost, 'port' => 5061],
            'asterisk_wss' => ['host' => $asteriskHost, 'port' => 8089],
            'livekit' => $this->parseHostPort(config('telephony.livekit.local.url') ?: env('LIVEKIT_URL', 'http://livekit:7880'), 7880),
            'livekit_sip' => ['host' => env('LIVEKIT_SIP_HOST', 'livekit-sip'), 'port' => (int) env('LIVEKIT_SIP_PORT', 5060)],
            'icecast' => ['host' => env('ICECAST_HOST', 'icecast'), 'port' => (int) env('ICECAST_PORT', 8000)],
            'prometheus' => ['host' => 'prometheus', 'port' => 9090],
            'loki' => ['host' => 'loki', 'port' => 3100],
            'grafana' => ['host' => 'grafana', 'port' => 3000],
            'minio' => ['host' => 'minio', 'port' => 9000],
            // Ollama is opt-in (docker-compose `local-ai` profile) —
            // reported as a degraded/warn state when unreachable rather
            // than down, same pattern as Icecast.
            'ollama' => $this->parseHostPort(
                config('services.ollama.url') ?: 'http://ollama:11434',
                11434,
            ),
        ]);

        $checks = [
            $this->checkPostgres(),
            $this->checkValkey(),
            $this->checkAppDisk(),
            $this->checkAsterisk($probes, $asteriskHost),
            $this->probeResultToCheck($probes['livekit'], 'livekit', 'LiveKit', 'Media', 'WebRTC server', 'heroicon-o-signal'),
            $this->probeResultToCheck($probes['livekit_sip'], 'livekit_sip', 'LiveKit SIP', 'Telephony', 'SIP bridge', 'heroicon-o-arrows-right-left'),
            $this->checkAgentWorker(),
            $this->probeResultToCheck($probes['icecast'], 'icecast', 'Icecast', 'Media', 'Hold-music server', 'heroicon-o-musical-note', optional: true),
            $this->checkSipTrunks(),
            $this->checkOnlineOperators(),
            $this->probeResultToCheck($probes['prometheus'], 'prometheus', 'Prometheus', 'Observability', 'Metrics collector', 'heroicon-o-chart-bar'),
            $this->probeResultToCheck($probes['loki'], 'loki', 'Loki', 'Observability', 'Log aggregator', 'heroicon-o-document-text'),
            $this->probeResultToCheck($probes['grafana'], 'grafana', 'Grafana', 'Observability', 'Dashboard UI', 'heroicon-o-presentation-chart-line'),
            $this->probeResultToCheck($probes['minio'], 'minio', 'MinIO', 'Storage', 'S3-compatible object storage', 'heroicon-o-archive-box'),
            $this->probeResultToCheck($probes['ollama'], 'ollama', 'Ollama', 'AI', 'Local embeddings & inference', 'heroicon-o-cpu-chip', optional: true),
        ];

        Cache::put('system_health:checks', $checks, now()->addSeconds(60));

        return $checks;
    }

    /**
     * @param  array<int, HealthCheck>  $checks
     */
    public function summarize(array $checks): array
    {
        $down = 0;
        $warn = 0;
        foreach ($checks as $c) {
            if ($c->isDown()) {
                $down++;
            } elseif ($c->isWarn()) {
                $warn++;
            }
        }

        if ($down > 0) {
            return ['status' => HealthCheck::DOWN, 'label' => 'Outage', 'message' => "{$down} service(s) down".($warn ? ", {$warn} degraded" : '')];
        }
        if ($warn > 0) {
            return ['status' => HealthCheck::WARN, 'label' => 'Degraded', 'message' => "{$warn} service(s) degraded"];
        }

        return ['status' => HealthCheck::OK, 'label' => 'All systems operational', 'message' => 'Every check is passing.'];
    }

    private function checkPostgres(): HealthCheck
    {
        try {
            DB::connection()->getPdo();
            $row = DB::selectOne('SELECT pg_database_size(current_database()) AS size, version() AS version');
            $size = $this->formatBytes((int) $row->size);
            $version = trim(explode(' on ', $row->version)[0] ?? 'PostgreSQL');

            return new HealthCheck(
                key: 'postgres',
                name: 'PostgreSQL',
                category: 'Data',
                status: HealthCheck::OK,
                message: 'Connected and responsive.',
                metrics: ['Version' => $version, 'DB size' => $size],
                icon: 'heroicon-o-circle-stack',
            );
        } catch (Throwable $e) {
            return new HealthCheck(
                key: 'postgres',
                name: 'PostgreSQL',
                category: 'Data',
                status: HealthCheck::DOWN,
                message: $e->getMessage(),
                icon: 'heroicon-o-circle-stack',
            );
        }
    }

    private function checkValkey(): HealthCheck
    {
        try {
            $pong = Redis::connection()->command('ping');
            if (! $pong) {
                throw new \RuntimeException('PING returned empty response');
            }

            $info = Redis::connection()->command('info', ['memory']);
            $used = $this->parseRedisInfo($info, 'used_memory_human');
            $peak = $this->parseRedisInfo($info, 'used_memory_peak_human');

            return new HealthCheck(
                key: 'valkey',
                name: 'Valkey',
                category: 'Data',
                status: HealthCheck::OK,
                message: 'Cache, queue, and session backend reachable.',
                metrics: ['Used' => $used ?? '—', 'Peak' => $peak ?? '—'],
                icon: 'heroicon-o-bolt',
            );
        } catch (Throwable $e) {
            return new HealthCheck(
                key: 'valkey',
                name: 'Valkey',
                category: 'Data',
                status: HealthCheck::DOWN,
                message: $e->getMessage(),
                icon: 'heroicon-o-bolt',
            );
        }
    }

    private function checkAppDisk(): HealthCheck
    {
        try {
            $path = base_path();
            $free = (int) disk_free_space($path);
            $total = (int) disk_total_space($path);

            if ($total === 0) {
                throw new \RuntimeException('Unable to determine disk size.');
            }

            $usedPct = (int) round((($total - $free) / $total) * 100);

            $status = match (true) {
                $usedPct >= 95 => HealthCheck::DOWN,
                $usedPct >= 85 => HealthCheck::WARN,
                default => HealthCheck::OK,
            };

            $message = match ($status) {
                HealthCheck::DOWN => 'Disk is critically full.',
                HealthCheck::WARN => 'Disk usage is high.',
                default => 'Plenty of headroom.',
            };

            return new HealthCheck(
                key: 'app_disk',
                name: 'App Disk',
                category: 'System',
                status: $status,
                message: $message,
                metrics: [
                    'Used' => "{$usedPct}%",
                    'Free' => $this->formatBytes($free),
                    'Total' => $this->formatBytes($total),
                ],
                icon: 'heroicon-o-server-stack',
            );
        } catch (Throwable $e) {
            return new HealthCheck(
                key: 'app_disk',
                name: 'App Disk',
                category: 'System',
                status: HealthCheck::WARN,
                message: $e->getMessage(),
                icon: 'heroicon-o-server-stack',
            );
        }
    }

    /**
     * Aggregate the four Asterisk port probes into a single dashboard card.
     *
     * Status roll-up:
     *   - AMI down → DOWN (process likely dead; nothing else works)
     *   - AMI up but any SIP transport down → WARN (partial outage)
     *   - Everything up → OK
     *
     * UDP 5060 isn't TCP-probe'able; we treat the SIP/TCP result as a proxy
     * since Asterisk binds both transports from the same pjsip process —
     * if TCP is listening, UDP almost certainly is too. The dashboard
     * surfaces the assumption as "UDP 5060 via TCP sibling" in the metric.
     *
     * @param  array<string, array{ok: bool, host: string, port: int, error: ?string}>  $probes
     */
    private function checkAsterisk(array $probes, string $host): HealthCheck
    {
        $ami = $probes['asterisk_ami'] ?? ['ok' => false];
        $sipTcp = $probes['asterisk_sip_tcp'] ?? ['ok' => false];
        $sipTls = $probes['asterisk_sip_tls'] ?? ['ok' => false];
        $wss = $probes['asterisk_wss'] ?? ['ok' => false];

        $mark = fn (bool $ok): string => $ok ? 'ok' : 'down';

        $metrics = [
            'AMI 5038' => $mark($ami['ok']),
            'SIP 5060 TCP' => $mark($sipTcp['ok']),
            'SIP 5060 UDP' => $sipTcp['ok'] ? 'ok (inferred)' : 'down',
            'SIP 5061 TLS' => $mark($sipTls['ok']),
            'WSS 8089' => $mark($wss['ok']),
        ];

        if (! $ami['ok']) {
            return new HealthCheck(
                key: 'asterisk',
                name: 'Asterisk',
                category: 'Telephony',
                status: HealthCheck::DOWN,
                message: "AMI unreachable at {$host}:5038 — Asterisk is down or unreachable from the app container.",
                metrics: $metrics,
                icon: 'heroicon-o-phone-arrow-up-right',
            );
        }

        $transportsUp = $sipTcp['ok'] && $sipTls['ok'] && $wss['ok'];
        if ($transportsUp) {
            return new HealthCheck(
                key: 'asterisk',
                name: 'Asterisk',
                category: 'Telephony',
                status: HealthCheck::OK,
                message: 'AMI, SIP (UDP/TCP/TLS), and WSS all reachable.',
                metrics: $metrics,
                icon: 'heroicon-o-phone-arrow-up-right',
            );
        }

        $down = [];
        if (! $sipTcp['ok']) {
            $down[] = 'SIP 5060';
        }
        if (! $sipTls['ok']) {
            $down[] = 'SIP TLS 5061';
        }
        if (! $wss['ok']) {
            $down[] = 'WSS 8089';
        }

        return new HealthCheck(
            key: 'asterisk',
            name: 'Asterisk',
            category: 'Telephony',
            status: HealthCheck::WARN,
            message: 'Process up (AMI reachable) but some transports are not bound: '.implode(', ', $down).'.',
            metrics: $metrics,
            icon: 'heroicon-o-phone-arrow-up-right',
        );
    }

    private function checkAgentWorker(): HealthCheck
    {
        $heartbeat = Cache::get('agent_worker:heartbeat');

        if (! $heartbeat) {
            return new HealthCheck(
                key: 'agent_worker',
                name: 'Agent Worker',
                category: 'AI',
                status: HealthCheck::WARN,
                message: 'No heartbeat reported. Worker may not be configured to report.',
                icon: 'heroicon-o-cpu-chip',
            );
        }

        $heartbeatAt = $heartbeat instanceof \Carbon\CarbonInterface
            ? $heartbeat
            : \Carbon\Carbon::parse($heartbeat);
        $age = (int) abs(now()->diffInSeconds($heartbeatAt));
        $status = match (true) {
            $age <= 90 => HealthCheck::OK,
            $age <= 300 => HealthCheck::WARN,
            default => HealthCheck::DOWN,
        };

        return new HealthCheck(
            key: 'agent_worker',
            name: 'Agent Worker',
            category: 'AI',
            status: $status,
            message: $status === HealthCheck::OK
                ? 'Reporting heartbeats normally.'
                : "Last heartbeat {$age}s ago.",
            metrics: ['Last seen' => "{$age}s ago"],
            icon: 'heroicon-o-cpu-chip',
        );
    }

    private function checkSipTrunks(): HealthCheck
    {
        try {
            $total = SipTrunk::query()->count();
            $active = SipTrunk::query()->where('is_active', true)->count();

            if ($total === 0) {
                return new HealthCheck(
                    key: 'sip_trunks',
                    name: 'SIP Trunks',
                    category: 'Telephony',
                    status: HealthCheck::WARN,
                    message: 'No SIP trunks configured yet.',
                    icon: 'heroicon-o-link',
                );
            }

            return new HealthCheck(
                key: 'sip_trunks',
                name: 'SIP Trunks',
                category: 'Telephony',
                status: HealthCheck::OK,
                message: "{$active} of {$total} marked active. (Live registration check pending AMI integration.)",
                metrics: ['Active' => (string) $active, 'Total' => (string) $total],
                icon: 'heroicon-o-link',
            );
        } catch (Throwable $e) {
            return new HealthCheck(
                key: 'sip_trunks',
                name: 'SIP Trunks',
                category: 'Telephony',
                status: HealthCheck::WARN,
                message: $e->getMessage(),
                icon: 'heroicon-o-link',
            );
        }
    }

    private function checkOnlineOperators(): HealthCheck
    {
        try {
            $operatorRoleExists = DB::table('roles')
                ->where('name', 'operator')
                ->whereNull('team_id')
                ->exists();

            $totalOperators = $operatorRoleExists
                ? User::query()
                    ->whereExists(function ($q) {
                        $q->select(DB::raw(1))
                            ->from('model_has_roles')
                            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                            ->whereColumn('model_has_roles.model_id', 'users.id')
                            ->where('model_has_roles.model_type', User::class)
                            ->where('roles.name', 'operator')
                            ->whereNull('model_has_roles.team_id');
                    })
                    ->count()
                : 0;

            // Live softphone registration count comes from AMI in a future pass.
            $online = (int) (Cache::get('operators:online_count') ?? 0);

            return new HealthCheck(
                key: 'operators',
                name: 'Online Operators',
                category: 'Workforce',
                status: HealthCheck::OK,
                message: "{$online} of {$totalOperators} operator(s) currently registered.",
                metrics: ['Online' => (string) $online, 'Total' => (string) $totalOperators],
                icon: 'heroicon-o-user-group',
            );
        } catch (Throwable $e) {
            return new HealthCheck(
                key: 'operators',
                name: 'Online Operators',
                category: 'Workforce',
                status: HealthCheck::WARN,
                message: $e->getMessage(),
                icon: 'heroicon-o-user-group',
            );
        }
    }

    /**
     * Run a batch of TCP probes concurrently by shelling out to bash and
     * backgrounding each probe. PHP's stream_socket_client and fsockopen
     * both resolve DNS synchronously even with ASYNC_CONNECT, which makes
     * pure-PHP parallelism impossible when hostnames don't resolve.
     *
     * @param  array<string, array{host: string, port: int}>  $targets
     * @return array<string, array{ok: bool, host: string, port: int, error: ?string}>
     */
    private function runParallelProbes(array $targets): array
    {
        $results = [];

        $script = '';
        foreach ($targets as $key => $target) {
            $host = escapeshellarg($target['host']);
            $port = (int) $target['port'];
            $safeKey = preg_replace('/[^a-z0-9_]/i', '', $key);
            // bash /dev/tcp/<host>/<port> is the simplest portable TCP probe.
            // `timeout` enforces a hard deadline regardless of DNS or connect state.
            $script .= "(timeout {$this->probeTimeoutString()} bash -c 'exec 3<>/dev/tcp/'{$host}'/{$port}' 2>/dev/null && echo {$safeKey}:ok || echo {$safeKey}:fail) & ";
        }
        $script .= 'wait';

        $output = [];
        $exitCode = 0;
        @exec("bash -c ".escapeshellarg($script), $output, $exitCode);

        $statusByKey = [];
        foreach ($output as $line) {
            if (preg_match('/^([a-z0-9_]+):(ok|fail)$/i', trim($line), $m)) {
                $statusByKey[$m[1]] = $m[2] === 'ok';
            }
        }

        foreach ($targets as $key => $target) {
            $ok = $statusByKey[$key] ?? false;
            $results[$key] = [
                'ok' => $ok,
                'host' => $target['host'],
                'port' => $target['port'],
                'error' => $ok ? null : 'unreachable',
            ];
        }

        return $results;
    }

    private function probeTimeoutString(): string
    {
        return number_format(self::PROBE_TIMEOUT, 1);
    }

    private function probeResultToCheck(array $probe, string $key, string $name, string $category, string $label, string $icon, bool $optional = false): HealthCheck
    {
        $endpoint = "{$probe['host']}:{$probe['port']}";

        if ($probe['ok']) {
            return new HealthCheck(
                key: $key,
                name: $name,
                category: $category,
                status: HealthCheck::OK,
                message: "{$label} reachable.",
                metrics: ['Endpoint' => $endpoint],
                icon: $icon,
            );
        }

        return new HealthCheck(
            key: $key,
            name: $name,
            category: $category,
            status: $optional ? HealthCheck::WARN : HealthCheck::DOWN,
            message: ($optional ? 'Optional. ' : '')."Cannot reach {$endpoint} — {$probe['error']}",
            metrics: ['Endpoint' => $endpoint],
            icon: $icon,
        );
    }

    /**
     * Pull host:port out of a URL like http://livekit:7880 or ws://livekit:7880.
     */
    private function parseHostPort(string $url, int $defaultPort): array
    {
        $parts = parse_url($url);
        return [
            'host' => $parts['host'] ?? 'localhost',
            'port' => $parts['port'] ?? $defaultPort,
        ];
    }

    private function parseRedisInfo(mixed $info, string $key): ?string
    {
        if (is_array($info) && isset($info[$key])) {
            return (string) $info[$key];
        }

        if (is_string($info)) {
            foreach (preg_split("/\r?\n/", $info) as $line) {
                if (str_starts_with($line, "{$key}:")) {
                    return trim(substr($line, strlen($key) + 1));
                }
            }
        }

        return null;
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $i = 0;
        $value = (float) $bytes;
        while ($value >= 1024 && $i < count($units) - 1) {
            $value /= 1024;
            $i++;
        }

        return sprintf('%.1f %s', $value, $units[$i]);
    }
}
