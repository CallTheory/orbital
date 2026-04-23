<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Seeds the three team-less platform-staff roles:
 *   - super_admin  — configures everything, sees every tenant
 *   - operator     — takes calls via the softphone
 *   - supervisor   — monitors operators (future; same perms as operator for now)
 *
 * Every role on this installation that represents "platform staff" lives here.
 * Tenant-scoped roles (`client_user`) are created per-tenant by ClientProvisioner.
 */
class SuperAdminRoleSeeder extends Seeder
{
    public function run(): void
    {
        $registrar = app(PermissionRegistrar::class);
        $originalTeamId = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId(null);

        try {
            // super_admin — everything
            $superAdmin = Role::firstOrCreate(
                ['name' => 'super_admin', 'guard_name' => 'web', 'team_id' => null],
                ['color' => '#dc2626'], // red-600
            );
            if (! $superAdmin->color) {
                $superAdmin->update(['color' => '#dc2626']);
            }
            $superAdmin->syncPermissions(Permission::where('guard_name', 'web')->get());

            // operator — softphone + read-only telephony context for their active call
            $operator = Role::firstOrCreate(
                ['name' => 'operator', 'guard_name' => 'web', 'team_id' => null],
                ['color' => '#10b981'], // emerald-500
            );
            if (! $operator->color) {
                $operator->update(['color' => '#10b981']);
            }
            $operator->syncPermissions([
                'softphone.use',
                'softphone.transfer',
                'softphone.park',
                'softphone.conference',
                'extension.view',
                'call_queue.view',
                'script.view',
                'operating_hour.view',
                'call_log.view',
                'agent_persona.view',
            ]);

            // supervisor — stub. Starts with operator perms; platform-specific
            // supervisor-only perms (dashboards, barge-in, etc.) come later.
            $supervisor = Role::firstOrCreate(
                ['name' => 'supervisor', 'guard_name' => 'web', 'team_id' => null],
                ['color' => '#f59e0b'], // amber-500
            );
            if (! $supervisor->color) {
                $supervisor->update(['color' => '#f59e0b']);
            }
            $supervisor->syncPermissions([
                'softphone.use',
                'softphone.transfer',
                'softphone.park',
                'softphone.conference',
                'extension.view_any', 'extension.view',
                'call_queue.view_any', 'call_queue.view',
                'script.view_any', 'script.view',
                'operating_hour.view_any', 'operating_hour.view',
                'call_log.view_any', 'call_log.view', 'call_log.export',
                'agent_persona.view_any', 'agent_persona.view',
                'routing_rule.view_any', 'routing_rule.view',
            ]);
        } finally {
            $registrar->setPermissionsTeamId($originalTeamId);
            $registrar->forgetCachedPermissions();
        }
    }
}
