<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * On Kubernetes the Asterisk backends are discovered pods, keyed by
 * their node IP (what Kamailio's dispatcher list holds):
 *
 *   node_name       Asterisk's systemname (the pod name). ps_contacts
 *                   stamps registrations with it, so it's how a
 *                   backend's registered operators are found.
 *   dispatch_state  active | drain | disable, as last set by an
 *                   operator. The edge VMs' dispatcher list carries it,
 *                   so a drain survives their dispatcher reloads.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asterisk_backends', function (Blueprint $table) {
            $table->string('node_name')->nullable()->after('hostname');
            $table->string('dispatch_state', 16)->default('active')->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('asterisk_backends', function (Blueprint $table) {
            $table->dropColumn(['node_name', 'dispatch_state']);
        });
    }
};
