<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a DID's calls go, set on the DID itself. Inbound routing is
 * RoutingRule rows (rendered into the from-trunk dispatcher), which had
 * no admin UI; ClientDidRouting keeps one rule per routed DID in step,
 * linked by client_did_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_dids', function (Blueprint $table) {
            $table->string('destination_type', 32)->nullable()->after('sip_trunk_id');
            $table->unsignedBigInteger('destination_id')->nullable()->after('destination_type');
        });

        Schema::table('routing_rules', function (Blueprint $table) {
            $table->foreignId('client_did_id')->nullable()->after('team_id')
                ->constrained('client_dids')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('routing_rules', function (Blueprint $table) {
            $table->dropConstrainedForeignId('client_did_id');
        });

        Schema::table('client_dids', function (Blueprint $table) {
            $table->dropColumn(['destination_type', 'destination_id']);
        });
    }
};
