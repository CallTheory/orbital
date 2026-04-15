<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3: email queues — per-tenant named buckets that routed
 * inbound threads land in, to be picked up by operators or an
 * assigned AI persona.
 *
 * Mirrors the call-side `CallQueue` model but with email-shaped
 * fields (no strategy/timeout/wrapup/music_on_hold concepts —
 * strategy here just describes how operators claim threads).
 *
 * An `EmailRoutingRule` with `destination_type='queue'` points
 * its `destination_id` at a row here. The router stamps
 * `email_threads.email_queue_id` so operators can filter to
 * queues they work.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_queues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();

            // `round_robin`  — operators claim in order of last-claimed
            // `longest_idle` — oldest-waiting operator claims next
            // `manual`       — no auto-assignment, operators pull freely
            // `ai_first`     — overflow AI takes everything unless
            //                  manually escalated to a human
            $table->string('strategy', 32)->default('manual');

            // When no operator is available or the thread has been
            // waiting too long, dispatch to this AgentPersona via
            // ProcessEmailWithAgentJob. Nullable — queues can run
            // human-only if preferred.
            $table->foreignId('overflow_agent_persona_id')
                ->nullable()
                ->constrained('agent_personas')
                ->nullOnDelete();

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['team_id', 'is_active']);
        });

        // Now that email_queues exists, promote the email_queue_id
        // column on email_threads from a bare unsigned bigint to a
        // real FK. Keeps referential integrity without needing to
        // drop + re-create the column.
        Schema::table('email_threads', function (Blueprint $table) {
            $table->foreign('email_queue_id')
                ->references('id')
                ->on('email_queues')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('email_threads', function (Blueprint $table) {
            $table->dropForeign(['email_queue_id']);
        });
        Schema::dropIfExists('email_queues');
    }
};
