<?php

declare(strict_types=1);

namespace App\Services\Metrics;

use App\Models\PlatformSetting;
use App\Models\Team;
use App\Services\Backup\BackupService;
use App\Support\Release;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * Gathers deployment-wide metrics: per-client call/message/email/chat
 * volume, queue depth, worker health, and inventory counts.
 *
 * These are facts about the whole installation, not about one replica,
 * which is why they're pushed by a single scheduled writer rather than
 * exposed on every app instance's /metrics. See config/metrics.php for
 * the reasoning.
 *
 * Every collector method is individually guarded: one unavailable
 * subsystem (say, Valkey during a Sentinel failover) degrades that one
 * family rather than emptying the whole push. A metrics pipeline that
 * goes dark exactly when something breaks is worse than useless.
 */
class PlatformMetricsCollector
{
    /** Window for "recent activity" gauges. */
    private const WINDOW_HOURS = 24;

    /**
     * @param  Exposition|null  $exposition  write into an existing
     *                                       exposition when several
     *                                       collectors share one payload,
     *                                       so families aren't declared twice
     */
    public function collect(?Exposition $exposition = null): Exposition
    {
        $exposition ??= new Exposition;

        $this->buildInfo($exposition);
        $this->guard('clients', fn () => $this->clients($exposition));
        $this->guard('calls', fn () => $this->calls($exposition));
        $this->guard('messages', fn () => $this->messages($exposition));
        $this->guard('email', fn () => $this->email($exposition));
        $this->guard('voicemail', fn () => $this->voicemail($exposition));
        $this->guard('chat', fn () => $this->chat($exposition));
        $this->guard('messaging', fn () => $this->messaging($exposition));
        $this->guard('ai', fn () => $this->ai($exposition));
        $this->guard('operators', fn () => $this->operators($exposition));
        $this->guard('queues', fn () => $this->queues($exposition));
        $this->guard('inventory', fn () => $this->inventory($exposition));
        $this->guard('backups', fn () => $this->backups($exposition));

        return $exposition;
    }

    /**
     * Version/commit/license as labels on a constant 1. The standard
     * Prometheus idiom for build metadata — lets a dashboard show which
     * release is deployed, and lets an alert fire when two app replicas
     * disagree about it mid-rollout.
     */
    private function buildInfo(Exposition $exposition): void
    {
        $exposition->gauge(
            'orbital_build_info',
            1,
            [
                'version' => Release::version(),
                'commit' => Release::shortCommit() ?? 'unknown',
                'channel' => Release::channel(),
                'license' => Release::licenseSpdx(),
            ],
            'Orbital build identity. Always 1; read the labels.',
        );
    }

    private function clients(Exposition $exposition): void
    {
        $exposition->gauge(
            'orbital_clients_total',
            Team::query()->where('personal_team', false)->count(),
            [],
            'Client accounts (excluding personal teams) on this installation.',
        );
    }

    private function calls(Exposition $exposition): void
    {
        $since = now()->subHours(self::WINDOW_HOURS);

        foreach ($this->countByTeam('call_logs', 'created_at', $since) as $team => $count) {
            $exposition->gauge(
                'orbital_calls_recent',
                $count,
                $this->clientLabel($team),
                'Calls logged in the last '.self::WINDOW_HOURS.' hours.',
            );
        }

        // Direction and disposition split, platform-wide. Answers "are we
        // suddenly failing calls" without needing per-client breakdown.
        $rows = DB::table('call_logs')
            ->select('direction', 'status', DB::raw('count(*) as aggregate'))
            ->where('created_at', '>=', $since)
            ->groupBy('direction', 'status')
            ->get();

        foreach ($rows as $row) {
            $exposition->gauge(
                'orbital_calls_recent_by_status',
                (int) $row->aggregate,
                [
                    'direction' => (string) ($row->direction ?? 'unknown'),
                    'status' => (string) ($row->status ?? 'unknown'),
                ],
                'Calls in the last '.self::WINDOW_HOURS.' hours by direction and status.',
            );
        }

        $exposition->gauge(
            'orbital_calls_in_progress',
            DB::table('call_logs')->whereNull('ended_at')->whereNotNull('started_at')->count(),
            [],
            'Calls with a start but no end timestamp. A number that only grows means hangup events are being lost.',
        );
    }

    private function messages(Exposition $exposition): void
    {
        $since = now()->subHours(self::WINDOW_HOURS);

        foreach ($this->countByTeam('messages', 'created_at', $since) as $team => $count) {
            $exposition->gauge(
                'orbital_messages_taken_recent',
                $count,
                $this->clientLabel($team),
                'Messages taken on behalf of a client in the last '.self::WINDOW_HOURS.' hours.',
            );
        }

        $rows = DB::table('messages')
            ->select('team_id', DB::raw('count(*) as aggregate'))
            ->where('status', 'new')
            ->groupBy('team_id')
            ->get();

        foreach ($rows as $row) {
            $exposition->gauge(
                'orbital_messages_undelivered',
                (int) $row->aggregate,
                $this->clientLabel((int) $row->team_id),
                'Messages still marked new — taken but not yet read by the client.',
            );
        }
    }

