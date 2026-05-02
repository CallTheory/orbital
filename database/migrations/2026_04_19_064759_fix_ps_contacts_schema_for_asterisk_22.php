<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Asterisk 22.9 REGISTER writes to ps_contacts were failing with:
 *
 *   res_odbc ... SQL Execute error -1
 *   res_pjsip_registrar ... Unable to bind contact '...' to AOR '2002'
 *
 * Root cause: our original ps_contacts schema used the wrong types
 * for two columns vs Asterisk 22's canonical postgresql_config.sql:
 *
 *   prune_on_boot — we had FLOAT, Asterisk writes 'yes'/'no' string
 *                   → ODBC parameter bind fails at prepare time.
 *   endpoint      — we had VARCHAR(40), some AOR IDs can be longer
 *                   (trunk prefixes, tenant-scoped names). Bump to
 *                   VARCHAR(255) to match the upstream schema.
 *
 * Also normalize two lengths for parity with Asterisk 22:
 *   path          — 511 → 1024 (Path headers with multiple hops)
 *   user_agent    — 255 → 1024
 *
 * The table is runtime state (Asterisk owns writes). Rows only
 * exist while endpoints are registered; truncating to apply the
 * schema change costs one round of re-REGISTER and nothing else.
 */
return new class extends Migration
{
    public function up(): void
    {
        // pgsql-only schema reshape; sqlite test suite skips this.
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        // TRUNCATE first so the ALTER can safely convert
        // `prune_on_boot` from double → varchar without tripping
        // a cast error on existing rows (there shouldn't be any
        // in steady state, but be defensive).
        DB::statement('TRUNCATE TABLE ps_contacts');

        DB::statement('ALTER TABLE ps_contacts
            ALTER COLUMN prune_on_boot TYPE varchar(3) USING NULL,
            ALTER COLUMN endpoint      TYPE varchar(255),
            ALTER COLUMN path          TYPE varchar(1024),
            ALTER COLUMN user_agent    TYPE varchar(1024)');
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('TRUNCATE TABLE ps_contacts');

        DB::statement('ALTER TABLE ps_contacts
            ALTER COLUMN prune_on_boot TYPE double precision USING NULL,
            ALTER COLUMN endpoint      TYPE varchar(40),
            ALTER COLUMN path          TYPE varchar(511),
            ALTER COLUMN user_agent    TYPE varchar(255)');
    }
};
