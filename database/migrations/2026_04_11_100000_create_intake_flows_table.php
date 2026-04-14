<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A tenant-authored composition of intake goals — the sequence of small
 * objectives an AI agent or live operator walks through during a call.
 *
 * Flows are always tenant-scoped (team_id not null). The platform ships
 * the library of goals (intake_goals); tenants compose those goals into
 * flows and bind them to personas, extensions, or routing rules.
 *
 * Order matters for migration timestamps: this table is created BEFORE
 * agent_personas, extensions, and routing_rules so those can add a
 * nullable foreign key to intake_flows without ordering issues. The
 * child table intake_flow_steps comes later (100010) because it also
 * references intake_goals (100004).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('intake_flows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->text('description')->nullable();

            // How this flow gets picked for a call. Informational / auditing;
            // actual resolution lives in AgentFlowCompiler which walks
            // routing_rule → extension → persona.default.
            $table->enum('trigger_type', ['did', 'routing_rule', 'persona_default', 'manual'])
                ->default('persona_default');

            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->index('team_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('intake_flows');
    }
};
