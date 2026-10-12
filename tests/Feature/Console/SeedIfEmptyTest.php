<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Database\Seeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Stands in for DatabaseSeeder so the test doesn't reach external services. */
class MarkerSeeder extends Seeder
{
    public static int $runs = 0;

    public function run(): void
    {
        self::$runs++;
        Role::findOrCreate('super_admin', 'web');
    }
}

/**
 * orbital:seed-if-empty — what the chart's migration job runs so a
 * failed first install still ends up seeded.
 */
class SeedIfEmptyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        MarkerSeeder::$runs = 0;
    }

    public function test_seeds_a_database_without_roles(): void
    {
        DB::table(config('permission.table_names.roles'))->delete();

        $this->artisan('orbital:seed-if-empty', ['--class' => MarkerSeeder::class])
            ->expectsOutputToContain('seeding reference data')
            ->assertSuccessful();

        $this->assertSame(1, MarkerSeeder::$runs);
        $this->assertTrue(Role::where('name', 'super_admin')->exists());
    }

    public function test_leaves_a_seeded_database_alone(): void
    {
        Role::findOrCreate('super_admin', 'web');

        $this->artisan('orbital:seed-if-empty', ['--class' => MarkerSeeder::class])
            ->expectsOutputToContain('already seeded')
            ->assertSuccessful();

        $this->assertSame(0, MarkerSeeder::$runs);
    }

    public function test_running_twice_seeds_once(): void
    {
        DB::table(config('permission.table_names.roles'))->delete();

        $this->artisan('orbital:seed-if-empty', ['--class' => MarkerSeeder::class])->assertSuccessful();
        $this->artisan('orbital:seed-if-empty', ['--class' => MarkerSeeder::class])->assertSuccessful();

        $this->assertSame(1, MarkerSeeder::$runs);
    }
}
