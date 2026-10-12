<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\User;
use Database\Seeders\SuperAdminRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * orbital:make-admin — the first login on a new install.
 */
class CreateAdminUserTest extends TestCase
{
    use RefreshDatabase;

    private const ARGS = ['--name' => 'Pat Admin', '--email' => 'pat@example.com', '--password' => 'a-strong-password'];

    public function test_creates_a_super_admin_on_a_seeded_database(): void
    {
        $this->seed(SuperAdminRoleSeeder::class);

        $this->artisan('orbital:make-admin', self::ARGS)->assertSuccessful();

        $user = User::where('email', 'pat@example.com')->firstOrFail();
        $this->assertNotNull($user->current_team_id);
        $this->assertTrue(DB::table('model_has_roles')->where('model_id', $user->id)->exists());
    }

    public function test_an_unseeded_database_leaves_nothing_behind_and_says_why(): void
    {
        DB::table(config('permission.table_names.roles'))->delete();

        $this->artisan('orbital:make-admin', self::ARGS)
            ->expectsOutputToContain('has not been seeded')
            ->assertFailed();

        $this->assertFalse(User::where('email', 'pat@example.com')->exists());

        // ...so once seeded, the same command just works.
        $this->seed(SuperAdminRoleSeeder::class);
        $this->artisan('orbital:make-admin', self::ARGS)->assertSuccessful();
    }
}
