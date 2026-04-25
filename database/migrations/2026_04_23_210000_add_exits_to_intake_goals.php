<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 follow-up: named exits per primitive.
 *
 * `max_transitions` capped the count but not the meaning — all extra
 * transitions still rendered as anonymous "fallback" edges. Named
 * exits solve both: each primitive declares the set of outcomes it
 * can exit through (continue / rejected / failure, matched /
 * not_matched, yes / no, …), and the editor renders one port per
 * named exit. No "+" for fixed-exit primitives.
 *
 * Storage: nullable JSON array of strings.
 *   null  = unbounded (match_did, gather_choice — exits depend on
 *           runtime config like DID list / options array)
 *   []    = terminal (transfer_call, assign_to_persona)
 *   [...] = fixed named exits in order
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('intake_goals', function (Blueprint $table) {
            if (! Schema::hasColumn('intake_goals', 'exits')) {
                $table->json('exits')->nullable()->after('max_transitions');
            }
        });
    }

    public function down(): void
    {
        Schema::table('intake_goals', function (Blueprint $table) {
            if (Schema::hasColumn('intake_goals', 'exits')) {
                $table->dropColumn('exits');
            }
        });
    }
};
