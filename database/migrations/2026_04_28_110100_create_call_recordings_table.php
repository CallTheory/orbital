<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-leg recording rows. Each `call_logs` row can have many
 * `call_recordings` rows — typically two from rtpengine
 * (caller_in + caller_out) and N from LiveKit Egress (one per
 * room participant track). Multi-source recordings of the same
 * call group by `call_log_id`.
 *
 * Replaces the legacy `call_logs.recording_*` path columns
 * (those stay in place for now to avoid breaking existing
 * read paths; new code reads `CallRecording` instead, and the
 * legacy columns get retired in a follow-up once consumers
 * are migrated).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('call_recordings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('call_log_id')->nullable()->constrained()->nullOnDelete();

            // Where the file came from. `rtpengine_edge` covers any
            // SIP dialog the platform sees (external + operator-to-
            // operator). `livekit_egress` covers per-participant
            // tracks from a LiveKit room. `asterisk_mixmonitor` is
            // legacy — pre-rtpengine recordings live here so the
            // historical view still works after the cutover.
            $table->string('source', 32);

            // Direction of audio flow. `caller_in` = bytes received
            // from the caller (their mic). `caller_out` = bytes
            // sent to the caller (everything they heard).
            // `participant_track` = a single LiveKit participant's
            // outbound audio (used as both source-of-truth and
            // diarization signal). `mixed` = legacy MixMonitor merge.
            $table->string('direction', 32);

            // SIP Call-ID (rtpengine) or room/session uuid (LK).
            // Lets the upload pipeline group paired files (e.g. the
            // caller_in and caller_out WAVs for the same dialog).
            $table->string('leg_uuid')->nullable();

            // LiveKit participant identity (e.g. "agent-7", "operator-21",
            // "caller-+15555550100"). Null for rtpengine rows.
            $table->string('participant_identity')->nullable();

            // Storage path on the configured filesystem disk
            // (SeaweedFS / MinIO). The format-specific extension is
            // included so the player picks the right MIME.
            $table->string('storage_path');
            $table->string('format', 16)->default('wav');

            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();

            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('team_id');
            $table->index('call_log_id');
            $table->index('source');
            $table->index('leg_uuid');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('call_recordings');
    }
};
