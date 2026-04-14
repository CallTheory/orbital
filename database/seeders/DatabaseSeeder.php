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
            FirstSuperAdminSeeder::class,
            // Runs after DB-backed seeding so external services see
            // the final row set — MinIO buckets, Ollama models,
            // Asterisk configs, etc.
            SystemBootstrapSeeder::class,
        ]);

        if (app()->environment('local')) {
            $this->call([
                DemoTenantSeeder::class,
            ]);
        }
    }
}
