<?php

declare(strict_types=1);

namespace App\Services\Telephony;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Renders recording-disclosure TTS audio files that Asterisk can play
 * back at the start of a recorded call (e.g. "This call may be
 * monitored or recorded for quality assurance purposes.").
 *
 * Files are hashed by message content so identical disclosures across
 * clients share a single audio file. The file lives in a volume shared
 * between the orbital.test container (which renders it) and the
 * asterisk container (which plays it back at call time):
 *
 *     /var/spool/asterisk/prompts/disclosures/{sha256(normalized)}.wav
 *
 * The renderer is split from the Asterisk config generator so the
 * generator can stay synchronous and fast — it just asks for the
 * *expected* path via pathFor() without triggering a TTS call.
 * Actual rendering happens out-of-band via RenderDisclosurePromptJob.
 */
class DisclosureRenderer
{
    /**
     * Absolute directory where rendered disclosure files live. Both the
     * app container and the asterisk container mount this path via the
     * shared `asterisk-prompts` named volume (see docker-compose.yml).
     *
     * In HA deployments the same path is backed by a SeaweedFS FUSE
     * mount instead of a docker named volume, so every Laravel and
     * Asterisk node sees a unified namespace. Keep this path POSIX-
     * friendly: no file locks, no atomic renames across directories,
     * no sparse-file tricks — FUSE-over-network may not support them.
     */
    public const BASE_DIR = '/var/spool/asterisk/prompts/disclosures';

    /**
     * Asterisk's Playback() application takes a sound path *without*
     * the extension — it picks a matching format at runtime. We render
     * as WAV because Asterisk's format_wav loads by default in our
     * image and OpenAI's TTS endpoint returns it natively.
     */
    protected const RENDER_FORMAT = 'wav';

    /**
     * Compute the absolute audio path for a disclosure message without
     * rendering. Returns null for empty messages. Callers that need
     * the file to actually exist should call ensureRendered() instead.
     */
    public function pathFor(string $message): ?string
    {
        $normalized = $this->normalize($message);
        if ($normalized === '') {
            return null;
        }

        $hash = hash('sha256', $normalized);

        return self::BASE_DIR."/{$hash}.".self::RENDER_FORMAT;
    }

    /**
     * The Asterisk-friendly prompt path (no extension). `Playback()`
     * takes the path minus the extension and picks a supported format.
     */
    public function asteriskPromptPath(string $message): ?string
    {
        $full = $this->pathFor($message);
        if ($full === null) {
            return null;
        }

        return preg_replace('/\.[^.]+$/', '', $full);
    }

    /**
     * Render the message to the shared prompts volume if it isn't
     * there already. Returns the absolute path on success, null if
     * the message is empty or TTS is unavailable (missing API key).
     *
     * Idempotent: a second call with the same message is a no-op.
     */
    public function ensureRendered(string $message): ?string
    {
        $path = $this->pathFor($message);
        if ($path === null) {
            return null;
        }

        if (is_file($path)) {
            return $path;
        }

        $apiKey = (string) config('services.openai.api_key', '');
        if ($apiKey === '') {
            Log::info('disclosure renderer: OPENAI_API_KEY not set, skipping TTS', [
                'path' => $path,
            ]);
            return null;
        }

        if (! is_dir(self::BASE_DIR)) {
            @mkdir(self::BASE_DIR, 0775, true);
        }

        try {
            $response = Http::withToken($apiKey)
                ->timeout(30)
                ->post('https://api.openai.com/v1/audio/speech', [
                    'model' => 'tts-1',
                    'voice' => 'alloy',
                    'input' => $this->normalize($message),
                    'response_format' => self::RENDER_FORMAT,
                ]);

            if (! $response->successful()) {
                Log::warning('disclosure renderer: OpenAI TTS failed', [
                    'status' => $response->status(),
                    'body' => substr((string) $response->body(), 0, 500),
                ]);
                return null;
            }

            file_put_contents($path, $response->body());
            @chmod($path, 0644);
        } catch (\Throwable $e) {
            Log::warning('disclosure renderer: TTS request threw', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }

        return $path;
    }

    /**
     * Collapse whitespace so trivial formatting tweaks don't invalidate
     * the cache. Keeps content comparison semantic rather than byte-for-
     * byte — two messages differing only in extra spaces produce the
     * same hash and reuse the same audio file.
     */
    protected function normalize(string $message): string
    {
        return trim(preg_replace('/\s+/', ' ', $message) ?? '');
    }
}
