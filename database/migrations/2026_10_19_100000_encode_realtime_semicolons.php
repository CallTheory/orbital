<?php

declare(strict_types=1);

use App\Services\Telephony\Realtime\RealtimeValue;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Re-encode realtime values written before RealtimeValue existed.
 * Asterisk split them on ";", so trunks' `sip:proxy;lr` outbound proxy
 * was read as "lr" and every outbound call through a trunk failed.
 *
 * Encoded values never contain ";", so only rows still holding one are
 * rewritten, which makes this safe to re-run.
 */
return new class extends Migration
{
    private const COLUMNS = [
        ['ps_endpoints', 'outbound_proxy'],
        ['ps_endpoints', 'callerid'],
        ['ps_aors', 'outbound_proxy'],
        ['ps_auths', 'password'],
    ];

    public function up(): void
    {
        foreach (self::COLUMNS as [$table, $column]) {
            DB::table($table)->where($column, 'like', '%;%')->select(['id', $column])->get()
                ->each(fn ($row) => DB::table($table)->where('id', $row->id)->update([
                    $column => RealtimeValue::encode($row->{$column}),
                ]));
        }
    }

    public function down(): void
    {
        // Decoding would bring the bug back.
    }
};
