<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * NULL `orchestrations.team_id` signals a platform-shared orchestration:
 * one row that any client can wire into their queues without being
 * duplicated. Per-client orchestrations keep team_id set as before.
 *
 * The unique on (team_id, name) is replaced with two PostgreSQL partial
 * indexes so name-uniqueness is enforced separately for per-client rows
 * and for platform rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orchestrations', function (Blueprint $table) {
            $table->dropUnique(['team_id', 'name']);
        });

        Schema::table('orchestrations', function (Blueprint $table) {
            $table->foreignId('team_id')->nullable()->change();
        });

        DB::statement('CREATE UNIQUE INDEX orchestrations_team_id_name_unique ON orchestrations (team_id, name) WHERE team_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX orchestrations_platform_name_unique ON orchestrations (name) WHERE team_id IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS orchestrations_platform_name_unique');
        DB::statement('DROP INDEX IF EXISTS orchestrations_team_id_name_unique');

        Schema::table('orchestrations', function (Blueprint $table) {
            $table->foreignId('team_id')->nullable(false)->change();
            $table->unique(['team_id', 'name']);
        });
    }
};
