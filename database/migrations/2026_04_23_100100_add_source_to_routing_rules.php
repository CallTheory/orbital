<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add `source` to routing_rules + email_routing_rules.
 *
 * The FlowGraphSynchronizer now writes routing rules as a derived
 * artifact of the canvas. We tag the rules it owns with
 * `source = 'flow_graph'` so we can distinguish them from any rules
 * hand-authored the old way (`source` null). On sync, only rows
 * stamped `flow_graph` get deleted/recreated — hand-authored rules
 * are opaque to us and stay.
 *
 * Nullable because existing rows pre-date this mechanism.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('routing_rules') && ! Schema::hasColumn('routing_rules', 'source')) {
            Schema::table('routing_rules', function (Blueprint $table) {
                $table->string('source', 32)->nullable()->after('is_active');
                $table->index(['team_id', 'source']);
            });
        }
        if (Schema::hasTable('email_routing_rules') && ! Schema::hasColumn('email_routing_rules', 'source')) {
            Schema::table('email_routing_rules', function (Blueprint $table) {
                $table->string('source', 32)->nullable()->after('is_active');
                $table->index(['team_id', 'source']);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('routing_rules', 'source')) {
            Schema::table('routing_rules', function (Blueprint $table) {
                $table->dropIndex(['team_id', 'source']);
                $table->dropColumn('source');
            });
        }
        if (Schema::hasColumn('email_routing_rules', 'source')) {
            Schema::table('email_routing_rules', function (Blueprint $table) {
                $table->dropIndex(['team_id', 'source']);
                $table->dropColumn('source');
            });
        }
    }
};
