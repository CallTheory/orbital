<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Call History's raw input. Asterisk's cdr_adaptive_odbc writes one row
 * per CDR here (docker/asterisk/cdr_adaptive_odbc.conf), matching columns
 * to CDR variables by name: the standard fields, plus the orbital_*
 * variables the dialplan sets. orbital:ingest-cdrs groups the rows by
 * linkedid (one per call) into call_logs and stamps ingested_at.
 *
 * Text columns for anything the dialplan sets, so an odd value can't
 * fail the insert. Asterisk leaves columns it has no value for (id,
 * ingested_at) out of its INSERT, so they take their defaults; it writes
 * an unanswered leg's answer time as the epoch and the disposition as a
 * number (8 = answered), which the ingester normalises.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asterisk_cdr', function (Blueprint $table) {
            $table->id();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->string('clid', 255)->nullable();
            $table->string('src', 128)->nullable();
            $table->string('dst', 128)->nullable();
            $table->string('dcontext', 128)->nullable();
            $table->string('channel', 255)->nullable();
            $table->string('dstchannel', 255)->nullable();
            $table->string('lastapp', 64)->nullable();
            $table->string('lastdata', 512)->nullable();
            $table->integer('duration')->nullable();
            $table->integer('billsec')->nullable();
            $table->string('disposition', 32)->nullable();
            $table->string('amaflags', 32)->nullable();
            $table->string('accountcode', 128)->nullable();
            $table->string('uniqueid', 64)->nullable();
            $table->string('linkedid', 64)->nullable();
            $table->string('userfield', 255)->nullable();
            $table->string('peeraccount', 128)->nullable();
            $table->integer('sequence')->nullable();
            $table->string('orbital_team_id', 32)->nullable();
            $table->string('orbital_rule_id', 32)->nullable();
            $table->string('orbital_did', 64)->nullable();
            $table->string('orbital_sip_call_id', 255)->nullable();
            $table->timestamp('ingested_at')->nullable();

            $table->index('linkedid');
            $table->index('ingested_at');
        });

        Schema::table('call_logs', function (Blueprint $table) {
            // One Call History row per call; ingestion upserts on it.
            $table->unique('linked_id');
        });

        Schema::table('call_session_states', function (Blueprint $table) {
            // Asterisk's linkedid for the call this AI session is part of,
            // from the X-Orbital-Call header (see orbital-ai-headers).
            $table->string('linked_id', 64)->nullable()->index();
            $table->longText('transcript')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('call_session_states', function (Blueprint $table) {
            $table->dropIndex(['linked_id']);
            $table->dropColumn(['linked_id', 'transcript']);
        });

        Schema::table('call_logs', function (Blueprint $table) {
            $table->dropUnique(['linked_id']);
        });

        Schema::dropIfExists('asterisk_cdr');
    }
};
