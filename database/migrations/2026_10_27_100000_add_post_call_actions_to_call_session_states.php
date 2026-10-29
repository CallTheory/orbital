<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a call's post-call actions (email, web call) are recorded.
 * post_call_actions_at doubles as the claim that stops a session's
 * actions running twice when the worker's end call and LiveKit's
 * room_finished webhook both arrive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('call_session_states', function (Blueprint $table) {
            $table->timestamp('post_call_actions_at')->nullable();
            $table->json('post_call_actions')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('call_session_states', function (Blueprint $table) {
            $table->dropColumn(['post_call_actions_at', 'post_call_actions']);
        });
    }
};
