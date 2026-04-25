<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Transitions — directed edges between intake flows.
 *
 * After a flow's steps run, its transitions are evaluated in
 * priority order. The first matching condition wins and the call
 * jumps to that transition's `to_flow_id`. A `null` target ends
 * the call. A transition with no condition is a fallback — always
 * matches.
 *
 * `condition` is a JsonLogic tree (https://jsonlogic.com); the
 * renderer converts it to plain English in the LLM prompt so the
 * agent doesn't parse JSON at runtime.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('intake_flow_transitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('from_flow_id')->constrained('intake_flows')->cascadeOnDelete();
            $table->foreignId('to_flow_id')->nullable()->constrained('intake_flows')->cascadeOnDelete();
            $table->json('condition')->nullable(); // JsonLogic; null = unconditional fallback
            $table->string('description', 160)->nullable();
            $table->unsignedInteger('priority')->default(100); // lower = evaluated first
            $table->string('source_handle', 32)->nullable(); // per-handle out-port if we split output nodes later
            $table->timestamps();

            $table->index(['from_flow_id', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('intake_flow_transitions');
    }
};
