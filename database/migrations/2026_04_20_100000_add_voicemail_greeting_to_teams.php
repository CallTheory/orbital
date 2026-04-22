<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-tenant custom voicemail greeting.
 *
 * Tenants with `voicemail_greeting_mode = 'custom_tts'` render the
 * configured `voicemail_greeting_text` through the chosen voice
 * provider. The generated WAV lands in the shared asterisk-prompts
 * volume and gets played back via `Playback()` right before the
 * dialplan calls `VoiceMail(mailbox, s)` — the `s` flag suppresses
 * Asterisk's stock "please leave a message" intro so the tenant's
 * custom greeting is the only thing callers hear.
 *
 * When the mode is `asterisk_default`, the dialplan stays exactly
 * as before — no playback, Asterisk's built-in greeting plays.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->string('voicemail_greeting_mode')->default('asterisk_default');
            $table->text('voicemail_greeting_text')->nullable();
            $table->string('voicemail_greeting_voice_provider')->nullable();
            $table->string('voicemail_greeting_voice_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn([
                'voicemail_greeting_mode',
                'voicemail_greeting_text',
                'voicemail_greeting_voice_provider',
                'voicemail_greeting_voice_id',
            ]);
        });
    }
};
