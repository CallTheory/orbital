<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-tenant chat queues — interactive, session-shaped traffic
 * (embeddable web widget, Slack DM, Microsoft Teams). A queue
 * binds to one integration_type and carries the auth/config the
 * runtime needs to accept inbound conversations. Sessions and
 * message persistence live in a follow-up migration; this cut
 * just unblocks orchestration assignment + UI.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_queues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();

            $table->foreignId('orchestration_id')
                ->nullable()
                ->constrained('orchestrations')
                ->nullOnDelete();

            $table->string('name');
            $table->text('description')->nullable();

            // Same claim semantics as email/message queues.
            $table->string('strategy', 32)->default('manual');

            $table->foreignId('overflow_agent_persona_id')
                ->nullable()
                ->constrained('agent_personas')
                ->nullOnDelete();

            $table->foreignId('agent_group_id')
                ->nullable()
                ->constrained('agent_groups')
                ->nullOnDelete();

            $table->boolean('is_active')->default(true);

            // web_widget | slack | teams
            $table->string('integration_type', 32);

            // Per-integration credentials & options (widget keys,
            // Slack app token + signing secret, Teams tenant id, etc.).
            $table->json('integration_config')->nullable();

            $table->timestamps();

            $table->index(['team_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_queues');
    }
};
