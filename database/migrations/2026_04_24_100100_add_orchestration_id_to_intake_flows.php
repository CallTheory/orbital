<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reparent every existing intake_flow into a freshly-created
 * "Default" orchestration per team. After backfill the column
 * becomes NOT NULL — every flow lives inside an orchestration from
 * here on.
 *
 * Cascade-delete the orchestration → and every intake_flow attached
 * to it goes too. Combined with existing FK cascades on steps /
 * transitions / rules, deleting an orchestration cleanly wipes its
 * whole subtree.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('intake_flows', function (Blueprint $table) {
            $table->foreignId('orchestration_id')
                ->nullable()
                ->after('team_id')
                ->constrained('orchestrations')
                ->cascadeOnDelete();
        });

        // Backfill: create one Default orchestration per team,
        // reparent every flow already owned by that team.
        $teamIds = DB::table('intake_flows')
            ->distinct()
            ->pluck('team_id')
            ->filter()
            ->all();

        foreach ($teamIds as $teamId) {
            $orchestrationId = DB::table('orchestrations')->insertGetId([
                'team_id' => $teamId,
                'name' => 'Default',
                'description' => 'Default orchestration — created automatically during the orchestrations rollout.',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('intake_flows')
                ->where('team_id', $teamId)
                ->update(['orchestration_id' => $orchestrationId]);
        }

        // Now enforce NOT NULL — every future flow must belong to an
        // orchestration. Safe because the backfill above covered
        // existing rows.
        Schema::table('intake_flows', function (Blueprint $table) {
            $table->foreignId('orchestration_id')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('intake_flows', function (Blueprint $table) {
            $table->dropForeign(['orchestration_id']);
            $table->dropColumn('orchestration_id');
        });
    }
};
