<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who called, which number they dialled, and where the AI handed the
 * call off to. The handoff lookup API finds a session by the caller's
 * number once the live operator's system receives the transferred call.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('call_session_states', function (Blueprint $table) {
            $table->string('caller_number', 32)->nullable()->after('agent_persona_id');
            $table->string('called_number', 32)->nullable()->after('caller_number');
            $table->string('handoff_number', 32)->nullable()->after('active_step');
            $table->timestamp('handed_off_at')->nullable()->after('handoff_number');
            $table->index(['team_id', 'caller_number', 'handed_off_at'], 'call_session_states_handoff_lookup');
        });
    }

    public function down(): void
    {
        Schema::table('call_session_states', function (Blueprint $table) {
            $table->dropIndex('call_session_states_handoff_lookup');
            $table->dropColumn(['caller_number', 'called_number', 'handoff_number', 'handed_off_at']);
        });
    }
};
