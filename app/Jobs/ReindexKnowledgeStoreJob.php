<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\KnowledgeChunk;
use App\Models\KnowledgeStore;
use App\Services\Knowledge\EmbeddingService;
use App\Services\Knowledge\IndexMaintenance;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Pgvector\Laravel\Vector;

/**
 * Re-embed every chunk in a knowledge store using the store's currently
 * configured `embedding_model`. Useful when:
 *   - An operator changes the store's embedding model after ingest
 *   - An embedding model is upgraded upstream and we want parity
 *   - A store's existing chunks were corrupted or written with the
 *     wrong model
 *
 * The job processes chunks in batches of {@see BATCH_SIZE}, embeds each
 * batch with a single provider call when the provider supports batching,
 * and writes the new vectors in place. No data loss — chunks keep their
 * IDs and metadata, just get their embedding column refreshed.
 */
class ReindexKnowledgeStoreJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 1800;

    private const BATCH_SIZE = 32;

    public function __construct(public readonly int $storeId) {}

    public function handle(EmbeddingService $embeddings, IndexMaintenance $indexMaintenance): void
    {
        $store = KnowledgeStore::withoutGlobalScope('team')->findOrFail($this->storeId);
        $store->update(['ingest_status' => 'processing']);

        try {
            KnowledgeChunk::where('store_id', $store->id)
                ->orderBy('id')
                ->chunkById(self::BATCH_SIZE, function ($chunks) use ($store, $embeddings) {
                    $texts = $chunks->pluck('content')->all();
                    $vectors = $embeddings->embedBatch($texts, $store->embedding_model);

                    // Sanity check dims before writing anything.
                    $dims = count($vectors[0] ?? []);
                    if ($dims !== (int) $store->embedding_dims) {
                        throw new \RuntimeException(
                            "Embedding dim mismatch during reindex of store #{$store->id}: "
                            ."store says {$store->embedding_dims}, provider returned {$dims}."
                        );
                    }

                    foreach ($chunks as $i => $chunk) {
                        $chunk->embedding = new Vector($vectors[$i]);
                        $chunk->save();
                    }
                });

            $store->update(['ingest_status' => 'idle']);

            // Maintain per-dim ANN index — idempotent no-op if already built.
            $indexMaintenance->ensureAnnIndex($store->fresh());
        } catch (\Throwable $e) {
            Log::error('knowledge reindex failed', [
                'store_id' => $store->id,
                'error' => $e->getMessage(),
            ]);
            $store->update(['ingest_status' => 'failed']);
            throw $e;
        }
    }
}
