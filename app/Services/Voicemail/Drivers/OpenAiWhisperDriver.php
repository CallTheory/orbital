<?php

declare(strict_types=1);

namespace App\Services\Voicemail\Drivers;

use App\Services\Voicemail\TranscriptionDriver;
use Illuminate\Support\Facades\Http;

/**
 * OpenAI's hosted Whisper transcription API.
 *
 * Expects `api_key` in the client's voicemail_transcription_config.
 * Optional `model` override (defaults to `whisper-1`).
 */
class OpenAiWhisperDriver implements TranscriptionDriver
{
    public function __construct(
        /** @var array<string, mixed> */
        protected array $config,
        protected string $endpoint = 'https://api.openai.com/v1/audio/transcriptions',
        protected int $timeoutSeconds = 120,
    ) {}

    public function transcribe(string $wavPath): string
    {
        $apiKey = (string) ($this->config['api_key'] ?? '');
        if ($apiKey === '') {
            throw new \RuntimeException('OpenAI Whisper driver requires api_key in the client config.');
        }

        $response = Http::timeout($this->timeoutSeconds)
            ->withToken($apiKey)
            ->attach('file', file_get_contents($wavPath), basename($wavPath))
            ->asMultipart()
            ->post($this->endpoint, [
                'model' => (string) ($this->config['model'] ?? 'whisper-1'),
                'response_format' => 'json',
                // Always US English for voicemail; expand to a
                // client-configurable locale later if we get
                // multilingual clients.
                'language' => (string) ($this->config['language'] ?? 'en'),
            ])
            ->throw();

        $body = $response->json();

        return trim((string) ($body['text'] ?? ''));
    }
}
