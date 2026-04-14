<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Models\Team;
use App\Models\User;
use Database\Seeders\PermissionCatalogSeeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Handles first-time provisioning of a new tenant.
 *
 * In Orbital's platform-operator tenancy model, a tenant is a customer
 * account. Tenants have zero configuration responsibilities — the platform
 * operator does everything. The only role scoped to a tenant is `tenant_user`,
 * which gates access to the read-only customer portal.
 *
 * The `tenant_permission_grants` allow-list is still populated (very
 * restrictively) so that future "delegated tenant" deployments can widen
 * what a tenant can do without changing code. By default it contains only
 * the portal.* permissions.
 *
 * Idempotent.
 */
class TenantProvisioner
{
    /**
     * Default permissions granted to a newly provisioned tenant.
     * Only portal.* — nothing configurable.
     */
    public const DEFAULT_TENANT_ALLOW_LIST = [
        'portal.view_home',
        'portal.view_calls',
        'portal.view_messages',
        'portal.view_recordings',
    ];

    public function __construct(
        protected TenantPermissionGatekeeper $gatekeeper,
    ) {}

    /**
     * Provision a freshly created $team. Call this from CreateTeam actions,
     * from the TenantResource create flow, from tests, or from seeders.
     *
     * If $initialTenantUser is provided, the user gets the `tenant_user` role
     * inside this team (a contact at the customer who can log in to /portal).
     */
    public function provision(Team $team, ?User $initialTenantUser = null): void
    {
        DB::transaction(function () use ($team, $initialTenantUser) {
            $this->seedDefaultAllowList($team);
            $this->createTenantUserRole($team);

            if ($initialTenantUser) {
                $this->assignTenantUser($team, $initialTenantUser);
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Default allow-list for a new tenant: portal read permissions only.
     * The platform operator can widen this via TenantResource → Permission Ceiling.
     *
     * Direct DB insert — skips the gatekeeper's super-admin check so this
     * can run from seeders and provisioning flows without an authenticated user.
     */
    protected function seedDefaultAllowList(Team $team): void
    {
        $permissionIds = Permission::whereIn('name', self::DEFAULT_TENANT_ALLOW_LIST)
            ->pluck('id', 'name');

        $now = now();
        foreach ($permissionIds as $id) {
            DB::table('tenant_permission_grants')->updateOrInsert(
                ['team_id' => $team->id, 'permission_id' => $id],
                ['granted_at' => $now, 'created_at' => $now, 'updated_at' => $now],
            );
        }
    }

    /**
     * Create the per-tenant `tenant_user` role and grant it the default
     * portal permissions.
     */
    protected function createTenantUserRole(Team $team): void
    {
        $role = Role::firstOrCreate(
            ['name' => 'tenant_user', 'guard_name' => 'web', 'team_id' => $team->id],
        );

        $registrar = app(PermissionRegistrar::class);
        $originalTeamId = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId($team->id);

        try {
            // All default-allow-list perms (just portal.* by default)
            $role->syncPermissions(self::DEFAULT_TENANT_ALLOW_LIST);
        } finally {
            $registrar->setPermissionsTeamId($originalTeamId);
            $registrar->forgetCachedPermissions();
        }
    }

    /**
     * Attach a user to the tenant and grant them the `tenant_user` role.
     */
    protected function assignTenantUser(Team $team, User $user): void
    {
        if (! $user->belongsToTeam($team)) {
            $team->users()->attach($user, ['role' => 'member']);
        }

        $registrar = app(PermissionRegistrar::class);
        $originalTeamId = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId($team->id);

        try {
            $user->assignRole('tenant_user');
        } finally {
            $registrar->setPermissionsTeamId($originalTeamId);
        }
    }
}
