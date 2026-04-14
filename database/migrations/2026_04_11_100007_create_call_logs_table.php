<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('call_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('unique_id')->nullable();
            $table->string('linked_id')->nullable();
            $table->string('channel')->nullable();
            $table->foreignId('sip_trunk_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('extension_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('agent_persona_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('call_queue_id')->nullable()->constrained()->nullOnDelete();
            $table->string('caller_id_name')->nullable();
            $table->string('caller_id_num')->nullable();
            $table->string('from_number')->nullable();
            $table->string('to_number')->nullable();
            $table->enum('direction', ['inbound', 'outbound', 'internal'])->default('inbound');
            $table->string('status')->default('initiated');
            $table->string('disposition')->nullable();
            $table->unsignedInteger('duration_seconds')->default(0);
            $table->unsignedInteger('billable_seconds')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            // Recording storage paths on the configured filesystem disk
            // (MinIO by default). recording_path is the merged/mixed
            // file for convenient playback; recording_rx_path and
            // recording_tx_path are the caller-only and agent-only
            // legs that the transcription pipeline can feed directly
            // into Whisper/ElevenLabs without speaker-diarization.
            $table->string('recording_path')->nullable();
            $table->string('recording_rx_path')->nullable();
            $table->string('recording_tx_path')->nullable();
            $table->unsignedBigInteger('recording_size_bytes')->nullable();
            $table->text('transcript')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('team_id');
            $table->index('started_at');
            $table->index('direction');
            $table->index('unique_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('call_logs');
    }
};
