<?php

declare(strict_types=1);

namespace App\Services\Clients;

use App\Models\DirectoryFieldDefinition;
use App\Models\EmailQueue;
use App\Models\EmailRoutingRule;
use App\Models\Team;
use App\Models\User;
use App\Services\Flows\ChannelTriggerSeeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Handles first-time provisioning of a new client.
 *
 * In Orbital's platform-operator tenancy model, a client is a customer
 * account. Clients have zero configuration responsibilities — the platform
 * operator does everything. Two tenant-scoped roles are seeded:
 *
 *   - `client_admin` — holds every permission on the client's allow-list.
 *     The client's own admin uses this to delegate access to their staff.
 *     Every client must have at least one user carrying this role; the
 *     portal Users page blocks removing or demoting the last admin.
 *   - `client_user` — default role on invite acceptance; carries only the
 *     portal.view_* permissions so fresh invitees can see the dashboard
 *     without being over-privileged.
 *
 * The `client_permission_grants` allow-list is populated restrictively
 * by default so that future "delegated client" deployments can widen
 * what a client admin is able to grant without changing code.
 *
 * Idempotent.
 */
class ClientProvisioner
{
    /**
     * Everything that's on a freshly provisioned client's allow-list.
     * The admin role picks up ALL of these; the default client_user
     * role picks up only the view-only subset below. Management perms
     * (portal.manage_*) live on the allow-list so a client admin can
     * hand them out via custom roles, but aren't granted automatically
     * to everyone invited to the client.
     */
    public const DEFAULT_TENANT_ALLOW_LIST = [
        'portal.view_home',
        'portal.view_calls',
        'portal.view_messages',
        'portal.view_recordings',
        'portal.manage_users',
        'portal.manage_roles',
    ];

    /**
     * View-only subset used to seed the `client_user` role on fresh
     * clients. Intentionally EXCLUDES the manage_* perms so invitees
     * can see the portal dashboard without being able to manage
     * membership or roles — those are admin concerns.
     */
    public const DEFAULT_TENANT_USER_PERMISSIONS = [
        'portal.view_home',
        'portal.view_calls',
        'portal.view_messages',
        'portal.view_recordings',
    ];

    public const ROLE_CLIENT_ADMIN = 'client_admin';

    public const ROLE_CLIENT_USER = 'client_user';

    public function __construct(
        protected ClientPermissionGatekeeper $gatekeeper,
        protected ChannelTriggerSeeder $channelTriggerSeeder,
    ) {}

