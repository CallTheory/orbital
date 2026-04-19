<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dynamic registry of Asterisk backends.
 *
 * Replaces the hard-coded `asterisk-1 / asterisk-2` pair that
 * lived in AsteriskClusterActivity and docker/kamailio/dispatcher.list.
 * Admins add/remove Asterisk nodes through the Filament resource;
 * the service layer regenerates the Kamailio dispatcher.list from
 * this table and reloads the proxy so changes take effect without
 * a file edit + container bounce.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asterisk_backends', function (Blueprint $table) {
            $table->id();
            // Docker / DNS hostname the other services use to reach
            // this Asterisk (SIP + AMI). Must be unique — collisions
            // would make Kamailio's dispatcher list ambiguous.
            $table->string('hostname')->unique();
            // Operator-visible label for the SIP Proxy page and the
            // registration cards. Falls back to hostname in the UI
            // when blank.
            $table->string('display_name')->nullable();
            // SIP listener ports. Defaults match the stock docker
            // build; override when a node listens on non-standard
            // ports (rare).
            $table->unsignedInteger('sip_port')->default(5060);
            // AMI coordinates are stored so the per-node activity
            // poller (AsteriskClusterActivity) can connect without
            // a second env var per node.
            $table->string('ami_host')->nullable();
            $table->unsignedInteger('ami_port')->default(5038);
            // Draining a node from the UI flips is_active=false so
            // the dispatcher regen skips it entirely (fully out of
            // rotation rather than probed-but-drained). Runtime
            // drain state still lives in Kamailio's memory.
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asterisk_backends');
    }
};
