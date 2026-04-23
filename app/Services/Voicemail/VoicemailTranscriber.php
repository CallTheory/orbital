<?php

declare(strict_types=1);

namespace App\Services\Voicemail;

use App\Models\Team;
use App\Services\Voicemail\Drivers\DeepgramDriver;
use App\Services\Voicemail\Drivers\ElevenLabsDriver;
use App\Services\Voicemail\Drivers\OpenAiWhisperDriver;
use App\Services\Voicemail\Drivers\WhisperLocalDriver;

/**
 * Facade that resolves a client's configured transcription provider
 * and runs it against a local WAV path. Returns plain text transcript,
 * empty string if the client has transcription disabled (`provider=none`).
 *
 * Throws on driver errors — the caller (queued job) is responsible
 * for catching and stamping `transcription_status=failed` on the
 * Voicemail row.
 */
class VoicemailTranscriber
{
    /** Provider IDs surfaced in the client admin UI + teams table. */
    public const PROVIDERS = [
        'none' => 'Disabled (no transcription)',
        'whisper_local' => 'Whisper (local — offline)',
        'openai_whisper' => 'OpenAI Whisper (cloud)',
        'deepgram' => 'Deepgram',
        'elevenlabs' => 'ElevenLabs Scribe',
    ];

    public function transcribeFor(Team $team, string $wavPath): string
    {
        $provider = (string) ($team->voicemail_transcription_provider ?? 'none');
        if ($provider === 'none' || $provider === '') {
            return '';
        }

        $driver = $this->driverFor(
            $provider,
            (array) ($team->voicemail_transcription_config ?? []),
        );

        return $driver->transcribe($wavPath);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function driverFor(string $provider, array $config): TranscriptionDriver
    {
        return match ($provider) {
            'whisper_local' => new WhisperLocalDriver(config: $config),
            'openai_whisper' => new OpenAiWhisperDriver(config: $config),
            'deepgram' => new DeepgramDriver(config: $config),
            'elevenlabs' => new ElevenLabsDriver(config: $config),
            default => throw new \InvalidArgumentException("Unknown voicemail transcription provider: {$provider}"),
        };
    }
}
