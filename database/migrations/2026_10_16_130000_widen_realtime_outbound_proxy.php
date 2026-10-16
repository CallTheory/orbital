<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * outbound_proxy was sized from Asterisk's sample schema (40), but the
 * Helm chart points trunks at the in-cluster sip-edge Service, e.g.
 * `sip:orbital-orbital-sip-edge.orbital.svc.cluster.local;lr`, which is
 * longer. Every trunk save failed on that insert.
 */
return new class extends Migration
{
    private const TABLES = ['ps_endpoints', 'ps_aors', 'ps_contacts'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->string('outbound_proxy', 255)->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->string('outbound_proxy', 40)->nullable()->change();
            });
        }
    }
};
