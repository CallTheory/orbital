<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pivot: which DIDs route to which call queue.
 *
 * A DID belongs to at most one queue at a time (unique
 * `client_did_id`). This replaces the per-trigger-flow
 * `did_ids` step param — matching now lives on the queue row,
 * and the flow graph doesn't know how it was triggered.
 *
 * Cascade-delete both sides — removing a queue or a DID cleans
 * up the pivot automatically.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('call_queue_dids', function (Blueprint $table) {
            $table->id();

            $table->foreignId('call_queue_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('client_did_id')
                ->constrained('client_dids')
                ->cascadeOnDelete();

            $table->timestamps();

            // Enforce "one queue per DID" — a call on a given DID
            // has exactly one destination queue.
            $table->unique('client_did_id');
            $table->index('call_queue_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('call_queue_dids');
    }
};
