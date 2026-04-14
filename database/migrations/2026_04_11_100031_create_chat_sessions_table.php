<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chat sessions — one row per public-chat conversation. Stores the
 * message history, the target persona, and the team scope so operators
 * and super-admins can replay the transcript. Messages live in a JSON
 * column on the session row (no separate messages table in v1 since
 * chat is low-volume compared to voice).
 *
 * Keyed by a random public token the customer gets in their URL. The
 * token is unguessable and not tied to an auth user — anonymous chat
 * is the point.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('public_token')->unique();

            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_persona_id')->constrained('agent_personas')->cascadeOnDelete();

            // Full message log as a JSON array of {role, content, ts}.
            $table->json('messages')->nullable();

            // Captured data fields, keyed by data_field.key — same shape
            // as call_session_states.fields so the two surfaces can share
            // a reconciliation pipeline later.
            $table->json('fields')->nullable();

            $table->timestamp('last_activity_at')->nullable();

            $table->timestamps();

            $table->index(['team_id', 'agent_persona_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_sessions');
    }
};
