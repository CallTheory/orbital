<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-(client, orchestration, key) mapping from a binding key declared
 * inside an orchestration's step_params to a concrete team-scoped
 * resource (agent persona, queue, extension, knowledge store, DID set).
 *
 * Single-resource bindings populate `resource_id`. Multi-resource
 * bindings (e.g. a list of DIDs) populate `resource_ids` JSON.
 *
 * Cascades from team and orchestration tear down the row. Deletion of
 * the *targeted* resource (the persona, queue, etc.) is intentionally
 * not cascaded — the runtime surfaces a structured "unbound" error so
 * authors can spot a missing binding rather than silently losing it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orchestration_bindings', function (Blueprint $table) {
            $table->id();

            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('orchestration_id')->constrained()->cascadeOnDelete();

            $table->string('binding_key', 64);
            $table->string('resource_type', 32);

            $table->unsignedBigInteger('resource_id')->nullable();
            $table->json('resource_ids')->nullable();

            $table->timestamps();

            $table->unique(['team_id', 'orchestration_id', 'binding_key']);
            $table->index(['orchestration_id', 'binding_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orchestration_bindings');
    }
};
