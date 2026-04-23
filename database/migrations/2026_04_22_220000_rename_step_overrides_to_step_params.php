<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rename `intake_flow_steps.step_overrides` to `step_params`.
 *
 * Under the refactored "goals as primitive nodes" model each step
 * simply *parameterizes* the node type — there's no library default
 * being overridden, so "override" read wrong. `step_params` is what
 * you fill in when you drop a node into a flow.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('intake_flow_steps')) {
            return;
        }

        Schema::table('intake_flow_steps', function (Blueprint $table) {
            if (Schema::hasColumn('intake_flow_steps', 'step_overrides') && ! Schema::hasColumn('intake_flow_steps', 'step_params')) {
                $table->renameColumn('step_overrides', 'step_params');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('intake_flow_steps')) {
            return;
        }

        Schema::table('intake_flow_steps', function (Blueprint $table) {
            if (Schema::hasColumn('intake_flow_steps', 'step_params') && ! Schema::hasColumn('intake_flow_steps', 'step_overrides')) {
                $table->renameColumn('step_params', 'step_overrides');
            }
        });
    }
};
