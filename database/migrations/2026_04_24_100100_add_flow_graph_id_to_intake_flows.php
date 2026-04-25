<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reparent every existing intake_flow into a freshly-created
 * "Default" flow graph per team. After backfill the column becomes
 * NOT NULL — every flow lives inside a graph from here on.
 *
 * Cascade-delete the graph → and every intake_flow attached to it
 * goes too. Combined with existing FK cascades on steps /
 * transitions / rules, deleting a graph cleanly wipes its whole
 * subtree.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('intake_flows', function (Blueprint $table) {
            $table->foreignId('flow_graph_id')
                ->nullable()
                ->after('team_id')
                ->constrained('flow_graphs')
                ->cascadeOnDelete();
        });

        // Backfill: create one Default graph per team, reparent every
        // flow already owned by that team.
        $teamIds = DB::table('intake_flows')
            ->distinct()
            ->pluck('team_id')
            ->filter()
            ->all();

        foreach ($teamIds as $teamId) {
            $graphId = DB::table('flow_graphs')->insertGetId([
                'team_id' => $teamId,
                'name' => 'Default',
                'description' => 'Default flow graph — created automatically during the graph-aggregate migration.',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('intake_flows')
                ->where('team_id', $teamId)
                ->update(['flow_graph_id' => $graphId]);
        }

        // Now enforce NOT NULL — every future flow must belong to a
        // graph. Safe because the backfill above covered existing
        // rows.
        Schema::table('intake_flows', function (Blueprint $table) {
            $table->foreignId('flow_graph_id')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('intake_flows', function (Blueprint $table) {
            $table->dropForeign(['flow_graph_id']);
            $table->dropColumn('flow_graph_id');
        });
    }
};
