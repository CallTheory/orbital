<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Voicemail audit row.
 *
 * One row per message left by a caller via Asterisk's VoiceMail()
 * app. Populated by the externnotify webhook the moment the
 * recording lands; the transcription job later fills in
 * `transcript` + `transcription_status` + `transcribed_at`.
 *
 * `recording_path` is the container-absolute path to the WAV on
 * the shared asterisk-voicemail-spool volume. Cleanup happens when
 * the transcription job succeeds — the WAV gets re-uploaded into
 * SeaweedFS under `voicemails/{team_id}/...` for permanent retention
 * (same pattern as call recordings) and the spool copy is deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voicemails', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('mailbox');           // e.g. "900001"
            $table->string('caller_id_num')->nullable();
            $table->string('caller_id_name')->nullable();
            $table->unsignedInteger('duration_seconds')->default(0);
            // Container-local path while the recording is in the
            // Asterisk spool. Nulled out once the job re-uploads
            // to SeaweedFS and switches to s3_path.
            $table->string('recording_path')->nullable();
            $table->string('s3_path')->nullable();
            $table->text('transcript')->nullable();
            $table->string('transcription_provider')->nullable();
            $table->string('transcription_status')->default('pending'); // pending/ok/failed/skipped
            $table->text('transcription_error')->nullable();
            $table->timestamp('transcribed_at')->nullable();
            $table->timestamp('emailed_at')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'created_at']);
            $table->index('mailbox');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voicemails');
    }
};
