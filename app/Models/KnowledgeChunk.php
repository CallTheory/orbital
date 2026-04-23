<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Pgvector\Laravel\HasNeighbors;
use Pgvector\Laravel\Vector;

/**
 * A single chunk of ingested text + its vector embedding.
 *
 * Never query this model directly from user input — always go through the
 * parent KnowledgeStore or RetrievalService. The store_id predicate is the
 * runtime half of our isolation story; without it, a bug in the caller
 * could leak one client's chunks to another.
 *
 * The `embedding` column is a raw pgvector `vector(1536)` — the
 * Pgvector\Laravel\Vector cast turns it into a Vector instance on read
 * and serializes it back to the pgvector text format on write.
 */
class KnowledgeChunk extends Model
{
    use HasNeighbors;

    protected $fillable = [
        'store_id',
        'source_type',
        'source_ref',
        'chunk_index',
        'content',
        'embedding',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'chunk_index' => 'integer',
            'embedding' => Vector::class,
            'metadata' => 'array',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(KnowledgeStore::class, 'store_id');
    }
}
