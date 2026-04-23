<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operator skills + shared pool routing.
 *
 * Three tables:
 *
 *   - **skills** — platform-defined vocabulary. Clients don't
 *     author skills; this is an admin-only namespace because
 *     shared operator pools work best with a single normalized
 *     dictionary. Slugs are unique. Examples: `spanish`,
 *     `medical-intake`, `after-hours`, `vip-tier`, `escalation`.
 *
 *   - **skill_user** — pivot attaching skills to users. Carries a
 *     `level` (1–5) so QueueMemberSyncer can weight strong
 *     matches more heavily and a free-text `notes` field for
 *     operator-side context ("certified through 2027",
 *     "trained on Acme platform v2").
 *
 *   - **call_queue_required_skills** — pivot attaching skills to
 *     queues with a `weight` (1–10) representing how strongly the
 *     queue prefers operators with that skill. The QueueMemberSyncer
 *     materializes the (operator × skill × queue × required-skill)
 *     intersection into queue_members ARA rows so Asterisk only
 *     ever rings qualified people.
 *
 * Client tier (`teams.tier`) was added separately and is consumed
 * by QueueMemberSyncer to bias penalty math: enterprise > pro >
 * free, lower penalty = ring first.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('skills', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('description', 500)->nullable();
            $table->string('category', 32)->default('specialty');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('category');
        });

        Schema::create('skill_user', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('skill_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('level')->default(3);
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->primary(['user_id', 'skill_id']);
            $table->index('skill_id');
        });

        Schema::create('call_queue_required_skills', function (Blueprint $table) {
            $table->foreignId('call_queue_id')->constrained()->cascadeOnDelete();
            $table->foreignId('skill_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('weight')->default(5);
            $table->timestamps();

            $table->primary(['call_queue_id', 'skill_id']);
            $table->index('skill_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('call_queue_required_skills');
        Schema::dropIfExists('skill_user');
        Schema::dropIfExists('skills');
    }
};
