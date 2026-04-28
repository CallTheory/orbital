<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-tenant message queues — the messaging-channel sibling to
 * call/email queues. One bucket can carry SMS, MMS, RCS, SMPP,
 * WCTP, or pager traffic; protocols are kept on a JSON column
 * rather than spread across separate queues so a "Support"
 * queue can match every text-shaped inbound regardless of
 * underlying transport.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_queues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();

            $table->foreignId('orchestration_id')
                ->nullable()
                ->constrained('orchestrations')
                ->nullOnDelete();

            $table->string('name');
            $table->text('description')->nullable();

            // Same claim semantics as email queues — see EmailQueue::STRATEGY_* docs.
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

            // JSON list of phone numbers (E.164), shortcodes, pager IDs,
            // WCTP destinations — matched against the inbound's "to" field
            // by the message router.
            $table->json('matched_addresses')->nullable();

            // JSON list of protocols this queue accepts:
            // sms, mms, rcs, smpp, wctp, paging.
            $table->json('matched_protocols')->nullable();

            $table->timestamps();

            $table->index(['team_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_queues');
    }
};
