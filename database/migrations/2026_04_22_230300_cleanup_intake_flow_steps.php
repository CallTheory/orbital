<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drop unused columns on intake_flow_steps.
 *
 * `branches` was a half-baked per-step branching scheme replaced by
 * the proper `intake_flow_transitions` table (branches are now
 * between flows, not between steps).
 *
 * `is_required` was never read by the compiler. If per-step required-
 * ness ever matters, it lives in step_params where every other
 * primitive-specific param does.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('intake_flow_steps', function (Blueprint $table) {
            if (Schema::hasColumn('intake_flow_steps', 'branches')) {
                $table->dropColumn('branches');
            }
            if (Schema::hasColumn('intake_flow_steps', 'is_required')) {
                $table->dropColumn('is_required');
            }
        });
    }

    public function down(): void
    {
        Schema::table('intake_flow_steps', function (Blueprint $table) {
            $table->json('branches')->nullable();
            $table->boolean('is_required')->default(true);
        });
    }
};
