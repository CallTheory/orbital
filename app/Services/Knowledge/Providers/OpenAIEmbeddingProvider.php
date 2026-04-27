<?php

declare(strict_types=1);

namespace App\Services\Knowledge\Providers;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * OpenAI's embedding API. Supports native batching — a single request
 * can embed up to ~2048 inputs in one round trip.
 *
 * The API key comes from config('services.openai.api_key'), which the
 * platform settings page writes via SettingsRegistry.
 */
class OpenAIEmbeddingProvider implements EmbeddingProvider
{
    /**
     * Known dimensionality for each model we support.
     *
     * @var array<string, int>
     */
    private const MODEL_DIMS = [
        'text-embedding-3-small' => 1536,
        'text-embedding-3-large' => 3072,
        'text-embedding-ada-002' => 1536,
    ];

    public function __construct(private string $model = 'text-embedding-3-small') {}

    public function embed(string $text): array
    {
        $result = $this->embedBatch([$text]);

        return $result[0] ?? [];
    }

    public function embedBatch(array $texts): array
    {
        if (empty($texts)) {
            return [];
        }

        $apiKey = (string) config('services.openai.api_key');
        if ($apiKey === '') {
            throw new RuntimeException('OpenAI API key is not configured. Set it in Platform Settings → AI Providers.');
        }

        $response = Http::withToken($apiKey)
            ->acceptJson()
            ->asJson()
            ->timeout(30)
            ->post('https://api.openai.com/v1/embeddings', [
                'input' => array_values($texts),
                'model' => $this->model,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException(
                'OpenAI embeddings request failed: '.$response->status().' '.$response->body()
            );
        }

        $body = $response->json();
        $data = $body['data'] ?? [];

        // OpenAI preserves input order in the `data` array.
        return array_map(
            fn (array $row): array => array_map('floatval', $row['embedding']),
            $data,
        );
    }

    public function dimensions(): int
    {
        return self::MODEL_DIMS[$this->model] ?? 1536;
    }
}
