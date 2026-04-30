<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add SIP Call-ID correlation key to `call_logs`.
 *
 * Today CallLog correlates to recordings via Asterisk uniqueid
 * (`unique_id` column). Post-rtpengine the correlation key is
 * the SIP Call-ID — that's what rtpengine's recording-daemon
 * stamps onto each WAV's metadata, and what Kamailio routes on.
 *
 * Index it so the upload-watcher can resolve a freshly-finalized
 * spool file back to its CallLog row in O(1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('call_logs', function (Blueprint $table) {
            $table->string('sip_call_id')->nullable()->after('linked_id');
            $table->index('sip_call_id');
        });
    }

    public function down(): void
    {
        Schema::table('call_logs', function (Blueprint $table) {
            $table->dropIndex(['sip_call_id']);
            $table->dropColumn('sip_call_id');
        });
    }
};
