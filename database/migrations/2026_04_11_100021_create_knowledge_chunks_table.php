<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Chunked text with vector embeddings. One physical table serves every
 * tenant (the "namespace pattern" used by Pinecone / Weaviate / Qdrant)
 * and isolation is enforced at the ORM + API boundary by filtering on
 * store_id, which is team-scoped through knowledge_stores.
 *
 * The pgvector extension is enabled here (the first migration that
 * actually needs it). It's idempotent so subsequent migrations are
 * happy to find it already installed.
 *
 * The `embedding` column uses pgvector's native `vector(1536)` type.
 * Laravel's Blueprint doesn't know about it, so we create the table
 * with everything except the vector column via the normal builder,
 * then tack the column on with a raw ALTER.
 */
return new class extends Migration
{
    public function up(): void
    {
        // pgvector extension — idempotent, safe to re-run. Guarded by
        // driver so the sqlite-backed feature test suite can still
        // migrate this file without blowing up. Knowledge-specific
        // tests that need real vector behavior target pgsql explicitly.
        $isPgsql = DB::connection()->getDriverName() === 'pgsql';
        if ($isPgsql) {
            DB::statement('CREATE EXTENSION IF NOT EXISTS vector');
        }

        Schema::create('knowledge_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('knowledge_stores')->cascadeOnDelete();

            $table->enum('source_type', ['file', 'url', 'text'])->default('text');

            // Human-readable reference — filename, URL, or user-supplied title.
            $table->string('source_ref')->nullable();

            // Position of this chunk within its source document.
            $table->unsignedInteger('chunk_index')->default(0);

            $table->text('content');

            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->index(['store_id', 'chunk_index']);
        });

        // The pgvector `vector` column type is unknown to Laravel's
        // Blueprint, so add it via raw SQL after the table exists.
        //
        // We use unbounded `vector` (no dimension spec) so a single table
        // can hold chunks from multiple providers with different native
        // dimensionalities: OpenAI text-embedding-3-small is 1536,
        // nomic-embed-text is 768, mxbai-embed-large is 1024, etc. Each
        // store records its own `embedding_dims` and the RetrievalService
        // validates the query vector matches the store's dims before
        // running a search.
        //
        // No HNSW / IVFFlat index in v1 — pgvector's HNSW needs a fixed
        // dimension, and we'd rather ship multi-provider flexibility than
        // optimize ANN for a table that will contain at most a few
        // thousand chunks per tenant. When a specific store grows past
        // that threshold we can add a filtered HNSW index keyed to its
        // dimensionality (e.g. WHERE vector_dims(embedding) = 1536).
        // Only pgsql gets the real vector column. On sqlite the table
        // exists without `embedding` at all — tests that need vector
        // ops run on pgsql and never hit this branch.
        if ($isPgsql) {
            DB::statement('ALTER TABLE knowledge_chunks ADD COLUMN embedding vector');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_chunks');
    }
};
