<?php

declare(strict_types=1);

namespace App\Services\Metrics;

use App\Models\RtpengineNode;
use App\Services\Telephony\AsteriskAmiService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Telephony metrics — live Asterisk channel state plus queue activity.
 *
 * Asterisk ships no Prometheus exporter, and the community ones wrap the
 * same AMI commands we already speak fluently through AsteriskAmiService.
 * Rather than vendor another container, this collector polls AMI on the
 * metrics schedule and reads the realtime `queue_log` table that
 * logger.conf already populates (queue_log_realtime = yes) for queue
 * activity. Two sources we own, no new moving parts.
 *
 * `queue_log` is the good one: because app_queue writes ENTERQUEUE /
 * CONNECT / ABANDON straight to Postgres, per-queue offered/answered/
 * abandoned counts are a plain SQL aggregate rather than a log tail.
 * RollUpQueueMetricsCommand aggregates the same table nightly for
 * long-term reporting — this collector reads the recent window for live
 * dashboards, so the two never fight over the same rows.
 */
class TelephonyMetricsCollector
{
    /** Rolling window for live queue-activity gauges. */
    private const WINDOW_MINUTES = 15;

    public function __construct(
        private readonly AsteriskAmiService $ami,
    ) {}

    /**
     * @param  Exposition|null  $exposition  write into an existing
     *                                       exposition when several
     *                                       collectors share one payload
     */
    public function collect(?Exposition $exposition = null): Exposition
    {
        $exposition ??= new Exposition;

        $this->guard('asterisk', fn () => $this->asterisk($exposition));
        $this->guard('queues', fn () => $this->queueActivity($exposition));
        $this->guard('endpoints', fn () => $this->endpoints($exposition));
        $this->guard('rtpengine', fn () => $this->rtpengine($exposition));

        return $exposition;
    }

    /**
     * Live channel count via `core show channels concise`.
     *
     * AMI reachability is itself the most valuable signal here: if this
     * flips to 0 while calls are still arriving at the trunk, the problem
     * is Asterisk, not the carrier.
     */
    private function asterisk(Exposition $exposition): void
    {
        $channels = $this->ami->getActiveChannels();

        // getActiveChannels() returns [] both for "no calls" and for
        // "couldn't connect", so probe reachability separately rather
        // than inferring it from an empty list and reporting a healthy
        // idle PBX as down (or a dead one as idle). The socket probe
        // only runs when the channel list is empty, so a busy PBX costs
        // one AMI round trip, not two.
        $reachable = $channels !== [] || $this->amiReachable();

        $exposition->gauge(
            'orbital_asterisk_up',
            $reachable ? 1 : 0,
            [],
            'Whether Asterisk AMI answered the last poll.',
        );

        if (! $reachable) {
            return;
        }

        $exposition->gauge(
            'orbital_asterisk_active_channels',
            count($channels),
            [],
            'Channels currently up on Asterisk, per `core show channels concise`.',
        );

        // The concise format is bang-delimited; field 4 is the channel
        // state name (Up, Ringing, Ring, Dialing...). Grouping by it
        // separates "answered and talking" from "ringing and nobody is
        // picking up", which is the distinction that matters at 9am.
        $byState = [];
        foreach ($channels as $line) {
            $fields = explode('!', $line);
            $state = trim($fields[4] ?? '') ?: 'unknown';
            $byState[$state] = ($byState[$state] ?? 0) + 1;
        }

        foreach ($byState as $state => $count) {
            $exposition->gauge(
                'orbital_asterisk_channels_by_state',
                $count,
                ['state' => $state],
                'Active Asterisk channels by channel state.',
            );
        }
    }

