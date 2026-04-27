<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\PermissionRegistrar;

class FirstSuperAdminSeeder extends Seeder
{
    /**
     * Creates the first super-admin account for the platform operator.
     *
     * Configure via env:
     *   SUPER_ADMIN_EMAIL, SUPER_ADMIN_PASSWORD, SUPER_ADMIN_NAME
     */
    public function run(): void
    {
        $email = env('SUPER_ADMIN_EMAIL');
        $password = env('SUPER_ADMIN_PASSWORD');
        $name = env('SUPER_ADMIN_NAME', config('orbital.platform_company', 'Orbital').' Admin');

        if (! $email || ! $password) {
            $this->command?->warn('Skipping FirstSuperAdminSeeder: SUPER_ADMIN_EMAIL and SUPER_ADMIN_PASSWORD not set.');

            return;
        }

        $user = User::firstOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => Hash::make($password),
                'email_verified_at' => now(),
            ],
        );

        if (! $user->currentTeam) {
            // "Platform" rather than Jetstream's "{name}'s Team" default.
            // Super-admins operate at the platform level, not inside a
            // tenant — the team exists only because BelongsToTeam needs
            // a current_team_id. "Platform" reads sensibly anywhere the
            // name surfaces (portal dashboard, tenant switcher, menus).
            $team = Team::forceCreate([
                'user_id' => $user->id,
                'name' => 'Platform',
                'personal_team' => true,
            ]);

            $user->current_team_id = $team->id;
            $user->save();

            $user->teams()->syncWithoutDetaching([$team->id => ['role' => 'admin']]);
        }

        $registrar = app(PermissionRegistrar::class);
        $originalTeamId = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId(null);

        try {
            $user->assignRole('super_admin');
        } finally {
            $registrar->setPermissionsTeamId($originalTeamId);
            $registrar->forgetCachedPermissions();
        }

        $this->command?->info("Super-admin ready: {$email}");
    }
}
