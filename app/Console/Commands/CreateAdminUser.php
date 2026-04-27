<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Team;
use App\Models\User;
use Illuminate\Console\Command;
use Spatie\Permission\PermissionRegistrar;

class CreateAdminUser extends Command
{
    protected $signature = 'orbital:make-admin
                            {--name= : Admin user name}
                            {--email= : Admin user email}
                            {--password= : Admin user password}';

    protected $description = 'Create a platform operator super-admin user';

    public function handle(): int
    {
        $name = $this->option('name') ?? $this->ask('Name');
        $email = $this->option('email') ?? $this->ask('Email');
        $password = $this->option('password') ?? $this->secret('Password');

        if (! $name || ! $email || ! $password) {
            $this->error('Name, email, and password are all required.');

            return self::FAILURE;
        }

        if (User::where('email', $email)->exists()) {
            $this->error("A user with email {$email} already exists.");

            return self::FAILURE;
        }

        // The User model's `hashed` cast auto-hashes plain passwords on save.
        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'email_verified_at' => now(),
        ]);

        // Personal team (Jetstream convention — every user has one)
        $team = Team::forceCreate([
            'user_id' => $user->id,
            'name' => explode(' ', $name, 2)[0]."'s Team",
            'personal_team' => true,
        ]);

        $user->current_team_id = $team->id;
        $user->save();
        $user->teams()->attach($team, ['role' => 'owner']);

        // Assign the team-less super_admin role
        $registrar = app(PermissionRegistrar::class);
        $originalTeamId = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId(null);

        try {
            $user->assignRole('super_admin');
        } finally {
            $registrar->setPermissionsTeamId($originalTeamId);
            $registrar->forgetCachedPermissions();
        }

        $this->info("Super-admin created: {$name} <{$email}>");
        $this->info('Login at: '.config('app.url').'/admin');

        return self::SUCCESS;
    }
}
