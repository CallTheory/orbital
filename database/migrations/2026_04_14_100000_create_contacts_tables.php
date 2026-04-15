<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant contacts — people on the tenant's side the platform operator
 * communicates with about the account itself. Billing questions,
 * holiday cards, quarterly reviews, escalations, support tickets.
 *
 * These are distinct from `directory_entries` (tenant's own phone book
 * used during call handling — see the sibling migration).
 *
 * **Fully tenant-defined schema.** The contacts table has no fixed
 * person columns — every value lives in a single `values` JSONB
 * column keyed by the slug of a ContactFieldDefinition row. Tenants
 * author their own field set via the Contact Fields page; the
 * semantic `role` on each definition (name/email/phone/organization)
 * lets downstream code find the canonical name/email/phone regardless
 * of what the tenant labeled the field.
 *
 * Most contacts never log in. The optional `user_id` FK promotes a
 * contact to a portal-login user via a "Grant portal access" action;
 * removing the user leaves the contact row intact.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();

            // Either team_id (tenant-private contact) OR
            // shared_contact_list_id (shared platform-level contact
            // attached to multiple tenants). Exactly one must be set;
            // the CHECK constraint below enforces that. The global
            // scope on the Contact model unions both sources so
            // tenant queries see their own rows PLUS rows from any
            // shared lists they're attached to via the pivot.
            $table->foreignId('team_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('shared_contact_list_id')->nullable()
                ->constrained('shared_contact_lists')->cascadeOnDelete();

            // Optional link to a login user. Null = notification-only
            // contact; set = contact has portal access. Only meaningful
            // on tenant-private rows — shared contacts don't own user
            // sessions.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // All field values live here, keyed by the slug of a
            // ContactFieldDefinition row. Shape: { "{key}": <value> }
            // where the value type follows the field's definition type
            // (string, int, bool, array for multi_select, etc).
            $table->jsonb('values')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('team_id');
            $table->index('shared_contact_list_id');
            $table->index('user_id');
        });

        // Exactly one parent must be set. Postgres CHECK constraint
        // because the Laravel schema builder doesn't offer a nice
        // idiom for it. Filament forms enforce the same rule at save
        // time, but the DB is the source of truth.
        \Illuminate\Support\Facades\DB::statement(<<<'SQL'
            ALTER TABLE contacts ADD CONSTRAINT contacts_parent_exactly_one
            CHECK (
                (team_id IS NOT NULL AND shared_contact_list_id IS NULL)
                OR (team_id IS NULL AND shared_contact_list_id IS NOT NULL)
            )
        SQL);

        // Tag library. Platform defaults live with team_id = null and
        // are seeded (billing, holiday, newsletter, escalation,
        // technical, primary, after-hours). Tenants add their own
        // with team_id set — which shadows nothing, just appends.
        Schema::create('contact_tags', function (Blueprint $table) {
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

        Schema::create('contact_contact_tag', function (Blueprint $table) {
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_tag_id')->constrained('contact_tags')->cascadeOnDelete();
            $table->primary(['contact_id', 'contact_tag_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_contact_tag');
        Schema::dropIfExists('contact_tags');
        Schema::dropIfExists('contacts');
    }
};
