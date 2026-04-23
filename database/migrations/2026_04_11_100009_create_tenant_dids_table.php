<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DIDs (phone numbers) provisioned through the platform's SIP trunks
 * and assigned to a specific tenant.
 *
 * A tenant typically has multiple DIDs across different trunks for
 * carrier failover. The `priority` column orders them: lower number =
 * higher preference.
 *
 * Inbound calls arriving on a SIP trunk look up the dialed number in
 * this table to identify which tenant the call belongs to. Once the
 * tenant is identified, the tenant's routing rules decide what to do
 * with the call.
 *
 * Note: these are NOT customer-brought-along numbers — they're DIDs
 * the platform operator has provisioned from carriers and assigned to
 * customers. Owned by the platform, used by tenants.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_dids', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('sip_trunk_id')->nullable()->constrained('sip_trunks')->nullOnDelete();

            // The actual phone number. Stored in E.164 ideally (e.g. +15551234567)
            // but unique whatever format you use. Lookup is exact-match in the
            // dial plan, so be consistent.
            $table->string('number', 32)->unique();

            // Optional human label, e.g. "Primary", "Backup", "Disaster Recovery"
            $table->string('label')->nullable();

            // Lower = higher preference for failover ordering.
            $table->unsignedInteger('priority')->default(0);

            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('team_id');
            $table->index('sip_trunk_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_dids');
    }
};
