<?php

declare(strict_types=1);

namespace App\Services\Health;

use App\Events\SystemHealthUpdated;
use App\Models\HealthCheckAcknowledgment;
use App\Models\RtpengineNode;
use App\Models\SipTrunk;
use App\Services\CertificateService;
use App\Services\Telephony\Realtime\OutboundTrunkAuditor;
use App\Services\Telephony\RtpengineService;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
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
            // SIP TLS (5061) intentionally NOT probed: even a clean
            // TLS handshake without a following SIP OPTIONS dialog
            // makes chan_pjsip log a "Unable to set up ssl connection"
            // error per probe, which at a 5s poll cadence overwhelms
            // the Asterisk log. Niche transport (most installs use
            // UDP/TCP 5060 internally and only expose TLS to external
            // trunks); the TCP probe at 5060 already signals whether
            // chan_pjsip is alive, and `asterisk_wss` exercises the
            // TLS cert loading via a clean HTTP-over-TLS handshake.
            // 'asterisk_sip_tls' => ['host' => $asteriskHost, 'port' => 5061, 'tls' => true],
            // tls:true makes the probe do a real TLS handshake
            // (openssl s_client) instead of a raw TCP connect. Without
            // it, Asterisk's SSL_accept fails on our half-open socket
            // and logs an SSL_shutdown error for every poll cycle.
            'asterisk_wss' => ['host' => $asteriskHost, 'port' => 8089, 'tls' => true],
            'livekit' => $this->parseHostPort(config('telephony.livekit.local.url') ?: env('LIVEKIT_URL', 'http://livekit:7880'), 7880),
            'livekit_sip' => ['host' => env('LIVEKIT_SIP_HOST', 'livekit-sip'), 'port' => (int) env('LIVEKIT_SIP_PORT', 5060)],
            'icecast' => ['host' => env('ICECAST_HOST', 'icecast'), 'port' => (int) env('ICECAST_PORT', 8000)],
            'prometheus' => ['host' => 'prometheus', 'port' => 9090],
            'loki' => ['host' => 'loki', 'port' => 3100],
            'grafana' => ['host' => 'grafana', 'port' => 3000],
            // SeaweedFS S3 API is fronted by the HAProxy pair which
            // round-robins across filer-1 and filer-2. Probing the
            // frontend exercises the whole data-plane path rather
            // than a single filer, so the card goes red only when
            // the bucket itself is unreachable (not when one filer
            // is restarting).
            'seaweedfs' => ['host' => 'haproxy', 'port' => 8333],
            // Ollama is opt-in (docker-compose `local-ai` profile) —
            // reported as a degraded/warn state when unreachable rather
            // than down, same pattern as Icecast.
            'ollama' => $this->parseHostPort(
                config('services.ollama.url') ?: 'http://ollama:11434',
                11434,
            ),
            'mail' => [
                'host' => (string) (config('mail.mailers.smtp.host') ?: 'mailpit'),
                'port' => (int) (config('mail.mailers.smtp.port') ?: 1025),
            ],
            'promtail' => ['host' => 'promtail', 'port' => 9080],
            // Haraka SMTP shim — inbound mail gateway. TCP probe on
            // its SMTP listener (port 25 inside the container). Not
            // optional: once we depend on Haraka for inbound email,
            // it being down is a real outage.
            'haraka' => ['host' => 'haraka', 'port' => 25],
            // Reverb websocket broadcast server. Load-bearing: status
            // bar, nav badge, and future in-app notifications all
            // ride this channel. If Reverb is down, every tab falls
            // back to the 15s polling path — degraded but not dead —
            // so we surface it but not as an outage.
            'reverb' => [
                'host' => (string) (config('reverb.servers.reverb.host') === '0.0.0.0'
                    ? 'reverb'
                    : (config('reverb.servers.reverb.host') ?: 'reverb')),
                'port' => (int) (config('reverb.servers.reverb.port') ?: 8080),
            ],
            // Kamailio SIP proxy — TCP probe on the JSON-RPC
            // management port (8090) rather than the SIP port
            // (5060/UDP can't be TCP-probed). Marked optional when
            // kamailio.enabled is false so installs without the
            // container don't get a red card.
            'kamailio' => ['host' => 'kamailio', 'port' => 8090],
            // HAProxy internal stats frontend. Not the dataplane
            // probe (the tier-specific probes like `seaweedfs` already
            // hit the LB for their path) — this probes the stats
            // page on 8404 so a haproxy that's up but misrouting
            // still surfaces via the HAProxyStatsClient + per-tier
            // red lights rather than a single overall-red card.
            'haproxy' => ['host' => 'haproxy', 'port' => 8404],
            // Local whisper.cpp transcription service. Used by
            // clients that pick `whisper_local` as their voicemail
            // transcription provider. Optional because a down
            // whisper-local just means voicemail emails go out
            // without transcripts (the job catches + degrades) —
            // not an outage.
            'whisper_local' => ['host' => 'whisper-local', 'port' => 9700],
            // Dev control panels — pgAdmin (Postgres) and Redis
            // Commander (Valkey). Both reach the database instances
            // they wrap over the sail network, so probing them tells
            // us the container is up and answering HTTP. Marked
            // optional because they're admin-facing tools — if
            // either is down the app itself keeps working.
            'pgadmin' => ['host' => 'pgadmin', 'port' => 443],
            'redis_commander' => ['host' => 'redis-commander', 'port' => 8081],
        ]);

        $checks = [
            $this->checkPostgres(),
            $this->checkValkey(),
            $this->checkAppDisk(),
            $this->checkAsterisk($probes, $asteriskHost),
            $this->probeResultToCheck($probes['livekit'], 'livekit', 'LiveKit', 'Media', 'LiveKit WebRTC server', 'heroicon-o-signal'),
            $this->probeResultToCheck($probes['livekit_sip'], 'livekit_sip', 'LiveKit SIP', 'Telephony', 'LiveKit SIP bridge', 'heroicon-o-arrows-right-left'),
            $this->checkAgentWorker(),
            $this->probeResultToCheck($probes['icecast'], 'icecast', 'Icecast', 'Media', 'Streaming hold music server', 'heroicon-o-musical-note', optional: true),
            $this->checkSipTrunks(),
            $this->probeResultToCheck($probes['prometheus'], 'prometheus', 'Prometheus', 'Observability', 'Metrics collector for Grafana', 'heroicon-o-chart-bar'),
            $this->probeResultToCheck($probes['loki'], 'loki', 'Loki', 'Observability', 'Log aggregator for Grafana', 'heroicon-o-document-text'),
            $this->probeResultToCheck($probes['grafana'], 'grafana', 'Grafana', 'Observability', 'Grafana dashboard', 'heroicon-o-presentation-chart-line'),
            $this->probeResultToCheck($probes['seaweedfs'], 'seaweedfs', 'SeaweedFS', 'Storage', 'S3-compatible object storage', 'heroicon-o-archive-box'),
            $this->probeResultToCheck($probes['ollama'], 'ollama', 'Ollama', 'AI', 'Local embeddings and inference server', 'heroicon-o-cpu-chip', optional: true),
            $this->probeResultToCheck($probes['mail'], 'mail', 'Mail', 'System', 'Outbound SMTP relay', 'heroicon-o-envelope'),
            $this->probeResultToCheck($probes['haraka'], 'haraka', 'Inbound Mail', 'Mail', 'Haraka Inbound SMTP gateway', 'heroicon-o-envelope-open'),
            $this->probeResultToCheck($probes['reverb'], 'reverb', 'Reverb', 'System', 'Websocket broadcast server for real-time UI', 'heroicon-o-bolt'),
            $this->probeResultToCheck($probes['kamailio'], 'kamailio', 'Kamailio', 'Telephony', 'SIP proxy for call routing and draining', 'heroicon-o-arrows-right-left', optional: ! config('telephony.kamailio.enabled')),
            $this->checkRtpengine(),
            $this->checkOutboundTrunkInvariant(),
            $this->probeResultToCheck($probes['haproxy'], 'haproxy', 'HAProxy', 'System', 'Internal L4 load balancer', 'heroicon-o-arrows-right-left'),
            $this->probeResultToCheck($probes['whisper_local'], 'whisper_local', 'Whisper (local)', 'AI', 'Local whisper.cpp transcription service for voicemail', 'heroicon-o-microphone', optional: true),
            $this->checkTlsCertificate(),
            $this->probeResultToCheck($probes['pgadmin'], 'pgadmin', 'pgAdmin', 'Control Panels', 'Postgres admin web UI', 'heroicon-o-circle-stack', optional: true),
            $this->probeResultToCheck($probes['redis_commander'], 'redis_commander', 'Redis Commander', 'Control Panels', 'Valkey / Redis web browser', 'heroicon-o-bolt', optional: true),
            $this->probeResultToCheck($probes['promtail'], 'promtail', 'Promtail', 'Observability', 'Log shipper feeding Loki', 'heroicon-o-paper-airplane', optional: true),
            $this->checkHorizon(),
            $this->checkScheduler(),
        ];

        // Layer active acknowledgments onto the raw results.
        // Cards keep their true status for display; effectiveStatus()
        // is what aggregate rollup reads, so acked cards count as
        // OK for the nav badge / status bar / summary.
        $checks = $this->applyAcknowledgments($checks);

        Cache::put('system_health:checks', $checks, now()->addSeconds(60));

        // Broadcast the new aggregate state on the `system-health`
        // Reverb channel so every connected Filament tab (every
        // panel, every open window) repaints its status bar + nav
        // badge immediately. Only fires on a real re-probe — the
        // cached hot path above returns without broadcasting, so we
        // don't flood the channel on every page load.
        SystemHealthUpdated::dispatch($checks);

        return $checks;
    }

    /**
     * Look up every active ack, attach the ack metadata to any
     * check whose key matches, and auto-clear acks whose
     * underlying check has returned to OK on its own.
     *
     * Auto-clear is what makes the ack "sticky until recovery"
     * — the operator doesn't have to remember to toggle it off
     * when the maintenance window ends and the component comes
     * back. Manually cleared acks stay in the audit log via
     * `cleared_by_user_id` being set; auto-cleared acks have
     * `cleared_by_user_id = null` so the history distinguishes
     * them.
     *
     * @param  array<int, HealthCheck>  $checks
     * @return array<int, HealthCheck>
     */
    private function applyAcknowledgments(array $checks): array
    {
        $activeAcks = HealthCheckAcknowledgment::active()
            ->get()
            ->keyBy('check_key');

        if ($activeAcks->isEmpty()) {
            return $checks;
        }

        $out = [];
        foreach ($checks as $check) {
            $ack = $activeAcks->get($check->key);

            if ($ack === null) {
                $out[] = $check;

                continue;
            }

            // Auto-clear: if the underlying check is back to OK
            // on its own, the ack has served its purpose. Stamp
            // cleared_at with a null cleared_by_user_id so the
            // audit log distinguishes it from manual clears.
            if ($check->isOk()) {
                $ack->forceFill([
                    'cleared_at' => now(),
                    'cleared_by_user_id' => null,
                ])->save();
                $out[] = $check;

                continue;
            }

            // Active ack on a non-OK check → attach metadata so
            // the card renders the "Acknowledged by {name}" note
            // and summarize() rolls it up as OK.
            $out[] = $check->withAck([
                'id' => $ack->id,
                'user_name' => $ack->acknowledgedBy?->name ?? 'Unknown',
                'acknowledged_at' => $ack->acknowledged_at->toIso8601String(),
                'reason' => $ack->reason,
            ]);
        }

        return $out;
    }

    /**
     * @param  array<int, HealthCheck>  $checks
     */
    public function summarize(array $checks): array
    {
        // Aggregate counts use effectiveStatus() so acknowledged
        // cards roll up as OK — the top bar / nav badge / summary
        // label all stay green during planned maintenance even
        // though individual cards still display their raw state.
        $down = 0;
        $warn = 0;
        foreach ($checks as $c) {
            $status = $c->effectiveStatus();
            if ($status === HealthCheck::DOWN) {
                $down++;
            } elseif ($status === HealthCheck::WARN) {
                $warn++;
            }
        }

        // Labels kept in lockstep with HealthCheck::statusLabel(),
        // the nav badge (Dashboard::getNavigationBadge), and the
        // status bar dispatch (SystemStatusBar::load). One
        // vocabulary everywhere: Operational / Degraded / Outage.
        if ($down > 0) {
            return [
                'status' => HealthCheck::DOWN,
                'label' => 'Problem',
                'message' => "{$down} component(s) with problems".($warn ? ", {$warn} degraded" : ''),
            ];
        }
        if ($warn > 0) {
            return [
                'status' => HealthCheck::WARN,
                'label' => 'Degraded',
                'message' => "{$warn} component(s) degraded",
            ];
        }

        return [
            'status' => HealthCheck::OK,
            'label' => 'OK',
            'message' => 'Every check is passing.',
        ];
    }

    /**
     * rtpengine — aggregate health across the active node registry.
     *
     * NG protocol is bencode-over-UDP, so this can't ride the
     * generic TCP probe path. We dispatch a `ping` to every active
     * node and roll up: all-up = OK, partial = WARN, none-up = DOWN.
     * No active nodes registered = optional/degraded so a fresh
     * install with the registry empty doesn't show red.
     *
     * Recording-spool disk usage is intentionally NOT checked here —
     * the spool lives on the rtpengine VMs, not the app host. The
     * Prometheus exporter on each VM (rtpengine 11.x `--listen-prom`,
     * plus node_exporter for filesystem metrics) is the right place
     * for that alert, and the Grafana rtpengine dashboard surfaces it.
     */
    private function checkRtpengine(): HealthCheck
    {
        try {
            $totalActive = RtpengineNode::active()->count();

            if ($totalActive === 0) {
                return new HealthCheck(
                    key: 'rtpengine',
                    name: 'rtpengine',
                    category: 'Telephony',
                    status: HealthCheck::WARN,
                    message: 'No active rtpengine nodes registered.',
                    icon: 'heroicon-o-signal',
                );
            }

            $results = app(RtpengineService::class)->pingAll();
            $up = count(array_filter($results));
            $down = $totalActive - $up;

            $status = match (true) {
                $up === 0 => HealthCheck::DOWN,
                $down > 0 => HealthCheck::WARN,
                default => HealthCheck::OK,
            };

            $message = $status === HealthCheck::OK
                ? 'Media relay daemons responding on every active node.'
                : ($up === 0
                    ? 'No rtpengine nodes are responding to NG ping.'
                    : "{$down} of {$totalActive} rtpengine node(s) unresponsive.");

            return new HealthCheck(
                key: 'rtpengine',
                name: 'rtpengine',
                category: 'Telephony',
                status: $status,
                message: $message,
                metrics: [
                    'Active nodes' => (string) $totalActive,
                    'Responding' => (string) $up,
                ],
                icon: 'heroicon-o-signal',
            );
        } catch (Throwable $e) {
            return new HealthCheck(
                key: 'rtpengine',
                name: 'rtpengine',
                category: 'Telephony',
                status: HealthCheck::DOWN,
                message: $e->getMessage(),
                icon: 'heroicon-o-signal',
            );
        }
    }

    /**
     * Outbound-trunk invariant — every Asterisk trunk endpoint must
     * point at the platform edge (Kamailio / LiveKit-SIP), never
     * directly at a carrier IP. A leak means that trunk's outbound
     * calls bypass rtpengine and silently go un-recorded.
     *
     * The auditor is non-destructive (detector only); when this card
     * goes red, the fix is to update the leaking trunk's contact in
     * `TrunkSyncer` so it routes through Kamailio.
     */
    private function checkOutboundTrunkInvariant(): HealthCheck
    {
        try {
            $leaks = app(OutboundTrunkAuditor::class)->findLeaks();
            if ($leaks === []) {
                return new HealthCheck(
                    key: 'outbound_trunk_invariant',
                    name: 'Outbound trunk invariant',
                    category: 'Telephony',
                    status: HealthCheck::OK,
                    message: 'Every trunk routes outbound through the platform edge.',
                    icon: 'heroicon-o-shield-check',
                );
            }

            $sample = array_slice($leaks, 0, 3);
            $sampleSummary = implode(', ', array_map(
                fn ($l) => ($l['trunk_name'] ?? $l['endpoint_id']).' (proxy: '.$l['outbound_proxy'].')',
                $sample,
            ));

            return new HealthCheck(
                key: 'outbound_trunk_invariant',
                name: 'Outbound trunk invariant',
                category: 'Telephony',
                status: HealthCheck::DOWN,
                message: count($leaks).' trunk(s) without a Kamailio outbound_proxy: '.$sampleSummary,
                metrics: ['Leaking trunks' => (string) count($leaks)],
                icon: 'heroicon-o-shield-exclamation',
            );
        } catch (Throwable $e) {
            return new HealthCheck(
                key: 'outbound_trunk_invariant',
                name: 'Outbound trunk invariant',
                category: 'Telephony',
                status: HealthCheck::WARN,
                message: 'Auditor failed to run: '.$e->getMessage(),
                icon: 'heroicon-o-shield-exclamation',
            );
        }
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
                message: 'Primary relational database',
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
                message: 'In-memory key-value store',
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

            $message = 'Application container disk';

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
        $wss = $probes['asterisk_wss'] ?? ['ok' => false];

        $mark = fn (bool $ok): string => $ok ? 'ok' : 'down';

        // SIP TLS 5061 intentionally omitted — see the probe list
        // for the reason (probing it spams Asterisk's SSL log).
        $metrics = [
            'AMI 5038' => $mark($ami['ok']),
            'SIP 5060 TCP' => $mark($sipTcp['ok']),
            'SIP 5060 UDP' => $sipTcp['ok'] ? 'ok (inferred)' : 'down',
            'WSS 8089' => $mark($wss['ok']),
        ];

        if (! $ami['ok']) {
            return new HealthCheck(
                key: 'asterisk',
                name: 'Asterisk',
                category: 'Telephony',
                status: HealthCheck::DOWN,
                message: "AMI unreachable at {$host}:5038 — Asterisk is down or unreachable from the app container",
                metrics: $metrics,
                icon: 'heroicon-o-phone-arrow-up-right',
            );
        }

        $transportsUp = $sipTcp['ok'] && $wss['ok'];
        if ($transportsUp) {
            return new HealthCheck(
                key: 'asterisk',
                name: 'Asterisk',
                category: 'Telephony',
                status: HealthCheck::OK,
                message: 'Asterisk PBX and SIP server',
                metrics: $metrics,
                icon: 'heroicon-o-phone-arrow-up-right',
            );
        }

        $down = [];
        if (! $sipTcp['ok']) {
            $down[] = 'SIP 5060';
        }
        if (! $wss['ok']) {
            $down[] = 'WSS 8089';
        }

        return new HealthCheck(
            key: 'asterisk',
            name: 'Asterisk',
            category: 'Telephony',
            status: HealthCheck::WARN,
            message: 'Process up (AMI reachable) but some transports are not bound: '.implode(', ', $down),
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
                message: 'No heartbeat reported — worker may not be configured to report',
                icon: 'heroicon-o-cpu-chip',
            );
        }

        $heartbeatAt = $heartbeat instanceof CarbonInterface
            ? $heartbeat
            : Carbon::parse($heartbeat);
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
                ? 'Python LiveKit AI agent worker'
                : "Last heartbeat {$age}s ago",
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
                    message: 'No SIP trunks configured yet',
                    icon: 'heroicon-o-link',
                );
            }

            return new HealthCheck(
                key: 'sip_trunks',
                name: 'SIP Trunks',
                category: 'Telephony',
                status: HealthCheck::OK,
                message: 'SIP carrier connections',
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

    /**
     * Horizon master supervisor status. If nothing is registered, the
     * queue worker is down and jobs pile up in Valkey silently. Horizon
     * itself persists supervisor records in Redis so this check is cheap
     * and doesn't require a TCP probe.
     */
    private function checkHorizon(): HealthCheck
    {
        try {
            $repo = app(MasterSupervisorRepository::class);
            $masters = $repo->all();

            if (empty($masters)) {
                return new HealthCheck(
                    key: 'horizon',
                    name: 'Horizon',
                    category: 'System',
                    status: HealthCheck::DOWN,
                    message: 'Horizon queue worker is down',
                    icon: 'heroicon-o-queue-list',
                );
            }

            $paused = 0;
            foreach ($masters as $master) {
                if (($master->status ?? null) === 'paused') {
                    $paused++;
                }
            }

            $status = $paused > 0 ? HealthCheck::WARN : HealthCheck::OK;

            return new HealthCheck(
                key: 'horizon',
                name: 'Horizon',
                category: 'System',
                status: $status,
                message: $paused > 0
                    ? "{$paused} supervisor(s) paused"
                    : 'Laravel background job worker',
                metrics: [
                    'Masters' => (string) count($masters),
                    'Paused' => (string) $paused,
                ],
                icon: 'heroicon-o-queue-list',
            );
        } catch (Throwable $e) {
            return new HealthCheck(
                key: 'horizon',
                name: 'Horizon',
                category: 'System',
                status: HealthCheck::WARN,
                message: $e->getMessage(),
                icon: 'heroicon-o-queue-list',
            );
        }
    }

    /**
     * Scheduler heartbeat. An every-minute task in routes/console.php
     * touches `scheduler:heartbeat`; if the key is missing or stale by
     * more than two minutes, the scheduler container isn't running.
     * This is the only check that catches that specific failure since
     * everything else uses on-demand triggers.
     */
    private function checkScheduler(): HealthCheck
    {
        $heartbeat = Cache::get('scheduler:heartbeat');

        if (! $heartbeat) {
            return new HealthCheck(
                key: 'scheduler',
                name: 'Scheduler',
                category: 'System',
                status: HealthCheck::WARN,
                message: 'No heartbeat yet — scheduler may still be starting',
                icon: 'heroicon-o-clock',
            );
        }

        try {
            $heartbeatAt = Carbon::parse((string) $heartbeat);
        } catch (Throwable) {
            return new HealthCheck(
                key: 'scheduler',
                name: 'Scheduler',
                category: 'System',
                status: HealthCheck::WARN,
                message: 'Heartbeat value unparseable',
                icon: 'heroicon-o-clock',
            );
        }

        $age = (int) abs(now()->diffInSeconds($heartbeatAt));
        $status = match (true) {
            $age <= 120 => HealthCheck::OK,
            $age <= 300 => HealthCheck::WARN,
            default => HealthCheck::DOWN,
        };

        return new HealthCheck(
            key: 'scheduler',
            name: 'Scheduler',
            category: 'System',
            status: $status,
            message: $status === HealthCheck::OK
                ? 'Laravel scheduled task runner'
                : "Last beat {$age}s ago",
            metrics: ['Last beat' => "{$age}s ago"],
            icon: 'heroicon-o-clock',
        );
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
            $timeout = $this->probeTimeoutString();

            if (! empty($target['tls'])) {
                // Real TLS handshake via openssl s_client. Closes
                // cleanly after the handshake by feeding empty stdin
                // with `-no_ign_eof`, so the server logs no half-open
                // SSL shutdown error. Cheaper than importing PHP's
                // OpenSSL extension config per-probe.
                $script .= "(timeout {$timeout} bash -c '</dev/null openssl s_client -connect '{$host}':{$port} -quiet -no_ign_eof >/dev/null 2>&1' && echo {$safeKey}:ok || echo {$safeKey}:fail) & ";
            } else {
                // bash /dev/tcp/<host>/<port> is the simplest portable
                // TCP probe. `timeout` enforces a hard deadline regardless
                // of DNS or connect state.
                $script .= "(timeout {$timeout} bash -c 'exec 3<>/dev/tcp/'{$host}'/{$port}' 2>/dev/null && echo {$safeKey}:ok || echo {$safeKey}:fail) & ";
            }
        }
        $script .= 'wait';

        $output = [];
        $exitCode = 0;
        @exec('bash -c '.escapeshellarg($script), $output, $exitCode);

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

    /**
     * The `$label` passed in is a short description of what the
     * service does (e.g. "WebRTC media server", "SIP bridge").
     * In the OK state we emit it verbatim — the status color is
     * already doing the "it's up" communication. In the DOWN state
     * we include the endpoint + error so the diagnostic detail
     * isn't lost to color-only status.
     */
    private function probeResultToCheck(array $probe, string $key, string $name, string $category, string $label, string $icon, bool $optional = false): HealthCheck
    {
        $endpoint = "{$probe['host']}:{$probe['port']}";

        if ($probe['ok']) {
            return new HealthCheck(
                key: $key,
                name: $name,
                category: $category,
                status: HealthCheck::OK,
                message: $label,
                metrics: ['Endpoint' => $endpoint],
                icon: $icon,
            );
        }

        return new HealthCheck(
            key: $key,
            name: $name,
            category: $category,
            status: $optional ? HealthCheck::WARN : HealthCheck::DOWN,
            message: ($optional ? 'Optional — ' : '')."cannot reach {$endpoint} ({$probe['error']})",
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
        if (is_array($info)) {
            if (isset($info[$key])) {
                return (string) $info[$key];
            }

            foreach ($info as $section) {
                if (is_array($section) && isset($section[$key])) {
                    return (string) $section[$key];
                }
            }
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

    /**
     * Check the managed TLS certificate's expiry status. Reads the
     * PEM file from the shared tls-certs volume via CertificateService.
     *
     * When `tls.enabled` is false, returns WARN "not configured" —
     * always-visible so the admin knows TLS isn't set up, but not red
     * so it doesn't alarm during initial install.
     */
    private function checkTlsCertificate(): HealthCheck
    {
        if (! config('tls.enabled')) {
            return new HealthCheck(
                key: 'tls',
                name: 'TLS Certificate',
                category: 'System',
                status: HealthCheck::WARN,
                message: 'TLS not configured — set ACME_ENABLED=true in .env',
                icon: 'heroicon-o-lock-closed',
            );
        }

        $service = app(CertificateService::class);

        if (! $service->certExists()) {
            return new HealthCheck(
                key: 'tls',
                name: 'TLS Certificate',
                category: 'System',
                status: HealthCheck::WARN,
                message: 'No certificate file found — issue one from the TLS Certificates page',
                icon: 'heroicon-o-lock-closed',
            );
        }

        $info = $service->getCertificateInfo();
        if ($info === null) {
            return new HealthCheck(
                key: 'tls',
                name: 'TLS Certificate',
                category: 'System',
                status: HealthCheck::WARN,
                message: 'Certificate file exists but could not be parsed',
                icon: 'heroicon-o-lock-closed',
            );
        }

        if ($info->isExpired()) {
            return new HealthCheck(
                key: 'tls',
                name: 'TLS Certificate',
                category: 'System',
                status: HealthCheck::DOWN,
                message: "Certificate expired on {$info->validTo->format('M j, Y')}",
                metrics: [
                    'Domain' => $info->domain,
                    'Issuer' => $info->issuer,
                    'Expired' => $info->validTo->format('M j, Y g:i A'),
                ],
                icon: 'heroicon-o-lock-closed',
            );
        }

        $status = $info->isExpiringSoon(14) ? HealthCheck::WARN : HealthCheck::OK;

        return new HealthCheck(
            key: 'tls',
            name: 'TLS Certificate',
            category: 'System',
            status: $status,
            message: "{$info->daysRemaining} days remaining ({$info->domain})",
            metrics: [
                'Domain' => $info->domain,
                'Issuer' => $info->issuer,
                'Expires' => $info->validTo->format('M j, Y'),
                'Days remaining' => (string) $info->daysRemaining,
                'Staging' => $info->isStaging ? 'Yes' : 'No',
            ],
            icon: 'heroicon-o-lock-closed',
        );
    }
}
