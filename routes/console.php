<?php

use App\Jobs\PruneExpiredCallRecordingsJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

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
