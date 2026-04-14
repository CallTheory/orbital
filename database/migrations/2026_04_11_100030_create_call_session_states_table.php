<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mid-call state shared between the AI agent and the live operator.
 *
 * Keyed on the LiveKit room name (which the worker parses to identify
 * the call — `agent-<extension>_<uuid>`) so both surfaces can read and
 * write against the same row without inventing a separate call_id.
 *
 * This is what makes AI→operator handoff work: the AI fills fields via
 * its set_field tool, those writes hit here, and when an operator
 * escalates or takes over mid-call, the operator's CompiledFlowViewer
 * pre-populates its form state from this row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('call_session_states', function (Blueprint $table) {
            $table->id();

            // Shared identifier between agent worker and operator UI.
            // The worker parses it from the LiveKit room name; the
            // operator UI inherits it from the active call context.
            $table->string('session_key')->unique();

            // Denormalized team scope for cheap tenant isolation checks.
            // Nullable because a session may exist before the caller's
            // tenant is identified.
            $table->foreignId('team_id')->nullable()->constrained()->cascadeOnDelete();

            // Persona handling the call. Not a hard FK — personas can be
            // soft-deleted and we still want the session record to survive.
            $table->unsignedBigInteger('agent_persona_id')->nullable();

            // Captured fields: keyed by data_field.key, value is the
            // captured string. Defaults to {}.
            $table->json('fields')->nullable();

            // Zero-based step index within the compiled operator_view.
            $table->unsignedInteger('active_step')->default(0);

            // Whether a human operator has taken over from the AI.
            $table->boolean('operator_owned')->default(false);

            // Last time a field was written — used to show "Live" badges
            // in the operator UI when the AI is still actively filling fields.
            $table->timestamp('last_field_at')->nullable();

            $table->timestamps();

            $table->index('team_id');
            $table->index('agent_persona_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('call_session_states');
    }
};
