<?php

declare(strict_types=1);

namespace App\Services\Telephony;

use App\Models\Team;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Renders per-tenant voicemail-greeting TTS WAVs for Asterisk to play
 * via `Playback()` before calling `VoiceMail(..., s)` (the `s` flag
 * suppresses Asterisk's stock intro so the tenant's custom greeting
 * is the only thing the caller hears).
 *
 * Output path layout — shared with the existing disclosure-prompts
 * flow so Asterisk only has to mount one prompts volume:
 *
 *     /var/spool/asterisk/prompts/voicemail-greetings/{team_id}.wav
 *
 * Keyed by team_id (not content hash) because a tenant changing their
 * greeting text should replace the file in place — there's no value
 * in sharing audio across tenants the way disclosure messages can.
 *
 * Supported providers: OpenAI `tts-1` and ElevenLabs. Tenants pick
 * via the admin UI; API keys come from the platform's service config
 * (shared with the existing OpenAI / ElevenLabs integrations) rather
 * than being stored per-tenant — the platform operator owns the
 * spend, tenants pick their voice.
 */
class VoicemailGreetingRenderer
{
    public const BASE_DIR = '/var/spool/asterisk/prompts/voicemail-greetings';

    protected const RENDER_FORMAT = 'wav';

    /**
     * Render the tenant's configured greeting to its on-disk WAV.
     * Returns the absolute path on success or null when:
     *   - the tenant isn't on custom_tts mode
     *   - the greeting text is empty
     *   - the required API key isn't configured
     *   - the provider call failed (logged as warning)
     */
    public function ensureRenderedForTenant(Team $team): ?string
    {
        if (($team->voicemail_greeting_mode ?? 'asterisk_default') !== 'custom_tts') {
            return null;
        }

        $text = trim((string) ($team->voicemail_greeting_text ?? ''));
        if ($text === '') {
            return null;
        }

        $path = $this->pathFor($team);
        if (! is_dir(self::BASE_DIR)) {
            @mkdir(self::BASE_DIR, 0775, true);
        }

        $provider = (string) ($team->voicemail_greeting_voice_provider ?? 'openai');
        $voiceId = (string) ($team->voicemail_greeting_voice_id ?? '');

        $audio = match ($provider) {
            'openai' => $this->renderOpenAi($text, $voiceId ?: 'alloy'),
            'elevenlabs' => $this->renderElevenLabs($text, $voiceId ?: '21m00Tcm4TlvDq8ikWAM'),
            default => null,
        };
        if ($audio === null) {
            return null;
        }

        file_put_contents($path, $audio);
        @chmod($path, 0644);

        return $path;
    }

    /**
     * Absolute on-disk path for the tenant's rendered WAV. Stable
     * per tenant so dialplan generation can reference the same path
     * at Blade-render time without waiting for TTS to finish.
     */
    public function pathFor(Team $team): string
    {
        return self::BASE_DIR."/{$team->id}.".self::RENDER_FORMAT;
    }

    /**
     * Asterisk-friendly prompt path (no extension). `Playback()`
     * takes the path minus the extension and picks a supported
     * format by extension probing at load time.
     */
    public function asteriskPromptPath(Team $team): string
    {
        return self::BASE_DIR."/{$team->id}";
    }

    /**
     * True when there's an on-disk WAV for this tenant — used by
     * the dialplan generator to decide whether to emit the custom
     * Playback() branch or the stock VoiceMail() fallback.
     */
    public function hasGreeting(Team $team): bool
    {
        return is_file($this->pathFor($team));
    }

    /**
     * Delete the rendered file — called when a tenant switches
     * back to `asterisk_default` so a stale WAV doesn't get played
     * after the admin thought they'd turned the feature off.
     */
    public function deleteFor(Team $team): void
    {
        $path = $this->pathFor($team);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    protected function renderOpenAi(string $text, string $voice): ?string
    {
        $apiKey = (string) config('services.openai.api_key', '');
        if ($apiKey === '') {
            Log::info('voicemail-greeting: OPENAI_API_KEY not set, skipping TTS');
            return null;
        }

        try {
            $response = Http::withToken($apiKey)
                ->timeout(30)
                ->post('https://api.openai.com/v1/audio/speech', [
                    'model' => 'tts-1',
                    'voice' => $voice,
                    'input' => $text,
                    'response_format' => self::RENDER_FORMAT,
                ]);

            if (! $response->successful()) {
                Log::warning('voicemail-greeting: OpenAI TTS failed', [
                    'status' => $response->status(),
                    'body' => mb_substr((string) $response->body(), 0, 500),
                ]);
                return null;
            }

            return (string) $response->body();
        } catch (\Throwable $e) {
            Log::warning('voicemail-greeting: OpenAI request threw', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    protected function renderElevenLabs(string $text, string $voiceId): ?string
    {
        $apiKey = (string) config('services.elevenlabs.api_key', '');
        if ($apiKey === '') {
            Log::info('voicemail-greeting: ELEVENLABS_API_KEY not set, skipping TTS');
            return null;
        }

        try {
            // ElevenLabs returns MPEG by default. Request PCM-in-WAV
            // explicitly so Asterisk can play it without an ffmpeg
            // transcode step.
            $response = Http::withHeaders(['xi-api-key' => $apiKey])
                ->timeout(30)
                ->post("https://api.elevenlabs.io/v1/text-to-speech/{$voiceId}?output_format=pcm_16000", [
                    'text' => $text,
                    'model_id' => 'eleven_turbo_v2_5',
                ]);

            if (! $response->successful()) {
                Log::warning('voicemail-greeting: ElevenLabs TTS failed', [
                    'status' => $response->status(),
                    'body' => mb_substr((string) $response->body(), 0, 500),
                ]);
                return null;
            }

            // ElevenLabs PCM output needs a WAV header so Asterisk's
            // format_wav can read it. Wrap the raw PCM bytes.
            return $this->wrapPcmAsWav((string) $response->body(), sampleRate: 16000);
        } catch (\Throwable $e) {
            Log::warning('voicemail-greeting: ElevenLabs request threw', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Minimal WAV wrapper for raw 16-bit PCM. We don't need a fancy
     * library for this — it's a 44-byte header.
     */
    protected function wrapPcmAsWav(string $pcm, int $sampleRate): string
    {
        $dataSize = strlen($pcm);
        $chunkSize = 36 + $dataSize;
        $header = 'RIFF'
            .pack('V', $chunkSize)
            .'WAVE'
            .'fmt '
            .pack('V', 16)          // PCM header size
            .pack('v', 1)           // format = PCM
            .pack('v', 1)           // mono
            .pack('V', $sampleRate)
            .pack('V', $sampleRate * 2) // byte rate
            .pack('v', 2)           // block align
            .pack('v', 16)          // bits per sample
            .'data'
            .pack('V', $dataSize);
        return $header.$pcm;
    }
}
