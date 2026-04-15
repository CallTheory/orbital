<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant directory — the tenant's own phone book that AI agents and
 * live operators consult while handling a call. These are the people
 * the tenant's business interacts with: employees, patients, clients,
 * members. They never log in to Orbital and are not users.
 *
 * **Fully tenant-defined schema.** Like `contacts`, the directory
 * table stores every value in a single `values` JSONB column keyed
 * by the slug of a DirectoryFieldDefinition row. Tenants author
 * their own field set via the Directory Fields page — which fits
 * dog-walking rosters, medical patient lists, and employee phone
 * books equally well without the schema forcing any of them into a
 * shape it doesn't want.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('directory_entries', function (Blueprint $table) {
            $table->id();

            // Either team_id (tenant-private entry) OR
            // shared_directory_id (shared platform-level entry
            // attached to multiple tenants via the pivot). Exactly
            // one must be set; CHECK constraint below enforces.
            // The DirectoryEntry global scope unions both sources.
            $table->foreignId('team_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('shared_directory_id')->nullable()
                ->constrained('shared_directories')->cascadeOnDelete();

            // All field values live here, keyed by the slug of a
            // DirectoryFieldDefinition row. Shape mirrors the
            // contacts table's `values` column.
            $table->jsonb('values')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('team_id');
            $table->index('shared_directory_id');
        });

        // Exactly one parent must be set.
        \Illuminate\Support\Facades\DB::statement(<<<'SQL'
            ALTER TABLE directory_entries ADD CONSTRAINT directory_entries_parent_exactly_one
            CHECK (
                (team_id IS NOT NULL AND shared_directory_id IS NULL)
                OR (team_id IS NULL AND shared_directory_id IS NOT NULL)
            )
        SQL);

        // Separate tag vocabulary from contact_tags because the routing
        // semantics differ (after-hours, emergency, spanish-speaker,
        // department-sales, etc). Shared vocabulary would make both
        // sides of the UI noisier for no benefit.
        Schema::create('directory_tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->string('color', 16)->default('gray');
            $table->string('description')->nullable();
            $table->timestamps();

            $table->unique(['team_id', 'slug']);
            $table->index('team_id');
        });

        Schema::create('directory_entry_directory_tag', function (Blueprint $table) {
            $table->foreignId('directory_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('directory_tag_id')->constrained('directory_tags')->cascadeOnDelete();
            $table->primary(['directory_entry_id', 'directory_tag_id'], 'directory_entry_tag_primary');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('directory_entry_directory_tag');
        Schema::dropIfExists('directory_tags');
        Schema::dropIfExists('directory_entries');
    }
};
