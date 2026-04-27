<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Aggregates `queue_log` events for a given date into one row per
 * queue per day in `queue_metrics_daily`. The dashboard reads from
 * the aggregate table — never from the raw firehose — so per-client
 * per-day stats stay cheap regardless of call volume.
 *
 * Idempotent. Re-running for the same date wipes that day's
 * aggregates first, so a partially-rolled-up day can be redone
 * cleanly. Old `queue_log` rows (older than 90 days by default)
 * are pruned in the same pass to keep the firehose bounded.
 *
 * Schedule via routes/console.php to run nightly at 02:00 against
 * yesterday's date.
 *
 *   ./vendor/bin/sail artisan orbital:roll-up-queue-metrics
 *   ./vendor/bin/sail artisan orbital:roll-up-queue-metrics --date=2026-04-13
 *   ./vendor/bin/sail artisan orbital:roll-up-queue-metrics --keep-days=180
 */
class RollUpQueueMetricsCommand extends Command
{
    protected $signature = 'orbital:roll-up-queue-metrics
        {--date= : Specific date (YYYY-MM-DD). Defaults to yesterday.}
        {--keep-days=90 : Prune queue_log rows older than this. 0 to keep forever.}';

    protected $description = 'Aggregate one day of queue_log events into queue_metrics_daily, then prune old log rows.';

    public function handle(): int
    {
        $date = $this->option('date')
            ? Carbon::parse($this->option('date'))->startOfDay()
            : Carbon::yesterday()->startOfDay();
        $end = (clone $date)->endOfDay();

        $this->info("Rolling up queue_log for {$date->toDateString()}...");

        // Wipe any existing row for this date so re-runs are
        // idempotent. The unique constraint on (queue_name, date)
        // would otherwise fight us.
        DB::table('queue_metrics_daily')
            ->whereDate('date', $date)
            ->delete();

        // Asterisk's queue_log events that count for our metrics:
        //   ENTERQUEUE  → caller offered the queue
        //   CONNECT     → caller answered (data1 = wait seconds)
        //   ABANDON     → caller hung up before answer (data3 = wait seconds)
        //   COMPLETEAGENT / COMPLETECALLER → call ended (data2 = talk seconds)
        $rows = DB::table('queue_log')
            ->whereBetween('time', [$date, $end])
            ->whereNotNull('queuename')
            ->select('queuename', 'event', 'data1', 'data2', 'data3')
            ->get();

        $byQueue = [];
        foreach ($rows as $row) {
            $q = $row->queuename;
            if (! isset($byQueue[$q])) {
                $byQueue[$q] = [
                    'calls_offered' => 0,
                    'calls_answered' => 0,
                    'calls_abandoned' => 0,
                    'total_wait_seconds' => 0,
                    'total_talk_seconds' => 0,
                    'max_wait_seconds' => 0,
                ];
            }

            switch ($row->event) {
                case 'ENTERQUEUE':
                    $byQueue[$q]['calls_offered']++;
                    break;
                case 'CONNECT':
                    $byQueue[$q]['calls_answered']++;
                    $wait = (int) ($row->data1 ?? 0);
                    $byQueue[$q]['total_wait_seconds'] += $wait;
                    if ($wait > $byQueue[$q]['max_wait_seconds']) {
                        $byQueue[$q]['max_wait_seconds'] = $wait;
                    }
                    break;
                case 'ABANDON':
                    $byQueue[$q]['calls_abandoned']++;
                    $wait = (int) ($row->data3 ?? 0);
                    $byQueue[$q]['total_wait_seconds'] += $wait;
                    if ($wait > $byQueue[$q]['max_wait_seconds']) {
                        $byQueue[$q]['max_wait_seconds'] = $wait;
                    }
                    break;
                case 'COMPLETEAGENT':
                case 'COMPLETECALLER':
                    $byQueue[$q]['total_talk_seconds'] += (int) ($row->data2 ?? 0);
                    break;
            }
        }

        $now = now();
        $insertCount = 0;
        foreach ($byQueue as $queueName => $stats) {
            $answered = $stats['calls_answered'];
            $abandoned = $stats['calls_abandoned'];
            $totalCompletedOrAbandoned = max($answered + $abandoned, 1);

            DB::table('queue_metrics_daily')->insert([
                'queue_name' => $queueName,
                'team_id' => $this->teamIdForQueueName($queueName),
                'date' => $date->toDateString(),
                'calls_offered' => $stats['calls_offered'],
                'calls_answered' => $answered,
                'calls_abandoned' => $abandoned,
                'total_wait_seconds' => $stats['total_wait_seconds'],
                'total_talk_seconds' => $stats['total_talk_seconds'],
                'avg_wait_seconds' => intdiv($stats['total_wait_seconds'], $totalCompletedOrAbandoned),
                'avg_talk_seconds' => $answered > 0 ? intdiv($stats['total_talk_seconds'], $answered) : 0,
                'max_wait_seconds' => $stats['max_wait_seconds'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $insertCount++;
        }

        $this->info("  → {$insertCount} queue(s) aggregated.");

        // Prune old queue_log rows so the firehose stays bounded.
        $keepDays = (int) $this->option('keep-days');
        if ($keepDays > 0) {
            $cutoff = now()->subDays($keepDays);
            $pruned = DB::table('queue_log')->where('time', '<', $cutoff)->delete();
            if ($pruned > 0) {
                $this->info("  → Pruned {$pruned} queue_log row(s) older than {$cutoff->toDateString()}.");
            }
        }

        return self::SUCCESS;
    }

    /**
     * Recover the team_id from a queue name like `t42_support`.
     * Returns null for platform queues (no `t<digits>_` prefix).
     */
    protected function teamIdForQueueName(string $name): ?int
    {
        if (preg_match('/^t(\d+)_/', $name, $m)) {
            return (int) $m[1];
        }

        return null;
    }
}
