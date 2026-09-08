<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Partial-message retention policy.
 *
 * When a caller hangs up part-way through an intake script, Orbital has
 * historically discarded everything collected so far — the message is
 * only written once the required fields are all present. For some
 * clients that's correct (a half-taken message is noise). For others it
 * is exactly wrong: a name and a callback number, even with no stated
 * reason, is enough for someone to call back, and throwing it away means
 * the caller is simply lost.
 *
 * So it becomes a policy rather than a behaviour:
 *
 *   teams.keep_partial_messages         client-level default (off)
 *   intake_goals.keep_partial_messages  per-goal override (null = inherit)
 *
 * The goal-level column is deliberately NULLABLE three-state rather than
 * a boolean: "inherit from the client" has to be distinguishable from
 * "explicitly off for this goal", or every new goal would silently opt
 * out of a client-wide policy.
 *
 * Messages record which side of the policy they came from so the
 * operator and portal lists can mark them, and so a partial can never be
 * mistaken for a complete message someone forgot to fill in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->boolean('keep_partial_messages')
                ->default(false)
                ->after('personal_team');
        });

        Schema::table('intake_goals', function (Blueprint $table) {
            $table->boolean('keep_partial_messages')
                ->nullable()
                ->after('is_active');
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->boolean('is_partial')->default(false)->after('urgency');

            // Why it's partial: 'caller_hung_up', 'operator_saved_partial',
            // 'session_abandoned'. Free-form string rather than an enum so
            // a new capture path doesn't need a migration to explain
            // itself.
            $table->string('partial_reason', 64)->nullable()->after('is_partial');

            // Which required fields never arrived. Lets the portal say
            // "no reason given" instead of leaving a blank the reader has
            // to interpret.
            $table->json('missing_fields')->nullable()->after('partial_reason');

            $table->index(['team_id', 'is_partial', 'status']);
        });

        // The AI side needs to know a call ENDED, not just that it went
        // quiet — "we have a name but no reason" means keep-the-partial
        // only once there's no chance of the caller supplying the rest.
        // Nothing recorded session termination before this, because
        // nothing needed to.
        Schema::table('call_session_states', function (Blueprint $table) {
            $table->timestamp('ended_at')->nullable()->after('last_field_at');
            $table->string('end_reason', 64)->nullable()->after('ended_at');
        });
    }

    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn('keep_partial_messages');
        });

        Schema::table('intake_goals', function (Blueprint $table) {
            $table->dropColumn('keep_partial_messages');
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex(['team_id', 'is_partial', 'status']);
            $table->dropColumn(['is_partial', 'partial_reason', 'missing_fields']);
        });

        Schema::table('call_session_states', function (Blueprint $table) {
            $table->dropColumn(['ended_at', 'end_reason']);
        });
    }
};
