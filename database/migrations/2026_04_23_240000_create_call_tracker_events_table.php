<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 9: analytics event log, written by the `save_call_tracker_event`
 * primitive (and by any in-app analytics emitters later).
 *
 * Rows are per-event append-only — event_type names what happened
 * ("button_pressed_transfer", "customer_identified", "escalated") and
 * `description` is a template-rendered narrative. `context` is a JSON
 * snapshot of the slots at the moment the event fired, for
 * reconstruction in the reporting surface.
 *
 * Indexed on (team_id, event_type) and (team_id, occurred_at) so
 * Grafana / report queries can slice by event class or time window
 * without scanning.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('call_tracker_events', function (Blueprint $table) {
            $table->id();

            $table->foreignId('team_id')->constrained()->cascadeOnDelete();

            // Optional call / message references so events can tie
            // back to the session that produced them.
            $table->unsignedBigInteger('call_log_id')->nullable();
            $table->unsignedBigInteger('message_id')->nullable();
            $table->unsignedBigInteger('caller_id')->nullable();

            $table->string('event_type', 120);
            $table->text('description')->nullable();

            // Slot + context snapshot at event time.
            $table->json('context')->nullable();

            $table->timestampTz('occurred_at')->useCurrent();
            $table->timestamps();

            $table->index(['team_id', 'event_type']);
            $table->index(['team_id', 'occurred_at']);
            $table->index('call_log_id');
            $table->index('caller_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('call_tracker_events');
    }
};
