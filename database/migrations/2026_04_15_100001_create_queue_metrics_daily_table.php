<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-day roll-up of queue activity. Aggregated nightly from the
 * raw `queue_log` table (which Asterisk writes directly via the
 * queue_log realtime backend) by `orbital:roll-up-queue-metrics`.
 *
 * One row per (queue, date). The dashboard reads from this table
 * instead of scanning queue_log live, so the per-queue stats widget
 * stays cheap even at 1000 tenants × N queues × 30 days × 1000s of
 * events per day.
 *
 * The roll-up command also prunes queue_log rows older than 90
 * days so the firehose doesn't grow unbounded; the daily
 * aggregates here are the long-lived record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('queue_metrics_daily', function (Blueprint $table) {
            $table->id();

            // queue_name matches `queues.name` (i.e. the Asterisk-side
            // prefixed name, e.g. `t42_support`). Not a foreign key
            // because queues.name is a varchar PK in the ARA table
            // and we may need to retain metrics rows for queues that
            // have been deleted (historical reporting).
            $table->string('queue_name', 128);

            // Optional team_id — derived from the queue name prefix
            // when we can parse it; null for platform queues. Not a
            // foreign key on purpose: historical metrics rows should
            // survive tenant deletion for billing/audit. We index
            // it (below) so tenant-scoped dashboard queries stay
            // cheap, but the row keeps existing if the team is gone.
            $table->unsignedBigInteger('team_id')->nullable();

            $table->date('date');

            $table->unsignedInteger('calls_offered')->default(0);
            $table->unsignedInteger('calls_answered')->default(0);
            $table->unsignedInteger('calls_abandoned')->default(0);
            $table->unsignedInteger('total_wait_seconds')->default(0);
            $table->unsignedInteger('total_talk_seconds')->default(0);
            $table->unsignedInteger('avg_wait_seconds')->default(0);
            $table->unsignedInteger('avg_talk_seconds')->default(0);
            $table->unsignedInteger('max_wait_seconds')->default(0);

            $table->timestamps();

            $table->unique(['queue_name', 'date']);
            $table->index(['team_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('queue_metrics_daily');
    }
};
