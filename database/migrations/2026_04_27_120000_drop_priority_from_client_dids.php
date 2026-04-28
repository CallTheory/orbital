<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drop `priority` from `client_dids`.
 *
 * Documented as a carrier-failover ordering hint, but no service ever
 * consumed it. Only effect was sort order in Team::tenantDids and
 * the editor DID picker — both now sort by `number` instead. If
 * outbound carrier failover lands later, it'll need trunk-level
 * retry detection + dialplan work and won't key off this column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_dids', function (Blueprint $table) {
            if (Schema::hasColumn('client_dids', 'priority')) {
                $table->dropColumn('priority');
            }
        });
    }

    public function down(): void
    {
        Schema::table('client_dids', function (Blueprint $table) {
            if (! Schema::hasColumn('client_dids', 'priority')) {
                $table->unsignedInteger('priority')->default(0);
            }
        });
    }
};
