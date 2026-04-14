<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('call_queues', function (Blueprint $table) {
            $table->id();
            // Nullable: null means platform-wide (system fallback queue for outages
            // or general "ring all idle operators" scenarios).
            $table->foreignId('team_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->enum('strategy', ['ringall', 'roundrobin', 'leastrecent', 'random', 'fewestcalls'])->default('ringall');
            $table->unsignedInteger('timeout')->default(30);
            $table->unsignedInteger('retry')->default(5);
            $table->unsignedInteger('wrapup_time')->default(0);
            $table->unsignedInteger('max_callers')->default(0);
            $table->string('music_on_hold')->default('default');
            $table->boolean('join_empty')->default(false);
            $table->boolean('leave_when_empty')->default(true);
            $table->foreignId('overflow_agent_persona_id')->nullable()->constrained('agent_personas')->nullOnDelete();

            // Which platform-level agent group rings when this queue activates.
            // FK constraint is added by the agent_groups migration once the
            // referenced table exists.
            $table->unsignedBigInteger('agent_group_id')->nullable();
            $table->index('agent_group_id');

            $table->timestamps();
            $table->softDeletes();

            $table->index('team_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('call_queues');
    }
};
