<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add `strategy_template_id` to `call_queues`, then backfill every
 * existing row to the nearest matching QueueStrategyTemplate.
 *
 * The legacy `strategy` / `timeout` / `retry` / `wrapup_time`
 * columns stay for one release so the QueueSyncer fallback keeps
 * working — a follow-up migration drops them once the UI and
 * runtime fully read through the template.
 *
 * "Nearest match" picks a template whose (strategy, timeout, retry,
 * wrapup_time) exactly equals the queue's current values. If
 * nothing matches we point at whichever template has
 * `is_default=true` — gives us a sane fallback without losing data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('call_queues', function (Blueprint $table) {
            if (! Schema::hasColumn('call_queues', 'strategy_template_id')) {
                $table->foreignId('strategy_template_id')
                    ->nullable()
                    ->after('strategy')
                    ->constrained('queue_strategy_templates')
                    ->nullOnDelete();
            }
        });

        // Backfill. Run only when templates exist; the seeder populates
        // them right after this migration.
        $templates = DB::table('queue_strategy_templates')->get();
        if ($templates->isEmpty()) {
            return;
        }
        $default = $templates->firstWhere('is_default', true) ?? $templates->first();

        foreach (DB::table('call_queues')->get() as $queue) {
            $match = $templates->first(fn ($t) => $t->strategy === $queue->strategy
                && (int) $t->timeout === (int) $queue->timeout
                && (int) $t->retry === (int) $queue->retry
                && (int) $t->wrapup_time === (int) $queue->wrapup_time);

            DB::table('call_queues')
                ->where('id', $queue->id)
                ->update(['strategy_template_id' => ($match ?? $default)->id]);
        }
    }

    public function down(): void
    {
        Schema::table('call_queues', function (Blueprint $table) {
            $table->dropForeign(['strategy_template_id']);
            $table->dropColumn('strategy_template_id');
        });
    }
};
