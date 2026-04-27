<?php

declare(strict_types=1);

namespace App\Services\Knowledge\Providers;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Local embeddings via Ollama. The Ollama container is opt-in via the
 * docker-compose `local-ai` profile; this provider just assumes it's
 * reachable at the configured URL and returns a meaningful error if
 * not.
 *
 * Ollama's embedding API doesn't batch natively — it accepts one
 * `prompt` per call — so batching loops inside this class.
 */
class OllamaEmbeddingProvider implements EmbeddingProvider
{
    /**
     * Known dimensionality for the common local embedding models. Used
     * to pre-populate KnowledgeStore.embedding_dims; the actual
     * dimensionality is still verified at ingest time against the real
     * vectors returned by the model.
     *
     * @var array<string, int>
     */
    private const MODEL_DIMS = [
        'nomic-embed-text' => 768,
        'mxbai-embed-large' => 1024,
        'snowflake-arctic-embed' => 1024,
        'snowflake-arctic-embed:l' => 1024,
        'bge-m3' => 1024,
        'all-minilm' => 384,
    ];

    public function __construct(private string $model = 'nomic-embed-text') {}

    public function embed(string $text): array
    {
        $url = rtrim((string) config('services.ollama.url', env('OLLAMA_URL', 'http://ollama:11434')), '/');

        $response = Http::acceptJson()
            ->asJson()
            ->timeout(60)
            ->post($url.'/api/embeddings', [
                'model' => $this->model,
                'prompt' => $text,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException(
                'Ollama embeddings request failed: '.$response->status().' '.$response->body()
                .' (Is the ollama service running? Start it with: docker compose --profile local-ai up -d ollama)'
            );
        }

        $body = $response->json();

        return array_map('floatval', $body['embedding'] ?? []);
    }

    public function embedBatch(array $texts): array
    {
        return array_map(fn (string $t) => $this->embed($t), array_values($texts));
    }

    public function dimensions(): int
    {
        return self::MODEL_DIMS[$this->model] ?? 768;
    }
}
