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
    ->withoutOverlapping();

// Walk every call log past its tenant's retention window, delete the
// S3 objects, and null the columns. Runs at 03:15 local so overnight
// upload jobs have already settled.
Schedule::job(new PruneExpiredCallRecordingsJob())
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