    private function email(Exposition $exposition): void
    {
        $since = now()->subHours(self::WINDOW_HOURS);

        foreach ($this->countByTeam('email_messages', 'received_at', $since) as $team => $count) {
            $exposition->gauge(
                'orbital_emails_received_recent',
                $count,
                $this->clientLabel($team),
                'Inbound emails received in the last '.self::WINDOW_HOURS.' hours.',
            );
        }

        $rows = DB::table('email_threads')
            ->select('status', DB::raw('count(*) as aggregate'))
            ->groupBy('status')
            ->get();

        foreach ($rows as $row) {
            $exposition->gauge(
                'orbital_email_threads',
                (int) $row->aggregate,
                ['status' => (string) ($row->status ?? 'unknown')],
                'Email threads by status.',
            );
        }

        // Mail that arrived but couldn't be matched to a client. This is
        // the number that should be zero; anything else is mail nobody is
        // reading.
        $exposition->gauge(
            'orbital_emails_unrouted',
            DB::table('email_messages')->where('routing_status', 'unrouted')->count(),
            [],
            'Inbound emails that matched no routing rule.',
        );
    }

    private function voicemail(Exposition $exposition): void
    {
        $rows = DB::table('voicemails')
            ->select('transcription_status', DB::raw('count(*) as aggregate'))
            ->where('created_at', '>=', now()->subDays(7))
            ->groupBy('transcription_status')
            ->get();

        foreach ($rows as $row) {
            $exposition->gauge(
                'orbital_voicemails_recent',
                (int) $row->aggregate,
                ['transcription_status' => (string) ($row->transcription_status ?? 'unknown')],
                'Voicemails left in the last 7 days, by transcription outcome.',
            );
        }
    }

    private function chat(Exposition $exposition): void
    {
        foreach ($this->countByTeam('chat_sessions', 'last_activity_at', now()->subHour()) as $team => $count) {
            $exposition->gauge(
                'orbital_chat_sessions_active',
                $count,
                $this->clientLabel($team),
                'Chat sessions with activity in the last hour.',
            );
        }
    }

    /**
     * The messaging channel — SMS/MMS and the other text transports.
     */
    private function messaging(Exposition $exposition): void
    {
        $since = now()->subHours(self::WINDOW_HOURS);

        foreach ($this->countByTeam('message_entries', 'occurred_at', $since) as $team => $count) {
            $exposition->gauge(
                'orbital_messages_exchanged_recent',
                $count,
                $this->clientLabel($team),
                'Text messages sent or received in the last '.self::WINDOW_HOURS.' hours.',
            );
        }

        $rows = DB::table('message_threads')
            ->select('status', DB::raw('count(*) as aggregate'))
            ->groupBy('status')
            ->get();

        foreach ($rows as $row) {
            $exposition->gauge(
                'orbital_message_threads',
                (int) $row->aggregate,
                ['status' => (string) ($row->status ?? 'unknown')],
                'Message conversations by status.',
            );
        }

        // The number that matters most on this channel. An SMS the
        // carrier accepted and then failed to deliver leaves an operator
        // believing the customer was told something they never received.
        $exposition->gauge(
            'orbital_messages_undelivered_recent',
            DB::table('message_entries')
                ->where('occurred_at', '>=', $since)
                ->whereIn('delivery_status', ['undelivered', 'failed'])
                ->count(),
            [],
            'Outbound texts the carrier reported as not delivered in the last '.self::WINDOW_HOURS.' hours.',
        );

        $exposition->gauge(
            'orbital_messaging_endpoints_total',
            DB::table('messaging_endpoints')->where('is_active', true)->count(),
            [],
            'Active messaging endpoints (carrier sender pools or numbers) across all clients.',
        );

        // An endpoint that can't send compliantly. Worth watching
        // because the symptom is invisible until an operator tries to
        // reply: sending is refused rather than falling back to a bare
        // number, so a misconfigured pool looks like a working endpoint
        // right up to the first customer who needs an answer.
        $exposition->gauge(
            'orbital_messaging_endpoints_without_pool',
            DB::table('messaging_endpoints')
                ->where('is_active', true)
                ->where('provider', 'twilio')
                ->where(function ($query) {
                    $query->whereNull('sender_pool_id')->orWhere('sender_pool_id', '');
                })
                ->count(),
            [],
            'Active endpoints on a provider that requires a carrier sender pool but has none configured. Replies on these fail.',
        );

        // Suppressions are normal; a CLIMBING suppression rate is a
        // client texting people who don't want to hear from them, and
        // it is the leading indicator of a number being de-registered.
        $exposition->gauge(
            'orbital_messaging_opt_outs_total',
            DB::table('messaging_opt_outs')->whereNull('opted_in_at')->count(),
            [],
            'People currently on a client do-not-text list.',
        );
    }

