<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform-level containers for shared directory + contact resources.
 *
 * These tables just hold the name / description for a shared set;
 * the actual content lives in the existing `contacts`,
 * `directory_entries`, `contact_field_definitions`, and
 * `directory_field_definitions` tables, each of which now has a
 * nullable `shared_*_id` column so a row can belong to either a
 * tenant OR a shared container — not both (CHECK constraints on
 * each target table enforce that).
 *
 * Access control: a tenant sees a shared container's rows only
 * when a `team_shared_*` pivot row links them. Two pivots, one
 * per container type, both with a simple `is_active` toggle so a
 * super-admin can temporarily detach without destroying the link.
 *
 * Runs BEFORE the existing contacts/directory migrations via its
 * timestamp (`099000` < `100000`) so the downstream nullable FKs
 * can point at these tables at create time.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Shared contact lists — the "platform-level Rolodex"
        // concept. Created and managed by super-admins under
        // /admin/shared-contact-lists, attached to tenants via
        // the team_shared_contact_list pivot.
        Schema::create('shared_contact_lists', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();
        });

        // Shared directories — the "platform-level phone book"
        // concept. Same pattern as contact lists, different
        // consumer (directory entries get looked up during call
        // handling; contacts are reached out to for account
        // business).
        Schema::create('shared_directories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();
        });

        // Pivot: which tenants can see which shared contact list.
        // `is_active` lets a super-admin temporarily detach a
        // shared list from a tenant without deleting the pivot
        // row and losing their settings.
        Schema::create('team_shared_contact_list', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('shared_contact_list_id')->constrained('shared_contact_lists')->cascadeOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['team_id', 'shared_contact_list_id']);
            $table->index(['team_id', 'is_active']);
        });

        // Pivot: same shape for shared directories.
        Schema::create('team_shared_directory', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('shared_directory_id')->constrained('shared_directories')->cascadeOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['team_id', 'shared_directory_id']);
            $table->index(['team_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_shared_directory');
        Schema::dropIfExists('team_shared_contact_list');
        Schema::dropIfExists('shared_directories');
        Schema::dropIfExists('shared_contact_lists');
    }
};
