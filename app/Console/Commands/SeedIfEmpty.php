<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Seed the reference data (permission catalogue, roles, hold music,
 * intake goals, ...) if this database has never been seeded.
 *
 * The chart used to seed only on Helm's first install, as a post-install
 * hook — which never runs when that first install fails or times out.
 * Every later run is an upgrade, so the database stayed migrated but
 * empty and nobody could be made an admin. Keying on the data instead
 * of on the Helm event lets a later run finish the job, while a seeded
 * database (with an operator's own edits) is never touched again.
 */
class SeedIfEmpty extends Command
{
    protected $signature = 'orbital:seed-if-empty
        {--class=Database\\Seeders\\DatabaseSeeder : Seeder to run when the database is empty}';

    protected $description = 'Seed reference data unless the database already has roles';

    public function handle(): int
    {
        $roles = (string) config('permission.table_names.roles', 'roles');

        if (DB::table($roles)->exists()) {
            $this->info('Reference data already seeded; nothing to do.');

            return self::SUCCESS;
        }

        $this->info('No roles yet — seeding reference data.');

        return $this->call('db:seed', ['--class' => $this->option('class'), '--force' => true]);
    }
}
