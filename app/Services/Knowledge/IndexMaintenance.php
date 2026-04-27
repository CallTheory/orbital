<?php

declare(strict_types=1);

namespace App\Services\Knowledge;

use App\Models\KnowledgeChunk;
use App\Models\KnowledgeStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Lazy ANN-index maintenance for knowledge_chunks.
 *
 * The table stores vectors from different embedding providers with
 * different dimensionalities, which means we can't put a single HNSW
 * index on the raw column (HNSW needs a fixed dim). Instead, when a
 * store grows past {@see INDEX_THRESHOLD} chunks, we create a partial
 * HNSW index filtered to that dimensionality and (optionally) pinned
 * to that specific store id.
 *
 * Multiple indexes coexist happily — pgvector picks the best one at
 * query time based on the WHERE clause and vector dim. Below the
 * threshold, sequential scan is actually faster than building an
 * index so we do nothing.
 */
class IndexMaintenance
{
    /** Create an ANN index once a store has this many chunks. */
    public const INDEX_THRESHOLD = 1000;

    /**
     * Check a store and create an HNSW index for it if it just crossed
     * the threshold. Called after ingest or reindex. Idempotent — if an
     * index already exists for that dim, the call is a no-op.
     */
    public function ensureAnnIndex(KnowledgeStore $store): void
    {
        $count = KnowledgeChunk::where('store_id', $store->id)->count();
        if ($count < self::INDEX_THRESHOLD) {
            return;
        }

        $dims = (int) $store->embedding_dims;
        if ($dims <= 0) {
            return;
        }

        $indexName = "knowledge_chunks_embedding_hnsw_dim{$dims}_idx";

        // Check whether an index with this name already exists — if so
        // we assume it's the correct shape (dim + ops class). pgvector
        // doesn't need one index per store — a single partial index
        // filtered by vector_dims(embedding) covers every store at
        // that dimensionality.
        $exists = DB::selectOne(
            "SELECT 1 FROM pg_indexes WHERE schemaname='public' AND indexname=?",
            [$indexName],
        );
        if ($exists) {
            return;
        }

        // Build a cast-based partial HNSW index so pgvector treats the
        // column as vector(N) for the rows that match. Using a filter
        // on `vector_dims` keeps other-dimensionality rows out of this
        // index. CREATE INDEX CONCURRENTLY is avoided here because it
        // can't run inside the Laravel transaction that dispatches the
        // job — we accept a brief lock on the table during creation.
        $sql = "CREATE INDEX {$indexName} "
            .'ON knowledge_chunks '
            ."USING hnsw ((embedding::vector({$dims})) vector_cosine_ops) "
            ."WHERE vector_dims(embedding) = {$dims}";

        try {
            DB::statement($sql);
            Log::info("built HNSW index {$indexName} for store #{$store->id} with {$count} chunks");
        } catch (\Throwable $e) {
            // Index build failures shouldn't break ingest — log and move on.
            Log::warning("failed to build HNSW index {$indexName}: {$e->getMessage()}");
        }
    }
}
