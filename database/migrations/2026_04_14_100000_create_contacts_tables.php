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
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();

            // Optional link to a login user. Null = notification-only
            // contact; set = contact has portal access.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // All field values live here, keyed by the slug of a
            // ContactFieldDefinition row. Shape: { "{key}": <value> }
            // where the value type follows the field's definition type
            // (string, int, bool, array for multi_select, etc).
            $table->jsonb('values')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('team_id');
            $table->index('user_id');
        });

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
