<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('extensions', function (Blueprint $table) {
            $table->id();
            // Nullable: null means platform-wide (e.g. operator WebRTC extensions,
            // which belong to platform staff, not any specific tenant).
            $table->foreignId('team_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('number', 20);
            // Device type. Manual platform device types (added through the
            // Phone Extensions resource): sip_phone, ata, softphone, webrtc_client.
            // Auto-managed staff softphone: staff_softphone (hidden from the
            // Phone Extensions list, surfaced on the Staff user page).
            // Tenant-only types: ai_agent, virtual.
            $table->enum('type', [
                'sip_phone',
                'ata',
                'softphone',
                'webrtc_client',
                'staff_softphone',
                'ai_agent',
                'virtual',
            ])->default('sip_phone');
            $table->string('label')->nullable();
            $table->nullableMorphs('assignable');
            $table->string('sip_username')->nullable();
            $table->text('sip_password')->nullable();
            $table->enum('transport', ['udp', 'tcp', 'tls', 'wss'])->default('udp');
            $table->string('context')->default('internal');
            $table->string('mailbox')->nullable();
            // Optional per-extension override of the persona's default intake flow.
            // When set, the AgentFlowCompiler picks this flow instead of
            // persona.default_flow_id. Useful when the same persona handles
            // multiple DIDs with different intake scripts.
            $table->foreignId('intake_flow_id')
                ->nullable()
                ->constrained('intake_flows')
                ->nullOnDelete();

            // Per-extension recording mode. `inherit` defers to the
            // tenant's override → platform default; `always` / `never`
            // force the behavior regardless of what's upstream. Useful
            // for one-off "do not record this line" exceptions like
            // a legal hotline or a tenant's executive suite.
            $table->enum('recording_mode', ['inherit', 'always', 'never'])
                ->default('inherit');

            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['team_id', 'number']);
            $table->index('team_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('extensions');
    }
};
