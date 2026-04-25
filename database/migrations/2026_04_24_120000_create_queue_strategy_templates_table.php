<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform-operator-owned call-queue strategy templates.
 *
 * Clients don't hand-tune `strategy` / `timeout` / `retry` /
 * `wrapup_time` on their queues — managing a long tail of
 * variations across hundreds of accounts doesn't scale. Instead
 * the platform curates a small set of named templates
 * ("Ring-All 30s", "Least Recent 45s", etc.) and clients pick one
 * when they create a queue.
 *
 * Platform-scoped — no `team_id`. Seeded with a handful of sane
 * defaults in QueueStrategyTemplateSeeder.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('queue_strategy_templates', function (Blueprint $table) {
            $table->id();

            $table->string('name', 120);
            $table->text('description')->nullable();

            // ringall | roundrobin | leastrecent | random | fewestcalls
            $table->string('strategy', 32);

            $table->unsignedSmallInteger('timeout')->default(30);
            $table->unsignedSmallInteger('retry')->default(5);
            $table->unsignedSmallInteger('wrapup_time')->default(0);

            $table->boolean('is_default')->default(false);

            $table->timestamps();

            $table->unique('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('queue_strategy_templates');
    }
};
