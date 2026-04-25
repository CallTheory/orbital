<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 follow-up: cap how many outgoing transitions a flow may
 * carry based on the primitive that terminates it.
 *
 * The cap lets the flow editor hide the "+" add-port once a flow
 * has as many transitions as its terminal primitive can semantically
 * branch. A `gather_phone` step has one forward exit (post-collection
 * continuation); a `transfer_call` is terminal (0 exits); a
 * `match_did` is unbounded (one exit per DID pattern configured).
 *
 * Null = unlimited. Per-primitive values are seeded in
 * IntakeGoalLibrarySeeder.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('intake_goals', function (Blueprint $table) {
            if (! Schema::hasColumn('intake_goals', 'max_transitions')) {
                $table->unsignedSmallInteger('max_transitions')->nullable()->after('tools');
            }
        });
    }

    public function down(): void
    {
        Schema::table('intake_goals', function (Blueprint $table) {
            if (Schema::hasColumn('intake_goals', 'max_transitions')) {
                $table->dropColumn('max_transitions');
            }
        });
    }
};
