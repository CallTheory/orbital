<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An Orchestration is a named bundle of intake_flows — the whole
 * canvas authors see in the flow editor, addressable as one object.
 *
 * Orchestrations belong to a client (team_id). A client can have
 * many orchestrations (one for each scenario the author wants to
 * stage). There is no manual `status` column — the "Active" label
 * in lists is derived live from "is at least one queue pointing at
 * this orchestration?". Deletes are hard and cascade to
 * intake_flows + steps + transitions + rules.
 *
 * Queues (call + email) carry an `orchestration_id` FK; that's the
 * only way an orchestration becomes routable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orchestrations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('team_id')->constrained()->cascadeOnDelete();

            $table->string('name', 160);
            $table->text('description')->nullable();

            $table->timestamps();

            $table->unique(['team_id', 'name']);
            $table->index('team_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orchestrations');
    }
};
