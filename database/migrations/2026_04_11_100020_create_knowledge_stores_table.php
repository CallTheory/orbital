<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A knowledge store is a tenant-scoped namespace for retrievable chunks —
 * a "collection" in Pinecone/Weaviate parlance. Every chunk belongs to
 * exactly one store, and every store belongs to exactly one tenant via
 * BelongsToTeam, giving us defense-in-depth isolation.
 *
 * Each store records which embedding model produced its chunks so we can
 * reject mismatched queries and, later, drive a re-embedding pipeline
 * when a model is upgraded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_stores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->text('description')->nullable();

            // provider:model — e.g. "openai:text-embedding-3-small". All chunks
            // under this store were embedded by this exact model.
            $table->string('embedding_model')->default('openai:text-embedding-3-small');
            $table->unsignedInteger('embedding_dims')->default(1536);

            $table->enum('ingest_status', ['idle', 'processing', 'failed'])->default('idle');
            $table->unsignedInteger('chunk_count')->default(0);

            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->index('team_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_stores');
    }
};
