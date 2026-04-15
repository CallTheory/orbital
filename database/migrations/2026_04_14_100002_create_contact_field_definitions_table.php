<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-defined schema for the Contacts page. Every row in
 * `contacts.values` is keyed by one of these field slugs.
 *
 * Fully tenant-scoped — no platform defaults, no null `team_id`.
 * New tenants get a starter set seeded by TenantProvisioner on
 * creation; after that the operator is free to add/rename/remove
 * fields via ManageTenantContactFields.
 *
 * The optional `role` column tells downstream code which field plays
 * the canonical "name" / "email" / "phone" / "organization" slot so
 * features like "Grant portal access" and record-title rendering
 * don't depend on the tenant picking specific field keys. At most
 * one field per team can hold a given non-`none` role; the
 * Filament form enforces this at save time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_field_definitions', function (Blueprint $table) {
            $table->id();

            // Tenant-private definitions get team_id set; definitions
            // belonging to a shared contact list get shared_contact_list_id
            // set instead. Contact::resolveFieldByRole() knows which
            // parent column to look up.
            $table->foreignId('team_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('shared_contact_list_id')->nullable()
                ->constrained('shared_contact_lists')->cascadeOnDelete();

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

            // Unique per parent, not across parents. A tenant can
            // have a "name" field and an attached shared list can
            // also have a "name" field — they don't collide.
            $table->unique(['team_id', 'key']);
            $table->unique(['shared_contact_list_id', 'key']);
            $table->index(['team_id', 'sort_order']);
            $table->index(['shared_contact_list_id', 'sort_order']);
        });

        // Exactly one parent must be set.
        \Illuminate\Support\Facades\DB::statement(<<<'SQL'
            ALTER TABLE contact_field_definitions ADD CONSTRAINT contact_field_definitions_parent_exactly_one
            CHECK (
                (team_id IS NOT NULL AND shared_contact_list_id IS NULL)
                OR (team_id IS NULL AND shared_contact_list_id IS NOT NULL)
            )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_field_definitions');
    }
};
