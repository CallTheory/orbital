<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Move `wrapup_time` from QueueStrategyTemplate down to the
 * individual call queue. Strategy templates remain about *ring*
 * behavior (strategy + timeout + retry) and live at the platform
 * level; wrapup is a per-call-type tuning knob — sales calls need
 * post-call CRM logging, FAQ calls don't — even when both queues
 * share the same agent group.
 *
 * Asterisk's `queues` table carries `wrapuptime` per row, so
 * pushing it down to call_queues maps cleanly: one column on
 * call_queues → one column in the synced ARA queues row.
 *
 * Email queues are untouched — they don't have a wrapup concept
 * (no Asterisk involvement, async thread handling, an operator can
 * hold many simultaneously).
 *
 * Backfill: for each existing call queue, copy the wrapup from its
 * group's strategy template; default 0 if the group / template
 * chain is broken.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('call_queues', function (Blueprint $table) {
            $table->unsignedInteger('wrapup_time')->default(0)->after('music_on_hold');
        });

        $this->backfillCallQueueWrapup();

        Schema::table('queue_strategy_templates', function (Blueprint $table) {
            if (Schema::hasColumn('queue_strategy_templates', 'wrapup_time')) {
                $table->dropColumn('wrapup_time');
            }
        });
    }

    public function down(): void
    {
        Schema::table('queue_strategy_templates', function (Blueprint $table) {
            if (! Schema::hasColumn('queue_strategy_templates', 'wrapup_time')) {
                $table->unsignedInteger('wrapup_time')->default(0);
            }
        });

        Schema::table('call_queues', function (Blueprint $table) {
            $table->dropColumn('wrapup_time');
        });
    }

    private function backfillCallQueueWrapup(): void
    {
        // Each queue inherits its group's template wrapup. Queues
        // without a group / without a template default to 0 — the
        // safe Asterisk default ("agent ready immediately").
        $rows = DB::table('call_queues as q')
            ->leftJoin('agent_groups as g', 'g.id', '=', 'q.agent_group_id')
            ->leftJoin('queue_strategy_templates as t', 't.id', '=', 'g.strategy_template_id')
            ->select('q.id', 't.wrapup_time')
            ->get();

        foreach ($rows as $row) {
            DB::table('call_queues')
                ->where('id', $row->id)
                ->update(['wrapup_time' => (int) ($row->wrapup_time ?? 0)]);
        }
    }
};
