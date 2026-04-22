<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class PermissionCatalogSeeder extends Seeder
{
    /**
     * The complete fixed catalog of Orbital permissions.
     *
     * New permissions should be added here and the seeder re-run. Permissions
     * are NEVER created at runtime — this is the only source of truth.
     */
    public const CATALOG = [
        // ────────────────────────────────────────────────────────────
        // Domain resources (all super-admin managed — no tenant self-service)
        // Kept as a flat catalog so future "delegated tenant" deployments
        // can selectively expose resources via TenantPermissionGatekeeper.
        // ────────────────────────────────────────────────────────────
        'sip_trunk.view_any', 'sip_trunk.view', 'sip_trunk.create', 'sip_trunk.update', 'sip_trunk.delete',
        'extension.view_any', 'extension.view', 'extension.create', 'extension.update', 'extension.delete',
        'agent_persona.view_any', 'agent_persona.view', 'agent_persona.create', 'agent_persona.update', 'agent_persona.delete',
        'script.view_any', 'script.view', 'script.create', 'script.update', 'script.delete',
        'call_queue.view_any', 'call_queue.view', 'call_queue.create', 'call_queue.update', 'call_queue.delete',
        'routing_rule.view_any', 'routing_rule.view', 'routing_rule.create', 'routing_rule.update', 'routing_rule.delete',
        'operating_hour.view_any', 'operating_hour.view', 'operating_hour.create', 'operating_hour.update', 'operating_hour.delete',

        // Call logs (system-generated, no create/update/delete)
        'call_log.view_any', 'call_log.view', 'call_log.export',

        // ────────────────────────────────────────────────────────────
        // Softphone (platform staff taking calls)
        // ────────────────────────────────────────────────────────────
        'softphone.use', 'softphone.transfer', 'softphone.park', 'softphone.conference',

        // ────────────────────────────────────────────────────────────
        // Customer portal
        // ────────────────────────────────────────────────────────────
        // Read-side — default `tenant_user` role perms.
        'portal.view_home',
        'portal.view_calls',
        'portal.view_messages',
        'portal.view_recordings',
        // Management — `tenant_admin` grants, gate the portal
        // Users/Roles admin pages. Never added to `tenant_user`;
        // a tenant admin can hand them out via custom roles.
        'portal.manage_users',
        'portal.manage_roles',

        // ────────────────────────────────────────────────────────────
        // System tooling (Horizon, Telescope, Pulse, Grafana under Monitor)
        // ────────────────────────────────────────────────────────────
        'tooling.horizon', 'tooling.telescope', 'tooling.pulse', 'tooling.grafana', 'tooling.redis_commander', 'tooling.icecast', 'tooling.seaweedfs', 'tooling.pgadmin', 'tooling.prometheus', 'tooling.mailpit', 'tooling.haproxy_stats',

        // ────────────────────────────────────────────────────────────
        // Platform-only (NEVER grantable to a tenant — enforced by the gatekeeper)
        // ────────────────────────────────────────────────────────────
        'platform.manage_tenants', 'platform.impersonate', 'platform.view_all_data',
    ];

    public function run(): void
    {
        $registrar = app(PermissionRegistrar::class);
        $originalTeamId = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId(null);

        try {
            foreach (self::CATALOG as $name) {
                Permission::findOrCreate($name, 'web');
            }
        } finally {
            $registrar->setPermissionsTeamId($originalTeamId);
            $registrar->forgetCachedPermissions();
        }
    }
}
