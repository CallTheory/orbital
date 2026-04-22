<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-tenant voicemail transcription configuration.
 *
 * The tenant picks the provider (none / whisper_local / openai /
 * deepgram / elevenlabs) on their admin page; credentials (API
 * keys, endpoints) live in the JSONB config column with Laravel's
 * encrypted cast applied at the model layer so they're never at
 * rest in plaintext.
 *
 * `whisper_local` needs no creds because it hits the in-cluster
 * whisper.cpp HTTP service; the UI still renders an empty config
 * block so the provider switch is consistent across drivers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->string('voicemail_transcription_provider')->default('none');
            $table->jsonb('voicemail_transcription_config')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn(['voicemail_transcription_provider', 'voicemail_transcription_config']);
        });
    }
};
