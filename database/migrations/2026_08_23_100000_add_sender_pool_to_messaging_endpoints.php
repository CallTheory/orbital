<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Endpoints are identified by their carrier SENDER POOL, not by a bare
 * phone number.
 *
 * A sender pool is the carrier's own grouping of numbers — Twilio calls
 * it a Messaging Service, Telnyx a Messaging Profile, Bandwidth an
 * Application. Sending through one instead of naming a `From` number
 * directly is the difference between the carrier enforcing compliance
 * for us and us being on our own:
 *
 *   - STOP / HELP / START are intercepted and honoured at the carrier,
 *     per-number, for every number in the pool. A bare `From` gets none
 *     of that, and a US number that ignores STOP is a number that gets
 *     de-registered.
 *   - Sticky sender keeps one customer talking to one number across a
 *     conversation instead of a different number each time.
 *   - Number pooling, geomatch, and shortcode failover all live at the
 *     pool. None of them exist for a lone number.
 *
 * Two schema consequences:
 *
 *   `sender_pool_id` — the carrier's identifier for the pool. For
 *   providers that have the concept it is REQUIRED and it becomes the
 *   inbound routing key: Twilio puts `MessagingServiceSid` on every
 *   webhook, so adding a number to the client's pool needs no change in
 *   Orbital at all. That is the real win — the number list stops being
 *   something we have to keep in sync with the carrier.
 *
 *   `address` becomes NULLABLE. With a pool there may be a dozen
 *   numbers behind one endpoint and no single one of them is "the"
 *   address. It stays as an optional display label, and as the routing
 *   key for transports that genuinely have no pool concept (an SMPP
 *   bind, a WCTP pager gateway, the dev Log driver).
 *
 * The existing unique(address, protocol) is left alone deliberately:
 * both Postgres and SQLite treat NULLs as distinct in a unique index,
 * so any number of pool-based endpoints coexist while two endpoints
 * claiming the same literal number still collide.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messaging_endpoints', function (Blueprint $table) {
            $table->string('sender_pool_id', 64)->nullable()->after('provider');

            // The inbound match. Scoped by provider because a pool id is
            // only unique within a carrier, and two clients must never
            // share a pool — that would route one client's customers
            // into another's queue.
            $table->unique(['provider', 'sender_pool_id']);
        });

        Schema::table('messaging_endpoints', function (Blueprint $table) {
            $table->string('address', 64)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('messaging_endpoints', function (Blueprint $table) {
            $table->dropUnique(['provider', 'sender_pool_id']);
            $table->dropColumn('sender_pool_id');
        });
    }
};
