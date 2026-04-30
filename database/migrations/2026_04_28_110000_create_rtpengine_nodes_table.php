<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dynamic registry of rtpengine media-relay nodes.
 *
 * Mirrors the `asterisk_backends` shape — admins add/remove
 * rtpengine nodes through the Filament resource; the service
 * layer fans out NG-protocol commands across every active row
 * (drain / activate / disable / list / statistics).
 *
 * Nodes are co-located on the Kamailio VMs in Phase 1; the
 * recording-spool path is per-node so a multi-VM topology
 * keeps each node's WAVs isolated until the upload pipeline
 * picks them up.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rtpengine_nodes', function (Blueprint $table) {
            $table->id();
            // Hostname the rest of the platform uses to reach this
            // node's NG control + Prometheus endpoints. Must be
            // unique — collisions would make the fan-out ambiguous.
            $table->string('hostname')->unique();
            // Operator-visible label for the FailoverCentral row
            // and the Filament list. Falls back to hostname when blank.
            $table->string('display_name')->nullable();
            // NG protocol control socket (bencode over UDP). Defaults
            // match the stock build; loopback-only on the rtpengine
            // host, the Laravel app reaches it via per-node `ng_host`.
            $table->string('ng_host')->nullable();
            $table->unsignedInteger('ng_port')->default(22222);
            // Prometheus `--listen-prom` endpoint exposed by rtpengine
            // 11.x. Scraped by the platform Prometheus and surfaced
            // on the Grafana rtpengine dashboard.
            $table->string('prom_host')->nullable();
            $table->unsignedInteger('prom_port')->default(9059);
            // Recording-daemon spool directory on this node. The
            // upload watcher reads paired `*-recv.wav` / `*-send.wav`
            // files from here and creates `call_recordings` rows.
            $table->string('recording_spool_path')->default('/var/spool/rtpengine');
            // Disabling a node from the UI flips this so health
            // probes + drain fan-out skip it. Runtime drain state
            // (in-flight calls finishing) lives in rtpengine memory.
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rtpengine_nodes');
    }
};
