<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A FlowGraph is a named bundle of intake_flows — the whole canvas
 * authors see in the flow editor, as one addressable object.
 *
 * Graphs belong to a client (team_id). A client can have many
 * graphs (drafts + variants + the active one). Status is a simple
 * two-value enum (`draft` or `active`); there is no archive —
 * deletes are hard and cascade to flows / steps / transitions /
 * rules.
 *
 * One graph is assigned per (client, channel_type) via the
 * `client_channel_assignments` table. Multiple channels can share a
 * graph when that graph carries all five channel-trigger entry
 * flows internally.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flow_graphs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('team_id')->constrained()->cascadeOnDelete();

            $table->string('name', 160);
            $table->text('description')->nullable();

            // draft | active
            $table->string('status', 16)->default('draft');

            $table->timestamps();

            $table->unique(['team_id', 'name']);
            $table->index(['team_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flow_graphs');
    }
};
