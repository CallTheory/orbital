<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Promote ring strategy from per-client `call_queues` up to the
 * platform-level `agent_groups`. Asterisk's queue strategy is a
 * property of the agent pool, not the client account that happens to
 * point at it — letting every client tune `strategy / timeout / retry /
 * wrapup` per-queue doesn't scale and produces nonsense permutations.
 *
 * After this migration:
 *   - `agent_groups.strategy_template_id` carries the template the
 *     group's queue rows are synced with.
 *   - `call_queues` loses `strategy_template_id` along with the legacy
 *     scalar columns (`strategy`, `timeout`, `retry`, `wrapup_time`).
 *   - `call_queues` also loses `max_callers` (handled by per-account
 *     concurrent-call quotas) and the confusing `join_empty` /
 *     `leave_when_empty` knobs (overflow_agent_persona_id covers the
 *     "nobody answered" case end-to-end).
 *
 * Backfill: for each group, copy the first non-null
 * `strategy_template_id` we find on any queue using it. Falls back to
 * whichever template has `is_default=true`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_groups', function (Blueprint $table) {
            $table->foreignId('strategy_template_id')
                ->nullable()
                ->after('description')
                ->constrained('queue_strategy_templates')
                ->nullOnDelete();
        });

        $this->backfillGroupStrategies();

        Schema::table('call_queues', function (Blueprint $table) {
            if (Schema::hasColumn('call_queues', 'strategy_template_id')) {
                $table->dropForeign(['strategy_template_id']);
                $table->dropColumn('strategy_template_id');
            }
            foreach (['strategy', 'timeout', 'retry', 'wrapup_time', 'max_callers', 'join_empty', 'leave_when_empty'] as $col) {
                if (Schema::hasColumn('call_queues', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }

    public function down(): void
    {
        // Forward-only — recreating the legacy columns would lose
        // strategy info that's now consolidated on the group.
        Schema::table('agent_groups', function (Blueprint $table) {
            if (Schema::hasColumn('agent_groups', 'strategy_template_id')) {
                $table->dropForeign(['strategy_template_id']);
                $table->dropColumn('strategy_template_id');
            }
        });
    }

    private function backfillGroupStrategies(): void
    {
        $defaultId = DB::table('queue_strategy_templates')
            ->where('is_default', true)
            ->value('id')
            ?? DB::table('queue_strategy_templates')->value('id');

        foreach (DB::table('agent_groups')->get() as $group) {
            $sample = DB::table('call_queues')
                ->where('agent_group_id', $group->id)
                ->whereNotNull('strategy_template_id')
                ->orderBy('id')
                ->value('strategy_template_id');

            DB::table('agent_groups')
                ->where('id', $group->id)
                ->update(['strategy_template_id' => $sample ?? $defaultId]);
        }
    }
};
