<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('routing_rules', function (Blueprint $table) {
            $table->id();

            // Tenant-scoped rules only. Tenant identification happens upstream
            // via tenant_dids lookup. These rules decide what to DO with a call
            // once the tenant is known.
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();

            $table->string('name');

            // Optional trunk constraint — limit the rule to calls arriving on
            // a specific trunk.
            $table->foreignId('sip_trunk_id')->nullable()->constrained()->nullOnDelete();

            // How to match the incoming call. Most rules use 'did' which matches
            // against the DID dialed. Advanced/edge cases can match the SIP
            // request URI user, To header user, From header user (caller ID),
            // or a regex pattern against any of those.
            $table->enum('match_type', [
                'did',
                'request_uri_user',
                'to_header_user',
                'from_header_user',
                'pattern',
            ])->default('did');

            // The value to match. For 'did' types, this is a number. For
            // 'pattern', this is a regex.
            $table->string('match_pattern')->nullable();

            $table->json('time_condition')->nullable();

            $table->enum('destination_type', ['extension', 'queue', 'agent', 'voicemail', 'ivr'])->default('queue');
            $table->unsignedBigInteger('destination_id')->nullable();

            // Highest-priority flow override — when set, every call matched by
            // this rule uses this flow regardless of persona/extension defaults.
            $table->foreignId('intake_flow_id')
                ->nullable()
                ->constrained('intake_flows')
                ->nullOnDelete();

            $table->unsignedInteger('priority')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('team_id');
            $table->index(['destination_type', 'destination_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('routing_rules');
    }
};
