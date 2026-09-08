<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two-factor enforcement — grace window state.
 *
 * TOTP enrolment already exists on /{panel}/security; nothing has ever
 * required anyone to use it. Enforcement needs two facts the schema
 * doesn't currently hold:
 *
 *   teams.two_factor_grace_days
 *     How long a client's users get to enrol before they're pushed to
 *     the security page. Per-client because an answering service's
 *     customers vary wildly in how fast they can be made to do
 *     anything, and a single platform-wide number would be set to
 *     whatever the slowest client tolerates.
 *
 *   users.two_factor_grace_started_at
 *     When the clock started FOR THIS USER. Not derived from
 *     created_at: every existing user was created before enforcement
 *     existed, so counting from creation would lock out the entire
 *     installation the moment this ships. The middleware stamps it on
 *     the user's first request under the policy, which gives everyone —
 *     existing and new — a full window from when the rule first applied
 *     to them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            // 0 means "no grace" — enrol before you can do anything.
            // Deliberately allowed: a client handling medical or
            // financial calls may want exactly that.
            $table->unsignedSmallInteger('two_factor_grace_days')
                ->default(7)
                ->after('keep_partial_messages');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('two_factor_grace_started_at')
                ->nullable()
                ->after('two_factor_confirmed_at');
        });
    }

    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn('two_factor_grace_days');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('two_factor_grace_started_at');
        });
    }
};
