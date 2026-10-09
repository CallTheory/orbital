<?php

use App\Jobs\PruneExpiredCallRecordingsJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Heartbeat touched every minute by the scheduler. The system health
// dashboard alarms if this key goes stale — it's the only thing that
// catches a crashed/unstarted scheduler container, since nothing else
// depends on it at minute granularity.
//
// Stored forever, not with a TTL: the value's age is the signal we
// read, and expiring the key would silently reset the DOWN card
// back to "no heartbeat yet" (WARN) once the TTL elapsed, hiding a
// long-dead scheduler behind a "still starting" message.
Schedule::call(fn () => Cache::forever('scheduler:heartbeat', now()->toIso8601String()))
    ->everyMinute()
    ->name('scheduler-heartbeat')
    ->withoutOverlapping()
    // onOneServer() takes a cache lock so only one scheduler replica
    // fires this tick. Harmless for this specific task (two writes
    // of the same timestamp is a no-op) but kept here to reinforce
    // the pattern: EVERY scheduled task in this file should carry
    // onOneServer() so prod can run N scheduler containers safely.
    ->onOneServer();

// Walk every call log past its client's retention window, delete the
// S3 objects, and null the columns. Runs at 03:15 local so overnight
// upload jobs have already settled.
Schedule::job(new PruneExpiredCallRecordingsJob)
    ->dailyAt('03:15')
    ->name('prune-expired-call-recordings')
    ->withoutOverlapping()
    ->onOneServer();

// Aggregate yesterday's queue_log events into queue_metrics_daily and
// prune queue_log rows older than 90 days. Runs at 02:00 so it finishes
// well before the recording prune kicks off and well after the day has
// ended in any reasonable timezone.
Schedule::command('orbital:roll-up-queue-metrics')
    ->dailyAt('02:00')
    ->name('roll-up-queue-metrics')
    ->withoutOverlapping()
    ->onOneServer();

// Encrypted database backup to the configured off-box destination.
//
// 03:00 rather than 02:00 so it runs AFTER the queue-metrics rollup and
// its 90-day prune — backing up first would capture rows that are about
// to be deleted, making every archive slightly larger than the database
// it came from for no benefit.
//
// onOneServer() because two replicas dumping the same database at the
// same moment doubles the load on Postgres to produce two archives that
// differ only in filename.
//
// Guarded on config rather than always-scheduled: an install with no
// destination configured would otherwise fail nightly and train whoever
// reads the logs to ignore backup errors.
if (config('backup.enabled')) {
    Schedule::command('orbital:backup')
        ->dailyAt('03:00')
        ->name('backup')
        ->withoutOverlapping()
        ->onOneServer();
}

// Collect deployment-wide metrics (per-client volume, queue depth,
// Asterisk channel state, SIP registrations) and push them to
// Pushgateway. Every minute matches Prometheus's scrape cadence closely
// enough for the dashboards without hammering AMI.
//
// onOneServer() is load-bearing here, not just hygiene: these are
// installation-wide numbers, so a second replica pushing them would
// overwrite the first's series with an identical payload at best, and
// interleave two half-collected snapshots at worst.
Schedule::command('orbital:collect-metrics')
    ->everyMinute()
    ->name('collect-metrics')
    ->withoutOverlapping()
    ->onOneServer();

// Drain rtpengine recording-daemon spool dirs into CallRecording rows.
// Polled rather than inotify-driven so we don't need a sidecar
// watcher container per node. Once-a-minute is fine — the upload
// job is idempotent (deletes the source after success), and the
// command skips files modified within the last couple of minutes,
// since recording-daemon writes in place until the call ends.
Schedule::command('orbital:upload-recordings')
    ->everyMinute()
    ->name('upload-rtpengine-recordings')
    ->withoutOverlapping()
    ->onOneServer();

// Kubernetes only: keep the Asterisk backend registry in step with the
// Asterisk pods so the SIP Proxy page drains and counts the real nodes.
Schedule::command('orbital:sync-asterisk-backends')
    ->everyMinute()
    ->name('sync-asterisk-backends')
    ->withoutOverlapping()
    ->onOneServer()
    ->when(fn (): bool => (string) config('telephony.kamailio.asterisk_discovery_host', '') !== '');
