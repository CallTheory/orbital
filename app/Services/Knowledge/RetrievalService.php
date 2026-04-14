<?php

declare(strict_types=1);

namespace App\Services\Knowledge;

use App\Models\KnowledgeStore;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Pgvector\Laravel\Vector;

/**
 * Vector-search front-end for a knowledge store. The service is the
 * only place that issues SELECT queries against knowledge_chunks outside
 * of model relations — every call comes through here so tenant isolation
 * can be enforced in one spot.
 *
 * Caller responsibilities:
 *   1. Validate store ownership BEFORE calling search(). The API
 *      controller does this; the AgentFlowCompiler does this. Don't
 *      call search() with store ids from untrusted input without a
 *      team_id check.
 *   2. All stores in a single search() call must use the same
 *      embedding_model. We reject mixed-model queries because the
 *      embedding spaces aren't comparable.
 */
class RetrievalService
{
    public function __construct(
        private readonly EmbeddingService $embeddings,
    ) {
    }

    /**
     * Run a top-K cosine-distance search across one or more stores.
     *
     * @param  array<int, int>  $storeIds
     * @return array<int, array{id: int, store_id: int, content: string, source_ref: ?string, metadata: ?array, score: float}>
     */
    public function search(array $storeIds, string $query, int $topK = 5): array
    {
        if (empty($storeIds)) {
            return [];
        }

        $stores = KnowledgeStore::withoutGlobalScope('team')
            ->whereIn('id', $storeIds)
            ->get();

        if ($stores->isEmpty()) {
            return [];
        }

        // Every store in the query must share the same embedding model.
        $models = $stores->pluck('embedding_model')->unique();
        if ($models->count() > 1) {
            throw new InvalidArgumentException(
                'Cannot search across stores with different embedding models: '
                .$models->implode(', ')
            );
        }
        $embeddingModel = $models->first();

        // Embed the query once using the shared model.
        $queryVector = new Vector($this->embeddings->embed($query, $embeddingModel));

        // pgvector's cosine distance operator is `<=>`. Lower = more similar.
        // We convert to a similarity score (1 - distance) on the way out.
        $rows = \App\Models\KnowledgeChunk::query()
            ->select([
                'id',
                'store_id',
                'content',
                'source_ref',
                'metadata',
            ])
            ->selectRaw('(embedding <=> ?) AS distance', [(string) $queryVector])
            ->whereIn('store_id', $storeIds)
            ->whereNotNull('embedding')
            ->orderByRaw('embedding <=> ?', [(string) $queryVector])
            ->limit($topK)
            ->get();

        return $rows->map(fn ($row) => [
            'id' => (int) $row->id,
            'store_id' => (int) $row->store_id,
            'content' => (string) $row->content,
            'source_ref' => $row->source_ref,
            'metadata' => $row->metadata ? json_decode((string) $row->metadata, true) : null,
            'score' => (float) (1 - (float) $row->distance),
        ])->all();
    }

    /**
     * Convenience wrapper that also enforces tenant ownership up front.
     * The API controller delegates here so the guard and the query are
     * always co-located.
     *
     * @param  array<int, int>  $storeIds
     * @return array<int, array<string, mixed>>
     *
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException on mismatch
     */
    public function searchForTeam(int $teamId, array $storeIds, string $query, int $topK = 5): array
    {
        if (empty($storeIds)) {
            return [];
        }

        $ownedIds = KnowledgeStore::withoutGlobalScope('team')
            ->where('team_id', $teamId)
            ->whereIn('id', $storeIds)
            ->pluck('id')
            ->all();

        sort($ownedIds);
        $requested = array_values(array_unique(array_map('intval', $storeIds)));
        sort($requested);

        if ($ownedIds !== $requested) {
            abort(403, 'One or more store_ids do not belong to the authenticated team.');
        }

        return $this->search($storeIds, $query, $topK);
    }
}
