<?php

declare(strict_types=1);

namespace App\Services\Knowledge;

use Illuminate\Support\Facades\Http;

/**
 * Generates text embeddings via Ollama's local API.
 *
 * Uses the nomic-embed-text model by default (768 dimensions).
 * Ollama runs inside Docker on the `sail` network, accessible
 * at http://ollama:11434 from other containers.
 */
class OllamaEmbedder
{
    protected string $baseUrl;

    protected string $model;

    public function __construct()
    {
        $this->baseUrl = config('services.ollama.url', 'http://ollama:11434');
        $this->model = config('services.ollama.embedding_model', 'nomic-embed-text');
    }

    /**
     * Embed a single text string and return the vector.
     *
     * @return array<int, float>
     */
    public function embed(string $text): array
    {
        $response = Http::timeout(30)
            ->post("{$this->baseUrl}/api/embeddings", [
                'model' => $this->model,
                'prompt' => $text,
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException("Ollama embedding failed: {$response->body()}");
        }

        return $response->json('embedding');
    }

    /**
     * Embed multiple texts in sequence.
     *
     * @param  array<int, string>  $texts
     * @return array<int, array<int, float>>
     */
    public function embedBatch(array $texts): array
    {
        $results = [];
        foreach ($texts as $text) {
            $results[] = $this->embed($text);
        }

        return $results;
    }

    /**
     * Get the dimensionality of the embedding model.
     */
    public function dimensions(): int
    {
        // nomic-embed-text produces 768-dimensional vectors
        return 768;
    }

    /**
     * Get the model identifier for storage.
     */
    public function modelId(): string
    {
        return "ollama:{$this->model}";
    }
}