    /**
     * Provision a freshly created $team. Call this from CreateTeam actions,
     * from the ClientResource create flow, from tests, or from seeders.
     *
     * If $initialTenantUser is provided, they're assigned BOTH `client_admin`
     * and `client_user` roles and stamped as admin on the team_user pivot.
     * The assumption is the first user provisioned is the client's owner,
     * and every client needs at least one admin who can delegate access.
     */
    public function provision(Team $team, ?User $initialTenantUser = null): void
    {
        DB::transaction(function () use ($team, $initialTenantUser) {
            $this->seedDefaultAllowList($team);
            $this->createTenantUserRole($team);
            $this->createTenantAdminRole($team);
            $this->seedDefaultDirectoryFields($team);
            $this->seedDefaultEmailQueue($team);
            $this->channelTriggerSeeder->ensureBootstrap($team);

            if ($initialTenantUser) {
                $this->assignInitialAdmin($team, $initialTenantUser);
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Default allow-list for a new client: portal read permissions only.
     * The platform operator can widen this via ClientResource → Permission Ceiling.
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
            DB::table('client_permission_grants')->updateOrInsert(
                ['team_id' => $team->id, 'permission_id' => $id],
                ['granted_at' => $now, 'created_at' => $now, 'updated_at' => $now],
            );
        }
    }

    /**
     * Create the per-client `client_user` role — granted to every invited
     * user on acceptance. Carries only the portal.view_* permissions so
     * a fresh invitee can see their client's dashboard and call history
     * without being able to manage anything. Client admins can later
     * create narrower roles (or widen this one) via the portal.
     */
    protected function createTenantUserRole(Team $team): void
    {
        $role = Role::firstOrCreate(
            ['name' => self::ROLE_CLIENT_USER, 'guard_name' => 'web', 'team_id' => $team->id],
        );

        $registrar = app(PermissionRegistrar::class);
        $originalTeamId = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId($team->id);

        try {
            $role->syncPermissions(self::DEFAULT_TENANT_USER_PERMISSIONS);
        } finally {
            $registrar->setPermissionsTeamId($originalTeamId);
            $registrar->forgetCachedPermissions();
        }
    }

    /**
     * Create the per-client `client_admin` role — holds every permission
     * on this client's allow-list. Anyone carrying this role can manage
     * other client users' role assignments and customize the client's
     * own Spatie roles via the portal Users/Roles pages.
     *
     * Re-syncs the permission set on every call so cascaded allow-list
     * shrinks (see ClientPermissionGatekeeper::reconcileTenantRoles) and
     * widenings both track automatically on next provision call.
     */
    protected function createTenantAdminRole(Team $team): void
    {
        $role = Role::firstOrCreate(
            ['name' => self::ROLE_CLIENT_ADMIN, 'guard_name' => 'web', 'team_id' => $team->id],
        );

        // Pulls whatever the client's current allow-list resolves to so
        // a client that's been widened past the default sees the admin
        // role pick up the new perms on next provision / re-run.
        $allowed = $this->gatekeeper->allowedPermissionsFor($team);
        if ($allowed === []) {
            $allowed = self::DEFAULT_TENANT_ALLOW_LIST;
        }

        $registrar = app(PermissionRegistrar::class);
        $originalTeamId = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId($team->id);

        try {
            $role->syncPermissions($allowed);
        } finally {
            $registrar->setPermissionsTeamId($originalTeamId);
            $registrar->forgetCachedPermissions();
        }
    }

    /**
     * Starter Directory field schema — the phone book the AI uses
     * during a call. Display name, organization, department, primary
     * phone, etc. The operator can rename, reorder, add, or remove
     * these from the client's Directory Fields page.
     */
    protected function seedDefaultDirectoryFields(Team $team): void
    {
        $fields = [
            ['key' => 'name',          'label' => 'Name',          'type' => 'text',     'role' => 'name',         'required' => true,  'sort_order' => 10],
            ['key' => 'organization',  'label' => 'Organization',  'type' => 'text',     'role' => 'organization', 'required' => false, 'sort_order' => 20],
            ['key' => 'department',    'label' => 'Department',    'type' => 'text',     'role' => 'none',         'required' => false, 'sort_order' => 30],
            ['key' => 'title',         'label' => 'Title',         'type' => 'text',     'role' => 'none',         'required' => false, 'sort_order' => 40],
            ['key' => 'primary_phone', 'label' => 'Primary phone', 'type' => 'phone',    'role' => 'phone',        'required' => true,  'sort_order' => 50],
            ['key' => 'email',         'label' => 'Email',         'type' => 'email',    'role' => 'email',        'required' => false, 'sort_order' => 60],
            ['key' => 'notes',         'label' => 'Notes',         'type' => 'textarea', 'role' => 'none',         'required' => false, 'sort_order' => 70],
        ];

        foreach ($fields as $row) {
            DirectoryFieldDefinition::query()->updateOrCreate(
                ['team_id' => $team->id, 'key' => $row['key']],
                $row + ['team_id' => $team->id, 'is_active' => true],
            );
        }
    }

    /**
     * Create a "General Inbox" email queue and a default catch-all
     * routing rule so inbound email for this client has somewhere
     * to land immediately. The queue is created with no agent group
     * (open to all operators). Admins can narrow it later.
     */
    protected function seedDefaultEmailQueue(Team $team): void
    {
        $queue = EmailQueue::query()->updateOrCreate(
            ['team_id' => $team->id, 'name' => 'General Inbox'],
            [
                'description' => 'Default email queue — all inbound email for this client lands here.',
                'strategy' => EmailQueue::STRATEGY_MANUAL,
                'is_active' => true,
            ],
        );

        EmailRoutingRule::query()->updateOrCreate(
            ['team_id' => $team->id, 'name' => 'Default catch-all'],
            [
                'match_type' => EmailRoutingRule::MATCH_DEFAULT,
                'destination_type' => EmailRoutingRule::DESTINATION_QUEUE,
                'destination_id' => $queue->id,
                'priority' => 100,
                'is_active' => true,
            ],
        );
    }

    /**
     * Attach the initial user to the client as its first admin.
     *
     * Does three things in one shot:
     *   - Stamps the team_user pivot with role='admin' so the portal
     *     shows them the Users/Roles management tabs.
     *   - Grants the Spatie `client_admin` role — carries every
     *     permission on the client's allow-list.
     *   - Also grants `client_user` — the fallback role any other
     *     invited user would get. Redundant for permission purposes
     *     (admins already have the portal.view_* perms through
     *     client_admin) but keeps role assignments uniform so a later
     *     demotion is purely a matter of removing `client_admin`.
     *
     * Uses syncWithoutDetaching rather than belongsToTeam + attach:
     * Jetstream's belongsToTeam returns true for team owners even
     * without a team_user pivot row, which would leave the Users tab
     * blank for the owner. Forcing a pivot row for every client user
     * (owner included) keeps the Users list canonical.
     */
    protected function assignInitialAdmin(Team $team, User $user): void
    {
        $team->users()->syncWithoutDetaching([
            $user->id => ['role' => 'admin'],
        ]);

        $registrar = app(PermissionRegistrar::class);
        $originalTeamId = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId($team->id);

        try {
            $user->assignRole([self::ROLE_CLIENT_ADMIN, self::ROLE_CLIENT_USER]);
        } finally {
            $registrar->setPermissionsTeamId($originalTeamId);
        }
    }
}
