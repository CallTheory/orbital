<?php

declare(strict_types=1);

use App\Services\Clients\ClientProvisioner;
use Illuminate\Database\Migrations\Migration;

/**
 * Backfill the `client_admin` role and pivot flag for every existing
 * tenant. Before this migration, the only tenant-scoped role was
 * `client_user`; the new portal Users/Roles pages gate on the
 * `client_admin` role (and team_user.role='admin') to decide who
 * can manage memberships inside a tenant.
 *
 * What this does per existing tenant:
 *   - Calls ClientProvisioner::provision() again (it's idempotent —
 *     only creates missing roles/perms; doesn't re-seed fields or
 *     queues, because those updateOrCreate calls no-op).
 *   - For the tenant's owner (Team.user_id): assigns `client_admin`
 *     and flips their team_user pivot row to role='admin'.
 *
 * Non-owner users that exist on the tenant keep their `client_user`
 * role and `member` pivot value — if the tenant's owner wants to
 * promote anyone else, they do it via the portal once it ships.
 */
return new class extends Migration
{
    public function up(): void
    {
        $provisioner = app(ClientProvisioner::class);

        \App\Models\Team::query()
            ->where('personal_team', false)
            ->whereNotNull('user_id')
            ->each(function (\App\Models\Team $team) use ($provisioner) {
                $owner = \App\Models\User::find($team->user_id);
                if (! $owner) {
                    return;
                }

                // Re-provisions: creates the new client_admin role
                // with the current allow-list, assigns both roles
                // to the owner, and stamps team_user.role='admin'.
                $provisioner->provision($team, $owner);
            });
    }

    public function down(): void
    {
        // Not reversible — deleting the client_admin role cluster-
        // wide would orphan every admin assignment. If you need to
        // drop the admin concept, revert at the application layer.
        throw new \RuntimeException('seed_tenant_admin_role is not reversible.');
    }
};
