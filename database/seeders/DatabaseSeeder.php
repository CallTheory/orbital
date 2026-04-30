<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            PermissionCatalogSeeder::class,
            SuperAdminRoleSeeder::class,
            HoldMusicClassSeeder::class,
            PersonalityTemplateSeeder::class,
            IntakeGoalLibrarySeeder::class,
            QueueStrategyTemplateSeeder::class,
            SkillCatalogSeeder::class,
            AvailabilityReasonSeeder::class,
            LogoutReasonSeeder::class,
            FirstSuperAdminSeeder::class,
            // AsteriskBackend roster — seeds asterisk-1 and asterisk-2
            // so the SIP Proxy page's AMI probe has targets to hit.
            // Must run before SystemBootstrapSeeder, which generates
            // Asterisk dialplan + dispatcher config from this roster.
            AsteriskBackendSeeder::class,
            // rtpengine roster — co-located with the Kamailio VMs.
            // Failover Central + the rtpengine health probe key off
            // these rows.
            RtpengineNodeSeeder::class,
            // Runs after DB-backed seeding so external services see
            // the final row set — SeaweedFS buckets, Ollama models,
            // Asterisk configs, etc.
            SystemBootstrapSeeder::class,
        ]);

        if (app()->environment('local')) {
            $this->call([
                DemoClientSeeder::class,
                // Template tenants — reusable example configurations
                // for the three common answering-service patterns.
                // Runs after DemoClientSeeder so it can reuse the
                // shared operator group and trunk that seeder creates.
                TemplateClientSeeder::class,
            ]);
        }
    }
}
