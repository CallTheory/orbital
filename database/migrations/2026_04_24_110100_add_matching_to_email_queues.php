<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pull email-channel matching up to the EmailQueue row.
 *
 * `matched_addresses` is a JSON array of local-part patterns (or
 * full addresses) the queue owns. The inbound-mail router resolves
 * an incoming message to a queue by matching its `To:` / envelope
 * against this list.
 *
 * `matched_domain` scopes patterns to a specific tenant domain
 * (useful when clients share a pool of inbound domains). Optional —
 * null means "match across any tenant domain assigned to this
 * client".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_queues', function (Blueprint $table) {
            if (! Schema::hasColumn('email_queues', 'matched_addresses')) {
                $table->json('matched_addresses')->nullable()->after('description');
            }
            if (! Schema::hasColumn('email_queues', 'matched_domain')) {
                $table->string('matched_domain', 255)->nullable()->after('matched_addresses');
            }
        });
    }

    public function down(): void
    {
        Schema::table('email_queues', function (Blueprint $table) {
            if (Schema::hasColumn('email_queues', 'matched_domain')) {
                $table->dropColumn('matched_domain');
            }
            if (Schema::hasColumn('email_queues', 'matched_addresses')) {
                $table->dropColumn('matched_addresses');
            }
        });
    }
};
