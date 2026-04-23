<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seed a PJSIP `anonymous` endpoint into the realtime ps_endpoints
 * table.
 *
 * Asterisk's `res_pjsip_endpoint_identifier_anonymous` module routes
 * unmatched SIP requests (those that don't match any identify block
 * or registered AOR) to an endpoint literally named `anonymous`.
 * Without that endpoint, Kamailio's dispatcher OPTIONS probes —
 * sourced from `sip:dispatcher@localhost` which matches no identify —
 * generate a NOTICE per probe cycle:
 *
 *     Request 'OPTIONS' from '<sip:dispatcher@localhost>' failed
 *     for '…' — No matching endpoint found
 *
 * The anonymous endpoint silences that noise: for OPTIONS, pjsip
 * replies 200 OK at the transport layer without executing any
 * dialplan. For INVITEs, the endpoint's context points at a
 * non-existent dialplan entry (`unknown-reject`) so any call attempt
 * from an unidentified peer is rejected, not answered.
 *
 * This row is infrastructure — every install needs it identically —
 * so it lives in a migration rather than the tenant-scoped
 * EndpointSyncer.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('ps_endpoints')->updateOrInsert(
            ['id' => 'anonymous'],
            [
                'context' => 'unknown-reject',
                'disallow' => 'all',
                'allow' => 'ulaw',
                'allow_subscribe' => 'no',
                'direct_media' => 'no',
            ],
        );
    }

    public function down(): void
    {
        DB::table('ps_endpoints')->where('id', 'anonymous')->delete();
    }
};
