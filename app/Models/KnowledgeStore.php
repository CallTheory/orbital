<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTeam;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Tenant-scoped knowledge namespace. Holds all chunks that belong to a
 * single "collection" the platform operator has set up for one of their
 * customers — e.g. "ACME Insurance FAQ", "Downtown Legal handbook".
 *
 * Isolation is the core contract here: every KnowledgeStore belongs to
 * exactly one team via BelongsToTeam, and every KnowledgeChunk belongs
 * to exactly one store. Queries must always go through a store (or
 * through the RetrievalService, which validates store_id ownership up
 * front), never against `knowledge_chunks` directly from user input.
 */
class KnowledgeStore extends Model
{
    use BelongsToTeam;
    use SoftDeletes;

    protected $fillable = [
        'team_id',
        'name',
        'description',
        'embedding_model',
        'embedding_dims',
        'ingest_status',
        'chunk_count',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'embedding_dims' => 'integer',
            'chunk_count' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Chunks in this store. Always prefer this relation over querying
     * KnowledgeChunk directly — it's the ORM half of the isolation guarantee.
     */
    public function chunks(): HasMany
    {
        return $this->hasMany(KnowledgeChunk::class, 'store_id');
    }
}
