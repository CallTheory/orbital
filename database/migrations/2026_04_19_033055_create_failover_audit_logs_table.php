<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit log for the Failover Central admin page. Every action an
 * operator takes on the HA control planes (Patroni switchover,
 * Sentinel failover, HAProxy drain, Kamailio drain, etc.) writes
 * one row here with who/when/what/result for post-incident review.
 *
 * Platform-scoped (not tenant-scoped) — HA actions are operator
 * territory and any tenant-scoped queries on this table would be
 * a security signal, not a feature.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('failover_audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->timestampTz('occurred_at')->useCurrent();
            $table->foreignId('actor_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            // Short slug for the tier: "postgres", "valkey",
            // "seaweedfs", "haproxy", "kamailio", "livekit", "edge".
            $table->string('tier', 32)->index();
            // Short slug for the action: "switchover", "failover",
            // "drain", "activate", "disable-server", "enable-server".
            $table->string('action', 48)->index();
            // Target node / server / URI. NULL when the action
            // applies to the whole tier (e.g. a cluster-level
            // switchover with no explicit target).
            $table->string('target')->nullable();
            $table->boolean('success');
            // Human-readable output from the control-plane call.
            // Truncated at the app layer if very long.
            $table->text('output')->nullable();
            $table->timestampsTz();

            $table->index(['tier', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('failover_audit_logs');
    }
};
