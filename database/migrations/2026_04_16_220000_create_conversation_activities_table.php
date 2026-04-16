<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_activities', function (Blueprint $table) {
            $table->id();

            // Polymorphic subject — EmailThread today, ChatSession /
            // CallLog / etc. in the future.
            $table->morphs('subject');

            // Who performed the action. Null for system-triggered
            // events (auto-close, routing, etc.).
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // If an AI agent performed the action.
            $table->foreignId('agent_persona_id')->nullable()->constrained()->nullOnDelete();

            // The action verb: claimed, replied, forwarded, closed,
            // reopened, assigned, note, etc.
            $table->string('action', 50);

            // Action-specific details — reply body, forward
            // recipient, assignment target, etc.
            $table->jsonb('metadata')->nullable();

            $table->timestamp('created_at')->useCurrent();

            // Timeline queries: "show me everything on this thread"
            $table->index(['subject_type', 'subject_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_activities');
    }
};
