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
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
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
            $table->index(['team_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_field_definitions');
    }
};
