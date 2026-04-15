<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-defined schema for the Directory page. Parallel to
 * contact_field_definitions but intentionally a separate table —
 * directory routing needs a different vocabulary and the two lists
 * don't benefit from sharing a pool (same argument as contact_tags
 * vs directory_tags).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('directory_field_definitions', function (Blueprint $table) {
            $table->id();

            // Tenant-private definitions get team_id set; definitions
            // belonging to a shared directory get shared_directory_id
            // set instead. DirectoryEntry::resolveFieldByRole() knows
            // which parent column to look up.
            $table->foreignId('team_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('shared_directory_id')->nullable()
                ->constrained('shared_directories')->cascadeOnDelete();

            $table->string('key');
            $table->string('label');
            $table->string('type', 32);
            $table->jsonb('options')->nullable();
            $table->string('role', 32)->default('none');
            $table->boolean('required')->default(false);
            $table->string('help_text')->nullable();
            $table->string('placeholder')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['team_id', 'key']);
            $table->unique(['shared_directory_id', 'key']);
            $table->index(['team_id', 'sort_order']);
            $table->index(['shared_directory_id', 'sort_order']);
        });

        // Exactly one parent must be set.
        \Illuminate\Support\Facades\DB::statement(<<<'SQL'
            ALTER TABLE directory_field_definitions ADD CONSTRAINT directory_field_definitions_parent_exactly_one
            CHECK (
                (team_id IS NOT NULL AND shared_directory_id IS NULL)
                OR (team_id IS NULL AND shared_directory_id IS NOT NULL)
            )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('directory_field_definitions');
    }
};
