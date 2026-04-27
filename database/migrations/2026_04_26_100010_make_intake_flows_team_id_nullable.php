<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A flow inside a platform-shared orchestration is tenant-less, so
 * `intake_flows.team_id` becomes nullable. The orchestration → flow
 * cascade-delete still cleans up children when the parent goes; the
 * flow's own team-scoping is now derived from its parent orchestration
 * rather than being independently authoritative.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('intake_flows', function (Blueprint $table) {
            $table->foreignId('team_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('intake_flows', function (Blueprint $table) {
            $table->foreignId('team_id')->nullable(false)->change();
        });
    }
};
