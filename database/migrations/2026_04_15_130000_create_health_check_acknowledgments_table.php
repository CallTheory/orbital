<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Health check acknowledgments + audit log.
 *
 * When an operator acknowledges a degraded or down component
 * (e.g. "we're rebooting Haraka, mute the alarm for a few
 * minutes"), a row lands here. The SystemHealthService treats
 * the card's raw status as-is for display but rolls it up as
 * OK for the aggregate so the top-of-page status bar, the
 * sidebar nav badge, and the dashboard summary don't flag it.
 *
 * The same table doubles as the audit log. We never delete
 * rows — clearing an ack just sets `cleared_at` and
 * `cleared_by_user_id`, preserving history.
 *
 *   acknowledged_at       when the ack was created
 *   acknowledged_by_user_id  which operator pressed the button
 *   reason                free-text note (optional)
 *   cleared_at            NULL = active; timestamp = cleared
 *   cleared_by_user_id    NULL = system auto-clear on recovery;
 *                         set = manual clear by a user
 *
 * Auto-clear behavior: on each runAll() pass, any active ack
 * whose underlying component is now back to OK is automatically
 * cleared with cleared_by_user_id=null so operators don't have
 * to remember to untoggle things that recovered.
 *
 * Query for active acks: where cleared_at IS NULL.
 * Query for a component's audit history: all rows for the key,
 * newest first.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('health_check_acknowledgments', function (Blueprint $table) {
            $table->id();

            // The check's stable key as registered in
            // SystemHealthService (e.g. 'postgres', 'haraka',
            // 'horizon', 'mail'). Not a FK because the check
            // registry is in code, not in the DB.
            $table->string('check_key', 64);

            $table->foreignId('acknowledged_by_user_id')
                ->constrained('users')
                ->cascadeOnDelete();
            $table->timestamp('acknowledged_at');
            $table->text('reason')->nullable();

            $table->timestamp('cleared_at')->nullable();
            // Nullable because system auto-clears don't have a
            // user — when the component returns to OK we clear
            // the ack with cleared_by_user_id = null.
            $table->foreignId('cleared_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            // Fast lookup for "is this key currently acked?"
            $table->index(['check_key', 'cleared_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('health_check_acknowledgments');
    }
};
