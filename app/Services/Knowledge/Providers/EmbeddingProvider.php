<?php

declare(strict_types=1);

namespace App\Services\Knowledge\Providers;

/**
 * Contract every embedding backend implements. Kept deliberately small —
 * we just want vectors out of text, we don't need provider-specific
 * features bleeding through.
 */
interface EmbeddingProvider
{
    /**
     * Embed a single text string.
     *
     * @return array<int, float>
     */
    public function embed(string $text): array;

    /**
     * Embed a batch of texts. Providers that don't natively support
     * batching can loop internally.
     *
     * @param  array<int, string>  $texts
     * @return array<int, array<int, float>>
     */
    public function embedBatch(array $texts): array;

    /**
     * The dimensionality of the vectors this provider/model returns.
     */
    public function dimensions(): int;
}