    /**
     * The AI-vs-human split, which is the question this product exists to
     * answer. A call log carrying an agent_persona_id was fronted by an
     * AI agent; one carrying only a created-by operator was not.
     *
     * Deliberately derived from call_logs rather than from LiveKit's own
     * metrics: LiveKit knows about rooms, but only Orbital knows whether
     * a room was an answering-service call and which client it belonged
     * to.
     */
    private function ai(Exposition $exposition): void
    {
        $since = now()->subHours(self::WINDOW_HOURS);

        $aiHandled = DB::table('call_logs')
            ->where('created_at', '>=', $since)
            ->whereNotNull('agent_persona_id')
            ->count();

        $total = DB::table('call_logs')->where('created_at', '>=', $since)->count();

        $exposition->gauge(
            'orbital_calls_recent_by_handler',
            $aiHandled,
            ['handler' => 'ai'],
            'Calls in the last '.self::WINDOW_HOURS.' hours by who fronted them.',
        );

        $exposition->gauge(
            'orbital_calls_recent_by_handler',
            max(0, $total - $aiHandled),
            ['handler' => 'human'],
            'Calls in the last '.self::WINDOW_HOURS.' hours by who fronted them.',
        );

        // Messages taken by an AI agent vs by an operator at the console.
        // The handoff story is only credible if both numbers are non-zero.
        $exposition->gauge(
            'orbital_messages_recent_by_handler',
            DB::table('messages')->where('created_at', '>=', $since)->whereNotNull('agent_persona_id')->count(),
            ['handler' => 'ai'],
            'Messages taken in the last '.self::WINDOW_HOURS.' hours by who took them.',
        );

        $exposition->gauge(
            'orbital_messages_recent_by_handler',
            DB::table('messages')->where('created_at', '>=', $since)->whereNotNull('created_by_user_id')->count(),
            ['handler' => 'human'],
            'Messages taken in the last '.self::WINDOW_HOURS.' hours by who took them.',
        );

        $exposition->gauge(
            'orbital_knowledge_stores_total',
            DB::table('knowledge_stores')->count(),
            [],
            'Per-client knowledge stores backing the agent search_knowledge tool.',
        );

        $exposition->gauge(
            'orbital_knowledge_chunks_total',
            DB::table('knowledge_chunks')->count(),
            [],
            'Embedded knowledge chunks available for retrieval.',
        );
    }

    private function operators(Exposition $exposition): void
    {
        $rows = DB::table('users')
            ->select('availability_status', DB::raw('count(*) as aggregate'))
            ->groupBy('availability_status')
            ->get();

        foreach ($rows as $row) {
            $exposition->gauge(
                'orbital_operators_by_availability',
                (int) $row->aggregate,
                ['status' => (string) ($row->availability_status ?? 'unknown')],
                'Platform users by current availability status.',
            );
        }
    }

    /**
     * Horizon queue depth, read straight from the Valkey lists Horizon
     * uses. Cheaper and more robust than going through Horizon's own
     * repository classes, and it still works when the Horizon supervisor
     * process is the thing that's down — which is precisely the moment
     * you want this number.
     */
    private function queues(Exposition $exposition): void
    {
        $connection = Redis::connection(config('horizon.use', 'default'));
        $prefix = (string) config('horizon.prefix', 'horizon:');

        foreach ($this->horizonQueues() as $queue) {
            $exposition->gauge(
                'orbital_queue_depth',
                (int) $connection->llen($queue),
                ['queue' => $queue],
                'Jobs waiting in a queue.',
            );

            $exposition->gauge(
                'orbital_queue_delayed',
                (int) $connection->zcard($queue.':delayed'),
                ['queue' => $queue],
                'Jobs scheduled for later execution.',
            );

            $exposition->gauge(
                'orbital_queue_reserved',
                (int) $connection->zcard($queue.':reserved'),
                ['queue' => $queue],
                'Jobs currently held by a worker.',
            );
        }

        $exposition->gauge(
            'orbital_failed_jobs_total',
            DB::table('failed_jobs')->count(),
            [],
            'Rows in failed_jobs. Cleared only by queue:flush or a successful retry.',
        );

        $exposition->gauge(
            'orbital_horizon_workload_pending',
            (int) $connection->zcard($prefix.'recent_jobs'),
            [],
            'Jobs Horizon has seen recently. Zero while nothing is being processed.',
        );
    }

