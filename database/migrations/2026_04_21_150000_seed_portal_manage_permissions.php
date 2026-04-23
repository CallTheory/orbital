<?php

declare(strict_types=1);

use App\Services\Clients\ClientProvisioner;
use Database\Seeders\PermissionCatalogSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;

/**
 * Surface two new management permissions at the tenant level:
 *
 *   - portal.manage_users — gates the portal Users page.
 *   - portal.manage_roles — gates the portal Roles page.
 *
 * Ensures they exist in the catalog, adds them to every existing
 * tenant's allow-list, and re-syncs the client_admin role so the
 * admin surface picks up the new perms without waiting for the
 * next manual provision. Doesn't touch client_user roles — the
 * view-only seed subset stays unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Artisan::call('db:seed', ['--class' => PermissionCatalogSeeder::class, '--force' => true]);

        $provisioner = app(ClientProvisioner::class);

        \App\Models\Team::query()
            ->where('personal_team', false)
            ->whereNotNull('user_id')
            ->each(function (\App\Models\Team $team) use ($provisioner) {
                $owner = \App\Models\User::find($team->user_id);
                if (! $owner) {
                    return;
                }
                $provisioner->provision($team, $owner);
            });
    }

    public function down(): void
    {
        throw new \RuntimeException('seed_portal_manage_permissions is not reversible.');
    }
};
