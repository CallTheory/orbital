<?php

declare(strict_types=1);

namespace App\Services\Telephony;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

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
     * Disk-relative key for the `asterisk-prompts` disk, matching
     * the last path segment of pathFor()'s absolute BASE_DIR path.
     * Returns null under the same conditions as pathFor().
     */
    protected function keyFor(string $message): ?string
    {
        $normalized = $this->normalize($message);
        if ($normalized === '') {
            return null;
        }

        $hash = hash('sha256', $normalized);

        return "disclosures/{$hash}.".self::RENDER_FORMAT;
    }

    /**
     * True when this message already has a rendered file on the
     * `asterisk-prompts` disk. Used by callers (e.g. the
     * `orbital:render-disclosures` command) that need an existence
     * check without triggering rendering — they used to `is_file()`
     * the absolute path directly, which stopped working once prompt
     * storage could be backed by S3.
     */
    public function exists(string $message): bool
    {
        $key = $this->keyFor($message);
        if ($key === null) {
            return false;
        }

        return Storage::disk('asterisk-prompts')->exists($key);
    }

    /**
     * Remove a previously rendered file so the next ensureRendered()
     * call re-renders it. Used by the `--force` flag on the
     * `orbital:render-disclosures` command.
     */
    public function forget(string $message): void
    {
        $key = $this->keyFor($message);
        if ($key === null) {
            return;
        }

        $disk = Storage::disk('asterisk-prompts');
        if ($disk->exists($key)) {
            $disk->delete($key);
        }
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
        $key = $this->keyFor($message);
        if ($path === null || $key === null) {
            return null;
        }

        $disk = Storage::disk('asterisk-prompts');

        if ($disk->exists($key)) {
            return $path;
        }

        $apiKey = (string) config('services.openai.api_key', '');
        if ($apiKey === '') {
            Log::info('disclosure renderer: OPENAI_API_KEY not set, skipping TTS', [
                'path' => $path,
            ]);

            return null;
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

            $disk->put($key, $response->body());
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
