<?php

declare(strict_types=1);

namespace App\Services\Telephony;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * The message a caller hears when the AI can't take or finish their call:
 * the worker can't load its persona, a provider (LLM, speech-to-text,
 * text-to-speech) fails past its retries, or Asterisk can't reach the AI
 * at all. Instead of dead air, Asterisk plays this and hangs up (the
 * [orbital-ai-unavailable] dialplan context).
 *
 * It's rendered ahead of time with the platform's TTS, because a broken
 * TTS provider may be the very failure it covers, and re-rendered only
 * when the wording or voice changes. Until a render succeeds, Asterisk
 * plays stock prompts instead ("Sorry. Please try your call again later.
 * Goodbye.").
 *
 * Stored as 8 kHz signed-linear (.sln): Asterisk plays it natively,
 * whereas its .wav reader rejects the 16/24 kHz audio TTS services return.
 */
class AiFallbackPrompt
{
    /** Where Asterisk finds it (Playback path, no extension). */
    public const ASTERISK_PATH = '/var/spool/asterisk/prompts/ai-fallback/unavailable';

    /** On the asterisk-prompts disk. */
    protected const AUDIO_KEY = 'ai-fallback/unavailable.sln';

    /** Fingerprint of what the audio says, to know when to re-render. */
    protected const HASH_KEY = 'ai-fallback/unavailable.hash';

    public const DEFAULT_MESSAGE = "We're sorry, we're having trouble connecting your call right now. Please try calling back in a few minutes. Goodbye.";

    /**
     * Render the prompt if it's missing or out of date.
     *
     * @return 'current'|'rendered'|'failed'|'empty'
     */
    public function ensureRendered(bool $force = false): string
    {
        $text = $this->message();
        if ($text === '') {
            return 'empty';
        }

        $disk = Storage::disk('asterisk-prompts');
        $hash = $this->fingerprint($text);
        if (! $force && $disk->exists(self::AUDIO_KEY) && trim((string) $disk->get(self::HASH_KEY)) === $hash) {
            return 'current';
        }

        $pcm = $this->synthesize($text);
        if ($pcm === null || $pcm === '') {
            return 'failed';
        }

        $disk->put(self::AUDIO_KEY, $pcm);
        $disk->put(self::HASH_KEY, $hash);

        return 'rendered';
    }

    public function exists(): bool
    {
        return Storage::disk('asterisk-prompts')->exists(self::AUDIO_KEY);
    }

    public function message(): string
    {
        return trim((string) (config('telephony.ai_fallback.message') ?: self::DEFAULT_MESSAGE));
    }

    protected function provider(): string
    {
        return (string) (config('telephony.ai_fallback.voice_provider') ?: 'openai');
    }

    protected function voice(): string
    {
        return (string) config('telephony.ai_fallback.voice_id', '');
    }

    protected function fingerprint(string $text): string
    {
        return hash('sha256', implode("\n", [$this->provider(), $this->voice(), $text]));
    }

    /**
     * 8 kHz mono signed 16-bit little-endian PCM, or null.
     */
    protected function synthesize(string $text): ?string
    {
        return match ($this->provider()) {
            'elevenlabs' => $this->elevenLabs($text, $this->voice() ?: '21m00Tcm4TlvDq8ikWAM'),
            default => $this->openAi($text, $this->voice() ?: 'alloy'),
        };
    }

    protected function openAi(string $text, string $voice): ?string
    {
        $key = (string) config('services.openai.api_key', '');
        if ($key === '') {
            Log::info('ai-fallback prompt: no OpenAI API key, Asterisk keeps the stock prompts');

            return null;
        }

        try {
            // "pcm" is raw 24 kHz signed 16-bit little-endian mono.
            $response = Http::withToken($key)->timeout(30)->post('https://api.openai.com/v1/audio/speech', [
                'model' => 'tts-1',
                'voice' => $voice,
                'input' => $text,
                'response_format' => 'pcm',
            ]);
        } catch (\Throwable $e) {
            Log::warning('ai-fallback prompt: OpenAI request failed', ['error' => $e->getMessage()]);

            return null;
        }
        if (! $response->successful()) {
            Log::warning('ai-fallback prompt: OpenAI TTS refused', ['status' => $response->status(), 'body' => mb_substr($response->body(), 0, 300)]);

            return null;
        }

        return self::downsample($response->body(), 3);
    }

    protected function elevenLabs(string $text, string $voice): ?string
    {
        $key = (string) config('services.elevenlabs.api_key', '');
        if ($key === '') {
            Log::info('ai-fallback prompt: no ElevenLabs API key, Asterisk keeps the stock prompts');

            return null;
        }

        try {
            $response = Http::withHeaders(['xi-api-key' => $key])->timeout(30)
                ->post("https://api.elevenlabs.io/v1/text-to-speech/{$voice}?output_format=pcm_16000", [
                    'text' => $text,
                    'model_id' => 'eleven_turbo_v2_5',
                ]);
        } catch (\Throwable $e) {
            Log::warning('ai-fallback prompt: ElevenLabs request failed', ['error' => $e->getMessage()]);

            return null;
        }
        if (! $response->successful()) {
            Log::warning('ai-fallback prompt: ElevenLabs TTS refused', ['status' => $response->status(), 'body' => mb_substr($response->body(), 0, 300)]);

            return null;
        }

        return self::downsample($response->body(), 2);
    }

    /**
     * Reduce 16-bit little-endian mono PCM by an integer factor, averaging
     * each group of samples (a simple low-pass, enough for speech).
     */
    public static function downsample(string $pcm, int $factor): string
    {
        $count = intdiv(strlen($pcm), 2);
        if ($factor <= 1 || $count === 0) {
            return substr($pcm, 0, $count * 2);
        }

        $samples = array_values(unpack('v*', substr($pcm, 0, $count * 2)));
        $out = '';
        for ($i = 0; $i + $factor <= $count; $i += $factor) {
            $sum = 0;
            for ($j = 0; $j < $factor; $j++) {
                $s = $samples[$i + $j];
                $sum += $s >= 0x8000 ? $s - 0x10000 : $s;   // to signed
            }
            $avg = intdiv($sum, $factor);
            $out .= pack('v', $avg < 0 ? $avg + 0x10000 : $avg);
        }

        return $out;
    }
}
