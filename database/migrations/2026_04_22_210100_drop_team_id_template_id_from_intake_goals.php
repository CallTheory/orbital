<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drop `team_id` and `template_id` from `intake_goals`. After the
 * preceding data migration remapped every team-scoped goal to its
 * library equivalent, the catalog is uniformly platform-level and
 * these columns (plus their indexes and foreign keys) are dead
 * weight. Keeping them encourages the drift that got us here —
 * seeders bypassing the library and spawning per-client duplicates.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('intake_goals')) {
            return;
        }

        Schema::table('intake_goals', function (Blueprint $table) {
            // FKs first, then the composite index that references team_id,
            // then the single-column indexes, then the columns themselves.
            if (Schema::hasColumn('intake_goals', 'team_id')) {
                $table->dropForeign(['team_id']);
            }
            if (Schema::hasColumn('intake_goals', 'template_id')) {
                $table->dropForeign(['template_id']);
            }
            $table->dropIndex('intake_goals_team_id_key_index');
            $table->dropIndex('intake_goals_team_id_index');
            $table->dropIndex('intake_goals_template_id_index');
            $table->dropColumn(['team_id', 'template_id']);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('intake_goals')) {
            return;
        }

        Schema::table('intake_goals', function (Blueprint $table) {
            $table->foreignId('team_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            $table->foreignId('template_id')->nullable()->after('team_id')->constrained('intake_goals')->nullOnDelete();
            $table->index(['team_id', 'key']);
        });
    }
};
