<?php

declare(strict_types=1);

namespace App\Services\Voicemail\Drivers;

use App\Services\Voicemail\TranscriptionDriver;
use Illuminate\Support\Facades\Http;

/**
 * ElevenLabs Speech-to-Text (Scribe) driver.
 *
 * Expects `api_key` in the client's voicemail_transcription_config.
 * Optional `model_id` (defaults to `scribe_v1`).
 *
 * Response shape (simplified):
 *   { "text": "full transcript", "language_code": "eng", "words": [...] }
 */
class ElevenLabsDriver implements TranscriptionDriver
{
    public function __construct(
        /** @var array<string, mixed> */
        protected array $config,
        protected string $endpoint = 'https://api.elevenlabs.io/v1/speech-to-text',
        protected int $timeoutSeconds = 120,
    ) {}

    public function transcribe(string $wavPath): string
    {
        $apiKey = (string) ($this->config['api_key'] ?? '');
        if ($apiKey === '') {
            throw new \RuntimeException('ElevenLabs driver requires api_key in the client config.');
        }

        $response = Http::timeout($this->timeoutSeconds)
            ->withHeaders(['xi-api-key' => $apiKey])
            ->attach('file', file_get_contents($wavPath), basename($wavPath))
            ->asMultipart()
            ->post($this->endpoint, [
                'model_id' => (string) ($this->config['model_id'] ?? 'scribe_v1'),
            ])
            ->throw();

        $body = $response->json();

        return trim((string) ($body['text'] ?? ''));
    }
}
