<?php

declare(strict_types=1);

namespace App\Services\Knowledge;

use App\Services\Knowledge\Providers\EmbeddingProvider;
use App\Services\Knowledge\Providers\OllamaEmbeddingProvider;
use App\Services\Knowledge\Providers\OpenAIEmbeddingProvider;
use InvalidArgumentException;

/**
 * Thin facade in front of the pluggable embedding backends. Resolves the
 * right provider from a "provider:model" string and defers the actual
 * HTTP call to the concrete implementation.
 *
 * Keys look like:
 *   - "openai:text-embedding-3-small"
 *   - "ollama:nomic-embed-text"
 *
 * The parent KnowledgeStore stores its embedding_model in this format
 * so every query knows exactly which backend to talk to. This lets a
 * platform operator mix hosted and local embeddings across different
 * clients — a cost-sensitive client can use Ollama, a high-accuracy
 * client can use OpenAI, all on the same server.
 */
class EmbeddingService
{
    /**
     * Embed a single text string using the store's configured backend.
     *
     * @param  string  $providerAndModel  "provider:model" — e.g. "openai:text-embedding-3-small"
     * @return array<int, float>
     */
    public function embed(string $text, string $providerAndModel): array
    {
        return $this->resolveProvider($providerAndModel)->embed($text);
    }

    /**
     * Embed a batch of text strings. Providers may use a single upstream
     * call when supported (OpenAI batches natively; Ollama currently does
     * one request per item, which the provider encapsulates).
     *
     * @param  array<int, string>  $texts
     * @return array<int, array<int, float>>
     */
    public function embedBatch(array $texts, string $providerAndModel): array
    {
        return $this->resolveProvider($providerAndModel)->embedBatch($texts);
    }

    /**
     * Return the dimensionality of vectors produced by a given
     * provider:model combination, for use when sizing a new
     * KnowledgeStore.
     */
    public function dimensions(string $providerAndModel): int
    {
        return $this->resolveProvider($providerAndModel)->dimensions();
    }

    private function resolveProvider(string $providerAndModel): EmbeddingProvider
    {
        [$provider, $model] = $this->parse($providerAndModel);

        return match ($provider) {
            'openai' => new OpenAIEmbeddingProvider($model),
            'ollama' => new OllamaEmbeddingProvider($model),
            default => throw new InvalidArgumentException("Unknown embedding provider: {$provider}"),
        };
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function parse(string $providerAndModel): array
    {
        if (! str_contains($providerAndModel, ':')) {
            throw new InvalidArgumentException(
                "Embedding model must be in 'provider:model' format. Got: {$providerAndModel}"
            );
        }

        [$provider, $model] = explode(':', $providerAndModel, 2);

        return [trim($provider), trim($model)];
    }
}
