<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catalog of artificial voices that AI agent personas can use.
 *
 * Each row captures one provider-specific voice (an ElevenLabs voice ID,
 * an OpenAI TTS voice name, a Cartesia voice handle, etc.) plus friendly
 * metadata so platform operators can browse and pick voices without
 * memorizing provider-specific identifiers.
 *
 * AgentPersona.voice_id is currently a freeform string. A future pass
 * will swap it for a foreign key into this table once the catalog is
 * meaningfully populated.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voices', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->enum('provider', [
                'elevenlabs',
                'openai',
                'cartesia',
                'deepgram',
                'azure',
                'google',
                'aws_polly',
                'custom',
            ])->default('elevenlabs');
            $table->string('provider_voice_id')
                ->comment('The provider-specific identifier (e.g. ElevenLabs voice_id, OpenAI voice name).');
            $table->enum('gender', ['female', 'male', 'neutral', 'unspecified'])->default('unspecified');
            $table->string('language', 16)->nullable()
                ->comment('BCP-47 tag, e.g. en-US, es-MX.');
            $table->string('accent')->nullable();
            $table->text('description')->nullable();
            $table->string('sample_url')->nullable()
                ->comment('Optional URL to a hosted preview audio file.');
            $table->json('tags')->nullable()
                ->comment('Free-form tags for filtering: warm, professional, energetic, etc.');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['provider', 'provider_voice_id']);
            $table->index('provider');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voices');
    }
};
