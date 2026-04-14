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
