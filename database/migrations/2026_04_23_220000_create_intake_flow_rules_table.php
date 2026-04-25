<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4: reactive rules attached to a flow or step.
 *
 * Rules are how authors express "when X happens, do Y" without
 * bloating the main step chain — the reactive counterpart to the
 * sequential step list.
 *
 * Trigger events:
 *   on_field_set     — any slot/field was set (rule condition usually
 *                      pins it to a specific slot or value)
 *   on_step_enter    — step became current
 *   on_step_complete — step's completion criterion was satisfied
 *   on_flow_start    — flow became active
 *   on_flow_end      — flow terminated (any outcome)
 *
 * `step_id` is nullable — when null, the rule is flow-scoped (fires
 * for any step in the flow that matches the trigger). When set, it's
 * step-scoped.
 *
 * `condition` stores an expression string (Phase 2 syntax). The rule
 * fires when the condition evaluates truthy at the trigger event.
 *
 * `action_prompt` is a template (Phase 2 template syntax) rendered
 * into the LLM prompt when the rule fires. v1 routes every action
 * through the LLM; later phases will add structured actions
 * (set_slot, goto_flow, send_email) via an `actions` JSON column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('intake_flow_rules', function (Blueprint $table) {
            $table->id();

            $table->foreignId('flow_id')
                ->constrained('intake_flows')
                ->cascadeOnDelete();

            $table->foreignId('step_id')
                ->nullable()
                ->constrained('intake_flow_steps')
                ->cascadeOnDelete();

            $table->string('trigger_event', 32);

            // Short human-readable label so the editor can list rules
            // without rendering the full condition/action.
            $table->string('label')->nullable();

            // Expression string (Phase 2 syntax). Null = unconditional
            // (always fires on the trigger).
            $table->text('condition')->nullable();

            // Template string the LLM reads when the rule fires.
            $table->text('action_prompt')->nullable();

            // Ordering for deterministic firing when multiple rules
            // match the same event.
            $table->unsignedInteger('priority')->default(100);

            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['flow_id', 'trigger_event']);
            $table->index(['step_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('intake_flow_rules');
    }
};
