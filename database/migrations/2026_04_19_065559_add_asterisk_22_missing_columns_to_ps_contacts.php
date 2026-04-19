<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Asterisk 22 added `qualify_2xx_only` to ps_contacts (controls
 * whether qualify probes consider only 2xx responses as "alive"
 * vs accepting 4xx like 401 as proof of life). Our original
 * schema predated this column, so Asterisk's ODBC INSERT fails
 * at parameter prepare time with:
 *
 *   ERROR: column "qualify_2xx_only" of relation "ps_contacts"
 *          does not exist
 *
 * Canonical type is yesno_values (`yes`/`no` varchar(3)).
 *
 * The fix is purely additive — Asterisk owns writes to the table,
 * so no data migration needed. Existing rows (if any) get NULL
 * for the new column which is the effective default.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ps_contacts', function (Blueprint $table): void {
            $table->string('qualify_2xx_only', 3)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ps_contacts', function (Blueprint $table): void {
            $table->dropColumn('qualify_2xx_only');
        });
    }

    // Companion ALTER's done below via raw SQL, outside the
    // Schema::table closure, because Laravel's change() requires
    // doctrine/dbal and adds a complexity we don't need.
};
