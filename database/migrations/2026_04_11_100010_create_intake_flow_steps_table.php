<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ordered steps inside an intake flow. Each step references exactly one
 * intake goal from the library, plus optional per-step overrides and
 * branching rules.
 *
 * Runs at 100010 — after both intake_flows (100000) and intake_goals
 * (100004) so the FKs resolve cleanly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('intake_flow_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flow_id')->constrained('intake_flows')->cascadeOnDelete();
            $table->foreignId('intake_goal_id')->constrained('intake_goals')->cascadeOnDelete();

            $table->unsignedInteger('position')->default(0);

            // Conditional branching — {field_key: {value: next_step_id}}.
            // Empty / null means "advance linearly to the next position".
            $table->json('branches')->nullable();

            // Per-step tweaks to the goal (greeting variants, field defaults,
            // required overrides) without cloning the goal itself.
            $table->json('step_overrides')->nullable();

            $table->boolean('is_required')->default(true);

            $table->timestamps();

            $table->index(['flow_id', 'position']);
            $table->index('intake_goal_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('intake_flow_steps');
    }
};
