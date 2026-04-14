<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ACD primitives: agent groups and their (polymorphic) members.
 *
 * An agent group is a platform-level pool of humans + devices that can
 * take calls — operators, supervisors, hardware desk phones, the lot.
 * Tenant call queues reference a group via call_queues.agent_group_id;
 * the queue's strategy decides the order, the group decides who.
 *
 * Members are polymorphic so a group can mix:
 *   - User records (platform staff who use the softphone)
 *   - Extension records (hardware SIP phones, ATAs, etc.)
 *
 * AI agents are NOT members of agent groups. They participate at the
 * routing layer (routing rule destination_type=agent, or a queue's
 * overflow_agent_persona_id), not the ACD layer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique()
                ->comment('Slug used internally and in the Asterisk dialplan generator.');
            $table->string('label');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('agent_group_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_group_id')->constrained()->cascadeOnDelete();

            // Polymorphic: User (staff) or Extension (hardware phone)
            $table->morphs('member');

            // Asterisk queue tunables per-member
            $table->unsignedInteger('priority')->default(0)
                ->comment('Order within the group for sequential strategies.');
            $table->unsignedInteger('penalty')->default(0)
                ->comment('Asterisk queue penalty — lower = preferred.');

            $table->timestamps();

            $table->unique(['agent_group_id', 'member_type', 'member_id'], 'agent_group_member_unique');
        });

        // Now that agent_groups exists, add the FK on call_queues.
        Schema::table('call_queues', function (Blueprint $table) {
            $table->foreign('agent_group_id')
                ->references('id')->on('agent_groups')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('call_queues', function (Blueprint $table) {
            $table->dropForeign(['agent_group_id']);
        });
        Schema::dropIfExists('agent_group_members');
        Schema::dropIfExists('agent_groups');
    }
};
