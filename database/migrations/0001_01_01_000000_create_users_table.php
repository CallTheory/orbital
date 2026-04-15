<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->foreignId('current_team_id')->nullable();
            $table->string('profile_photo_path', 2048)->nullable();
            // Per-user preferences: UI renders dates in the user's
            // timezone and strings in their locale. Both nullable
            // so a freshly-seeded user falls through to config('app.timezone')
            // / config('app.locale') until they set their own on the
            // profile page.
            $table->string('timezone', 64)->nullable();
            $table->string('locale', 16)->nullable();
            // Operator availability — the "am I taking work right now"
            // state shown in the operator panel topbar. Defaults to
            // `unavailable` so a freshly-created operator has to
            // explicitly flip themselves to `available` before they
            // start getting routed work. A Login event listener
            // (ResetAvailabilityOnLogin) ALSO resets the state to
            // `unavailable` on every fresh login, so operators
            // always opt in to taking work rather than being thrown
            // into rotation automatically. Every state, including
            // `available` and `unavailable`, is a row in
            // availability_reasons — `available` is protected from
            // deletion by the model's `deleting` hook.
            $table->string('availability_status', 32)->default('unavailable');
            $table->timestamp('availability_changed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
