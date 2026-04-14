<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_personas', function (Blueprint $table) {
            $table->id();

            // Template/instance model:
            //   team_id = null && template_id = null → platform library template
            //   team_id = set  && template_id = set  → tenant instance linked to a template
            //   team_id = set  && template_id = null → tenant-original (no template backing)
            $table->foreignId('team_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('template_id')->nullable()->constrained('agent_personas')->nullOnDelete();

            $table->string('name');
            $table->string('role');
            $table->text('description')->nullable();
            $table->string('avatar_path')->nullable();
            $table->longText('system_prompt')->nullable();
            $table->text('greeting')->nullable();
            $table->text('outbound_greeting')->nullable();
            $table->longText('personality')->nullable();
            $table->string('voice_id')->nullable();
            $table->json('voice_config')->nullable();
            $table->string('llm_provider')->default('anthropic');
            $table->string('llm_model')->default('claude-sonnet-4-20250514');
            $table->string('stt_provider')->default('elevenlabs');
            $table->string('tts_provider')->default('elevenlabs');
            $table->json('tools_config')->nullable();

            // Baseline intake flow used when this persona handles a call, unless
            // overridden at the extension or routing-rule level (see AgentFlowCompiler
            // resolution order). Nullable: a persona without a default flow falls
            // through to the compiler's null-flow path.
            $table->foreignId('default_flow_id')
                ->nullable()
                ->constrained('intake_flows')
                ->nullOnDelete();

            // Per-field overrides for instances. Keys are field names; values replace
            // the template value. Fields not in overrides fall through to the template.
            $table->json('overrides')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('team_id');
            $table->index('template_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_personas');
    }
};