    private function inventory(Exposition $exposition): void
    {
        $tables = [
            'orbital_extensions_total' => ['extensions', 'SIP/WebRTC/agent extensions configured.'],
            'orbital_sip_trunks_total' => ['sip_trunks', 'External SIP provider connections configured.'],
            'orbital_call_queues_total' => ['call_queues', 'Call queues configured.'],
            'orbital_agent_personas_total' => ['agent_personas', 'AI agent personas configured.'],
            'orbital_orchestrations_total' => ['orchestrations', 'Orchestrations (flow bundles) configured.'],
        ];

        foreach ($tables as $metric => [$table, $help]) {
            $exposition->gauge($metric, DB::table($table)->count(), [], $help);
        }
    }

    /**
     * Queue names Horizon is configured to watch, flattened across every
     * supervisor and environment block.
     *
     * @return array<int, string>
     */
    /**
     * When the database was last backed up successfully.
     *
     * The metric that matters here is the ABSENCE of a recent one. A
     * backup system that stops working announces nothing: the job
     * fails, the log line scrolls past, and everything looks fine until
     * the day it does not. Exposing the last-success timestamp lets the
     * OrbitalBackupStale rule alert on silence, which is the only
     * symptom this failure has.
     *
     * Emitted even when backups are disabled — as an explicit zero, not
     * as a missing series. "Backups are off" and "the metrics pipeline
     * broke" must not look identical on a dashboard.
     */
    private function backups(Exposition $exposition): void
    {
        $enabled = (bool) config('backup.enabled');

        $exposition->gauge(
            'orbital_backup_enabled',
            $enabled ? 1 : 0,
            [],
            'Whether scheduled database backups are turned on.',
        );

        // Read straight off the model rather than through
        // PlatformSettingsRepository. The repository caches in Valkey,
        // and a cache outage would make these two series vanish while
        // orbital_backup_enabled kept reporting — the exact shape of
        // "looks fine, is not" that this metric exists to catch.
        $last = $this->setting(BackupService::LAST_SUCCESS_KEY);
        $timestamp = 0;

        if (is_string($last) && $last !== '') {
            try {
                $timestamp = Carbon::parse($last)->getTimestamp();
            } catch (\Throwable) {
                $timestamp = 0;
            }
        }

        $exposition->gauge(
            'orbital_backup_last_success_timestamp_seconds',
            $timestamp,
            [],
            'Unix time of the last successful database backup. Zero means none has ever succeeded.',
        );

        $exposition->gauge(
            'orbital_backup_last_bytes',
            (int) ($this->setting(BackupService::LAST_BYTES_KEY) ?? 0),
            [],
            'Size of the last successful backup archive, encrypted.',
        );
    }

    private function setting(string $key): mixed
    {
        return PlatformSetting::query()->where('key', $key)->first()?->value;
    }

    private function horizonQueues(): array
    {
        $queues = [];

        foreach ((array) config('horizon.defaults', []) as $supervisor) {
            foreach ((array) ($supervisor['queue'] ?? []) as $queue) {
                $queues[] = (string) $queue;
            }
        }

        foreach ((array) config('horizon.environments', []) as $supervisors) {
            foreach ((array) $supervisors as $supervisor) {
                foreach ((array) ($supervisor['queue'] ?? []) as $queue) {
                    $queues[] = (string) $queue;
                }
            }
        }

        return array_values(array_unique($queues ?: ['default']));
    }

    /**
     * Row counts grouped by team, for rows newer than $since.
     *
     * @return array<int, int> team_id => count
     */
    private function countByTeam(string $table, string $column, \DateTimeInterface $since): array
    {
        $rows = DB::table($table)
            ->select('team_id', DB::raw('count(*) as aggregate'))
            ->where($column, '>=', $since)
            ->whereNotNull('team_id')
            ->groupBy('team_id')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row->team_id] = (int) $row->aggregate;
        }

        return $out;
    }

    /**
     * Per-client label, or an empty label set when per-client series are
     * disabled. Uses the team id rather than the name: names are edited
     * by admins, and a renamed client would otherwise orphan its history
     * into a new series.
     *
     * @return array<string, string>
     */
    private function clientLabel(int $teamId): array
    {
        if (! config('metrics.per_client_labels', true)) {
            return [];
        }

        return ['client_id' => (string) $teamId];
    }

    private function guard(string $family, callable $collector): void
    {
        try {
            $collector();
        } catch (\Throwable $e) {
            // One broken subsystem must not empty the entire push.
            Log::warning('Metrics collection failed for '.$family.': '.$e->getMessage());
        }
    }
}
