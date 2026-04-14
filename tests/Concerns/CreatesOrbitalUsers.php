<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Shared user-factory helpers for feature tests. Orbital's auth flow
 * depends on Spatie roles (both team-less platform roles and
 * team-scoped tenant roles), and factory-created users don't have
 * roles by default, so every auth-aware test needs a helper that
 * attaches the right role.
 *
 * Also provides {@see loginAs()}, which is preferred over Laravel's
 * plain `actingAs()` for feature tests that hit a Filament panel.
 * Filament's AuthenticateSession middleware compares the session's
 * password-hash cookie to the user's current hash on every request
 * and logs the user out on mismatch; `actingAs()` doesn't set up that
 * session state, so the user gets silently logged out and Filament
 * returns 403. `loginAs()` runs a real Fortify login POST so the
 * session is fully established.
 */
trait CreatesOrbitalUsers
{
    /**
     * Authenticate as a user via the real login flow so the Filament
     * panels see a fully-populated session (including the password
     * hash that AuthenticateSession compares against).
     */
    protected function loginAs(User $user): static
    {
        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        return $this;
    }

    /**
     * Build a user and attach a team-less Spatie role
     * (`super_admin`, `operator`, `supervisor`, etc).
     */
    protected function makeUserWithTeamlessRole(string $roleName): User
    {
        $user = User::factory()->create([
            'password' => bcrypt('password'),
        ]);

        $registrar = app(PermissionRegistrar::class);
        $original = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId(null);

        try {
            $role = Role::firstOrCreate([
                'name' => $roleName,
                'guard_name' => 'web',
                'team_id' => null,
            ]);
            $user->assignRole($role);
        } finally {
            $registrar->setPermissionsTeamId($original);
        }

        $registrar->forgetCachedPermissions();

        return $user->refresh();
    }

    /**
     * Build a user with a tenant-scoped role (team_id != null) so it
     * routes to the customer portal.
     *
     * Creates a real tenant team (`personal_team = false`) and attaches
     * the user via both `current_team_id` *and* the teams() pivot —
     * `User::belongsToAnyTenant()` checks pivot membership, so setting
     * current_team_id alone isn't enough to pass panel gating.
     */
    protected function makeTenantUser(): User
    {
        $user = User::factory()->withPersonalTeam()->create([
            'password' => bcrypt('password'),
        ]);

        $team = Team::factory()->create([
            'user_id' => $user->id,
            'personal_team' => false,
        ]);
        $user->teams()->syncWithoutDetaching([$team->id => ['role' => 'admin']]);
        $user->current_team_id = $team->id;
        $user->save();

        $registrar = app(PermissionRegistrar::class);
        $original = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId($team->id);

        try {
            $role = Role::firstOrCreate([
                'name' => 'tenant_user',
                'guard_name' => 'web',
                'team_id' => $team->id,
            ]);
            $user->assignRole($role);
        } finally {
            $registrar->setPermissionsTeamId($original);
        }

        $registrar->forgetCachedPermissions();

        return $user->refresh();
    }

    /**
     * Attach the given user to a real tenant team as a member. Used to
     * simulate a super-admin dog-fooding the customer portal — the new
     * portal gate requires `belongsToAnyTenant()`.
     */
    protected function attachTenantMembership(User $user): Team
    {
        $team = Team::factory()->create([
            'user_id' => $user->id,
            'personal_team' => false,
        ]);
        $user->teams()->syncWithoutDetaching([$team->id => ['role' => 'admin']]);

        return $team;
    }
}
