<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\KnowledgeStore;
use App\Services\Knowledge\IngestService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Queued wrapper around IngestService. The Filament upload action
 * dispatches this so the request returns immediately; the actual text
 * extraction, chunking, and embedding happen on a Horizon worker.
 */
class IngestKnowledgeJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Embedding API calls can take a while — generous timeout. */
    public int $timeout = 600;

    public function __construct(
        public readonly int $storeId,
        public readonly string $sourceType,
        public readonly string $payload,
        public readonly ?string $sourceRef = null,
    ) {
    }

    public function handle(IngestService $ingest): void
    {
        $store = KnowledgeStore::withoutGlobalScope('team')->findOrFail($this->storeId);

        match ($this->sourceType) {
            'file' => $ingest->ingestFile($store, $this->payload, $this->sourceRef ?? basename($this->payload)),
            'url' => $ingest->ingestUrl($store, $this->payload),
            'text' => $ingest->ingestText($store, $this->payload, sourceType: 'text', sourceRef: $this->sourceRef),
            default => throw new \InvalidArgumentException("Unknown source type: {$this->sourceType}"),
        };
    }
}
