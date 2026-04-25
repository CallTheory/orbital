<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Canvas coordinates on intake_flows.
 *
 * The Svelte Flow editor positions each flow as a node on a graph
 * canvas. Node positions are persisted so the graph opens the same
 * way every time; otherwise the lib's auto-layout reshuffles it on
 * every load and authors lose their mental map of where things are.
 *
 * Nullable because brand-new rows (e.g. seeded or API-created) may
 * not have positions until the editor sets them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('intake_flows', function (Blueprint $table) {
            $table->integer('canvas_x')->nullable()->after('description');
            $table->integer('canvas_y')->nullable()->after('canvas_x');
        });
    }

    public function down(): void
    {
        Schema::table('intake_flows', function (Blueprint $table) {
            $table->dropColumn(['canvas_x', 'canvas_y']);
        });
    }
};
