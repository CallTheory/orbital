<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 9: per-caller / per-account history log, written by
 * `save_history` and read back by `get_history`.
 *
 * A history entry is a lightweight row — source ("call",
 * "manual", "system"), subject line, body, and JSON metadata. Flows
 * can append a history entry on any milestone ("identified as VIP",
 * "transferred to billing", "resolved without escalation") and later
 * pull the N most recent entries when a caller calls back so the
 * LLM can reference prior context.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('call_history_entries', function (Blueprint $table) {
            $table->id();

            $table->foreignId('team_id')->constrained()->cascadeOnDelete();

            // A history entry can be scoped to a caller, an account,
            // or both. Nulls are allowed so the writer can pick whichever
            // scope makes sense.
            $table->unsignedBigInteger('caller_id')->nullable();
            $table->string('account_ref', 120)->nullable();

            $table->unsignedBigInteger('call_log_id')->nullable();

            $table->string('source', 32)->default('call');
            $table->string('subject', 240);
            $table->text('body')->nullable();

            $table->json('metadata')->nullable();

            $table->timestampTz('occurred_at')->useCurrent();
            $table->timestamps();

            $table->index(['team_id', 'caller_id', 'occurred_at']);
            $table->index(['team_id', 'account_ref', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('call_history_entries');
    }
};
