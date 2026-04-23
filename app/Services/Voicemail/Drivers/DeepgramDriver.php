<?php

declare(strict_types=1);

namespace App\Services\Voicemail\Drivers;

use App\Services\Voicemail\TranscriptionDriver;
use Illuminate\Support\Facades\Http;

/**
 * Deepgram pre-recorded transcription API.
 *
 * Expects `api_key` in the client's voicemail_transcription_config.
 * Optional `model` (defaults to `nova-2`) and `language` (`en-US`).
 *
 * Response shape (simplified):
 *   { "results": { "channels": [ { "alternatives": [ { "transcript": "..." } ] } ] } }
 */
class DeepgramDriver implements TranscriptionDriver
{
    public function __construct(
        /** @var array<string, mixed> */
        protected array $config,
        protected string $endpoint = 'https://api.deepgram.com/v1/listen',
        protected int $timeoutSeconds = 120,
    ) {}

    public function transcribe(string $wavPath): string
    {
        $apiKey = (string) ($this->config['api_key'] ?? '');
        if ($apiKey === '') {
            throw new \RuntimeException('Deepgram driver requires api_key in the client config.');
        }

        $query = http_build_query([
            'model' => (string) ($this->config['model'] ?? 'nova-2'),
            'language' => (string) ($this->config['language'] ?? 'en-US'),
            'punctuate' => 'true',
            'smart_format' => 'true',
        ]);

        $response = Http::timeout($this->timeoutSeconds)
            ->withHeaders([
                'Authorization' => 'Token '.$apiKey,
                'Content-Type' => 'audio/wav',
            ])
            ->withBody(file_get_contents($wavPath), 'audio/wav')
            ->post($this->endpoint.'?'.$query)
            ->throw();

        $body = $response->json();
        $transcript = $body['results']['channels'][0]['alternatives'][0]['transcript'] ?? '';

        return trim((string) $transcript);
    }
}
