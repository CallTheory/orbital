<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Asterisk 22 writes a value longer than 3 chars to one of our
 * yesno varchar(3) columns. Observed ODBC error on REGISTER:
 *
 *   ERROR: value too long for type character varying(3)
 *
 * Unclear which of the three (authenticate_qualify, prune_on_boot,
 * qualify_2xx_only) is the offender — driver padding, enum
 * expansion, or simply a newer Asterisk value set could all
 * do it. Widening all three to varchar(8) gives headroom for
 * any reasonable yesno-adjacent string without loss of semantic
 * meaning. Asterisk still writes 'yes' / 'no'; the extra bytes
 * are just slack.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE ps_contacts
            ALTER COLUMN authenticate_qualify TYPE varchar(8),
            ALTER COLUMN prune_on_boot        TYPE varchar(8),
            ALTER COLUMN qualify_2xx_only     TYPE varchar(8)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE ps_contacts
            ALTER COLUMN authenticate_qualify TYPE varchar(3),
            ALTER COLUMN prune_on_boot        TYPE varchar(3),
            ALTER COLUMN qualify_2xx_only     TYPE varchar(3)');
    }
};