    /**
     * Queue offered/answered/abandoned over the recent window, straight
     * from the realtime queue_log table.
     */
    private function queueActivity(Exposition $exposition): void
    {
        $since = now()->subMinutes(self::WINDOW_MINUTES);

        $rows = DB::table('queue_log')
            ->select('queuename', 'event', DB::raw('count(*) as aggregate'))
            ->where('time', '>=', $since)
            ->whereNotNull('queuename')
            ->whereIn('event', ['ENTERQUEUE', 'CONNECT', 'ABANDON', 'EXITWITHTIMEOUT', 'RINGNOANSWER'])
            ->groupBy('queuename', 'event')
            ->get();

        foreach ($rows as $row) {
            $exposition->gauge(
                'orbital_queue_events_recent',
                (int) $row->aggregate,
                [
                    'queue' => (string) $row->queuename,
                    'event' => (string) $row->event,
                ],
                'Queue events in the last '.self::WINDOW_MINUTES.' minutes (ENTERQUEUE offered, CONNECT answered, ABANDON hung up waiting).',
            );
        }

        // Wait time is the number a call center actually manages to.
        // data1 on CONNECT carries wait seconds.
        //
        // Postgres-specific: `~` and `cast(... as double precision)`.
        // That's fine — Asterisk realtime requires Postgres here — but
        // it means this family is the one that degrades (guarded, with a
        // log line) on a sqlite test database.
        $waits = DB::table('queue_log')
            ->select('queuename', DB::raw('avg(cast(data1 as double precision)) as avg_wait'), DB::raw('max(cast(data1 as double precision)) as max_wait'))
            ->where('time', '>=', $since)
            ->where('event', 'CONNECT')
            ->whereNotNull('queuename')
            ->where('data1', '~', '^[0-9]+$')
            ->groupBy('queuename')
            ->get();

        foreach ($waits as $row) {
            $exposition->gauge(
                'orbital_queue_wait_seconds_avg',
                (float) $row->avg_wait,
                ['queue' => (string) $row->queuename],
                'Mean answered-call wait in the last '.self::WINDOW_MINUTES.' minutes.',
            );
            $exposition->gauge(
                'orbital_queue_wait_seconds_max',
                (float) $row->max_wait,
                ['queue' => (string) $row->queuename],
                'Longest answered-call wait in the last '.self::WINDOW_MINUTES.' minutes.',
            );
        }
    }

    /**
     * PJSIP endpoint registration state, read from the ARA contact table
     * rather than AMI. `ps_contacts` is written by Asterisk itself on
     * every REGISTER, so a row with a live expiry means that phone is
     * currently reachable — no command parsing required.
     */
    private function endpoints(Exposition $exposition): void
    {
        $registered = DB::table('ps_contacts')
            ->where('expiration_time', '>', now()->getTimestampMs())
            ->count();

        $exposition->gauge(
            'orbital_sip_contacts_registered',
            $registered,
            [],
            'PJSIP contacts with an unexpired registration.',
        );

        $exposition->gauge(
            'orbital_sip_endpoints_total',
            DB::table('ps_endpoints')->count(),
            [],
            'PJSIP endpoints provisioned in the realtime tables.',
        );
    }

    private function rtpengine(Exposition $exposition): void
    {
        $exposition->gauge(
            'orbital_rtpengine_nodes',
            RtpengineNode::query()->count(),
            ['state' => 'registered'],
            'rtpengine nodes known to the platform.',
        );

        $exposition->gauge(
            'orbital_rtpengine_nodes',
            RtpengineNode::active()->count(),
            ['state' => 'active'],
            'rtpengine nodes known to the platform.',
        );
    }

    /**
     * Cheap liveness probe that doesn't depend on there being calls up.
     */
    private function amiReachable(): bool
    {
        $host = (string) config('telephony.asterisk.ami.host');
        $port = (int) config('telephony.asterisk.ami.port');

        if ($host === '' || $port === 0) {
            return false;
        }

        $socket = @fsockopen($host, $port, $errno, $errstr, 2);

        if ($socket === false) {
            return false;
        }

        fclose($socket);

        return true;
    }

    private function guard(string $family, callable $collector): void
    {
        try {
            $collector();
        } catch (\Throwable $e) {
            Log::warning('Telephony metrics collection failed for '.$family.': '.$e->getMessage());
        }
    }
}
