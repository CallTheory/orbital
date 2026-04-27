<?php

declare(strict_types=1);

namespace App\Services\Voicemail\Drivers;

use App\Services\Voicemail\TranscriptionDriver;
use Illuminate\Support\Facades\Http;

/**
 * Local whisper.cpp multi-model driver.
 *
 * Sends the WAV as multipart to the in-cluster whisper-local
 * compose service at :9700 and parses the `{ "text": "..." }`
 * response. The client's config picks:
 *   - `model`    — which bundled ggml model to load (tiny.en,
 *                  base.en, small, large-v3-turbo, etc.); defaults
 *                  to base.en for fast English.
 *   - `language` — optional ISO-639-1 hint for the multilingual
 *                  models; leave unset to let whisper auto-detect.
 *
 * No creds — this is the offline / zero-outbound-traffic path.
 */
class WhisperLocalDriver implements TranscriptionDriver
{
    public function __construct(
        /** @var array<string, mixed> */
        protected array $config = [],
        protected string $endpoint = 'http://whisper-local:9700',
        protected int $timeoutSeconds = 300,
    ) {}

    public function transcribe(string $wavPath): string
    {
        $model = (string) ($this->config['model'] ?? 'base.en');
        $language = (string) ($this->config['language'] ?? '');

        $form = ['model' => $model];
        // Only send `language` when the client picked one. The
        // server interprets absence as auto-detect, which is right
        // for multilingual models used without a locale hint.
        if ($language !== '') {
            $form['language'] = $language;
        }

        $response = Http::timeout($this->timeoutSeconds)
            ->attach('file', file_get_contents($wavPath), basename($wavPath))
            ->asMultipart()
            ->post($this->endpoint.'/inference', $form)
            ->throw();

        $body = $response->json();

        return trim((string) ($body['text'] ?? ''));
    }
}
