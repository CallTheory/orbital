<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            // Customer-facing identifier (mutable). Manually assigned by
            // platform operators — never auto-generated.
            $table->unsignedBigInteger('account_number')->nullable()->unique()->after('name');

            // Quotas / suspension. max_users is hidden from the UI but the
            // column stays for future use.
            //
            // We don't expose max_sip_trunks / max_extensions as tenant
            // quotas because tenants are read-only customer accounts —
            // the platform operator owns trunks and extensions on their
            // behalf. Only max_concurrent_calls is billing-relevant.
            $table->unsignedInteger('max_users')->nullable()->after('personal_team');
            $table->unsignedInteger('max_concurrent_calls')->nullable()->after('max_users');
            $table->timestamp('suspended_at')->nullable()->after('max_concurrent_calls');

            // Per-tenant call recording overrides. When null, the tenant
            // inherits every recording setting from the platform defaults
            // (services.recording.* in SettingsRegistry). Individual keys
            // that ARE present override just that setting.
            //
            // Shape:
            //   { "enabled": true, "format": "mp3", "retention_days": 90, "beep_on_record": false }
            //
            // Any of those keys may be omitted — the resolver walks
            // extension → tenant → global and takes the first defined
            // value per key.
            $table->json('recording_overrides')->nullable()->after('suspended_at');
        });
    }

    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn([
                'account_number',
                'max_users',
                'max_concurrent_calls',
                'suspended_at',
                'recording_overrides',
            ]);
        });
    }
};
