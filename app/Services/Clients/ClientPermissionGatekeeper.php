<?php

declare(strict_types=1);

namespace App\Services\Clients;

use App\Models\Team;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The one and only sanctioned write path for tenant-scoped role/permission
 * assignment in Orbital.
 *
 * Every call site that mutates what a user or role can do inside a client
 * MUST go through this service. Direct calls to Spatie's syncPermissions /
 * assignRole / givePermissionTo outside of this service are a bug.
 *
 * Responsibilities:
 * - Enforces the super-admin's per-client "allow list"
 *   (client_permission_grants table)
 * - Strips platform-only permissions defensively
 * - Cascades shrinks: when super-admin narrows the allow list, roles inside
 *   the client have newly disallowed permissions revoked automatically
 */
class ClientPermissionGatekeeper
{
    /**
     * Permissions that MAY NEVER be granted inside a client, regardless of
     * what the super-admin configures. Enforced in every write path.
     */
    public const PLATFORM_ONLY = [
        'platform.manage_tenants',
        'platform.impersonate',
        'platform.view_all_data',
    ];

    /**
     * Return the set of permission names currently allowed in $team.
     *
     * @return array<int, string>
     */
    public function allowedPermissionsFor(Team $team): array
    {
        return DB::table('client_permission_grants as g')
            ->join('permissions as p', 'p.id', '=', 'g.permission_id')
            ->where('g.team_id', $team->id)
            ->pluck('p.name')
            ->all();
    }

    /**
     * Filter requested permissions down to those allowed in $team.
     *
     * @param  array<int, string>  $requested
     * @return array<int, string>
     */
    public function filterToAllowed(Team $team, array $requested): array
    {
        $allowed = $this->allowedPermissionsFor($team);
        return array_values(array_intersect($requested, $allowed));
    }

    /**
     * Throw if any requested permission is outside the client's allow-list
     * or on the PLATFORM_ONLY list.
     *
     * @param  array<int, string>  $requested
     *
     * @throws HttpException
     */
    public function assertAllAllowed(Team $team, array $requested): void
    {
        $platformViolations = array_intersect($requested, self::PLATFORM_ONLY);
        if (! empty($platformViolations)) {
            abort(403, 'Platform-only permissions cannot be granted to clients: '.implode(', ', $platformViolations));
        }

        $allowed = $this->allowedPermissionsFor($team);
        $disallowed = array_diff($requested, $allowed);
        if (! empty($disallowed)) {
            abort(403, 'Requested permissions are not on the client allow-list: '.implode(', ', $disallowed));
        }
    }

    /**
     * Sync permissions onto a tenant-scoped role, enforcing the allow-list.
     *
     * This is the ONLY approved way to modify the permissions attached to a
     * role inside a client. $role must belong to $team. Aborts (403) if any
     * requested permission is outside the allow-list — bugs at call sites
     * fail loudly rather than silently weakening security.
     *
     * @param  array<int, string>  $permissions  Permission names
     */
    public function syncRolePermissions(Team $team, Role $role, array $permissions): void
    {
        abort_unless(
            $role->team_id === $team->id,
            403,
            'Role does not belong to the specified client.'
        );

        $this->assertAllAllowed($team, $permissions);

        $registrar = app(PermissionRegistrar::class);
        $originalTeamId = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId($team->id);

        try {
            $role->syncPermissions($permissions);
        } finally {
            $registrar->setPermissionsTeamId($originalTeamId);
            $registrar->forgetCachedPermissions();
        }
    }

    /**
     * Super-admin only. Writes the client's allow-list (client_permission_grants),
     * then cascades: revokes any now-disallowed permissions from every role in
     * the client.
     *
     * @param  array<int, string>  $permissions  Permission names
     */
    public function setTenantAllowList(Team $team, array $permissions): void
    {
        $user = auth()->user();
        abort_unless($user?->isSuperAdmin(), 403, 'Only super-admins can set the client allow-list.');

        // Strip platform-only permissions defensively.
        $safe = array_values(array_diff($permissions, self::PLATFORM_ONLY));

        DB::transaction(function () use ($team, $safe, $user) {
            // Resolve permission IDs
            $permissionIds = Permission::whereIn('name', $safe)
                ->pluck('id', 'name')
                ->all();

            // Anything requested but not in the catalog is a bug — fail loudly.
            $missing = array_diff($safe, array_keys($permissionIds));
            if (! empty($missing)) {
                throw new PermissionDoesNotExist(
                    'Unknown permissions: '.implode(', ', $missing)
                );
            }

            // Delete grants no longer wanted
            DB::table('client_permission_grants')
                ->where('team_id', $team->id)
                ->whereNotIn('permission_id', array_values($permissionIds))
                ->delete();

            // Upsert current desired grants
            $now = now();
            foreach ($permissionIds as $permissionId) {
                DB::table('client_permission_grants')->updateOrInsert(
                    ['team_id' => $team->id, 'permission_id' => $permissionId],
                    [
                        'granted_by' => $user->id,
                        'granted_at' => $now,
                        'updated_at' => $now,
                        'created_at' => $now,
                    ],
                );
            }

            // Cascade: any permission that was removed must also disappear
            // from every role in the client.
            $this->reconcileTenantRoles($team);
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Walk every role in $team and revoke any permissions no longer on the
     * allow-list. Internal — called after a shrink.
     */
    protected function reconcileTenantRoles(Team $team): void
    {
        $allowed = $this->allowedPermissionsFor($team);

        $registrar = app(PermissionRegistrar::class);
        $originalTeamId = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId($team->id);

        try {
            $roles = Role::where('team_id', $team->id)->get();
            foreach ($roles as $role) {
                $current = $role->permissions()->pluck('name')->all();
                $stillAllowed = array_intersect($current, $allowed);
                if (count($stillAllowed) !== count($current)) {
                    $role->syncPermissions($stillAllowed);
                }
            }
        } finally {
            $registrar->setPermissionsTeamId($originalTeamId);
        }
    }
}
