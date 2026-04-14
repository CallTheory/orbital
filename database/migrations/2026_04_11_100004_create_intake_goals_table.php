<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Intake goals are small, structured building blocks that compile to every
 * surface where we run a call: the AI voice agent, the live operator UI,
 * and (future) a chat bot. One goal == one self-contained objective like
 * "gather caller info", "identify reason for call", or "answer from FAQ".
 *
 * Tenants compose these into ordered flows (see intake_flows); they never
 * author goals themselves — the platform maintains the library.
 *
 * Template/instance model (same shape as agent_personas):
 *   team_id = null && template_id = null → platform library template
 *   team_id = set  && template_id = set  → tenant instance linked to a template
 *   team_id = set  && template_id = null → tenant-original (no template backing)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('intake_goals', function (Blueprint $table) {
            $table->id();

            $table->foreignId('team_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('template_id')->nullable()->constrained('intake_goals')->nullOnDelete();

            // Stable slug (e.g. "identify_caller") the library references by key.
            // Unique per tenant scope — platform library owns the unqualified names.
            $table->string('key');

            $table->string('name');
            $table->text('description')->nullable();
            $table->string('category')->nullable();
            $table->string('icon')->nullable();

            // Ordered array of short natural-language prompts the agent or
            // operator says out loud to advance the goal.
            $table->json('talking_points')->nullable();

            // Array of {key, label, type, required, hint, validation} describing
            // the data the goal collects. The AI compiler turns these into LLM
            // function-call schemas; the operator compiler turns them into a form.
            $table->json('data_fields')->nullable();

            // How we know the goal is done. { type: "all_required" | "decision" | "manual", ... }
            $table->json('completion')->nullable();

            // Tool bindings the goal exposes (transfer, lookup_account, etc.).
            $table->json('tools')->nullable();

            // Optional knowledge_store IDs the goal is allowed to query. The
            // AI compiler surfaces `search_knowledge` as a tool when this is set.
            $table->json('knowledge_store_ids')->nullable();

            // Surface-specific overrides layered on top of the base fields.
            // Useful when voice wants a different prompt phrasing than chat.
            $table->json('voice_overrides')->nullable();
            $table->json('operator_overrides')->nullable();
            $table->json('chat_overrides')->nullable();

            $table->boolean('is_active')->default(true);

            // Per-field overrides for template-linked tenant instances.
            $table->json('overrides')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('team_id');
            $table->index('template_id');
            $table->index(['team_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('intake_goals');
    }
};
