<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform-level vocabulary for operator logout reasons.
 * Managed by super-admins under Features → Logout Reasons.
 *
 * Distinct from availability_reasons: these are the selection
 * shown in the "why are you signing out?" modal that intercepts
 * the operator-panel logout button, and only get recorded on
 * user_logout_events at the moment a session ends. They don't
 * control routing — a logged-out operator is simply off.
 *
 * Simpler shape than availability reasons: just label +
 * description + sort_order + is_active. No dot color, no
 * routing flag. Sort order drives dropdown ordering.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('logout_reasons', function (Blueprint $table) {
            $table->id();
            $table->string('label');
            $table->string('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });

        // Audit log — one row per logout. Captures who logged out,
        // when, and which reason they picked. Kept separate from
        // logout_reasons so deleting a reason doesn't wipe history.
        Schema::create('user_logout_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('logout_reason_id')->nullable()->constrained('logout_reasons')->nullOnDelete();
            $table->string('reason_label_snapshot')->nullable();
            $table->timestamp('logged_out_at');
            $table->timestamps();

            $table->index(['user_id', 'logged_out_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_logout_events');
        Schema::dropIfExists('logout_reasons');
    }
};
