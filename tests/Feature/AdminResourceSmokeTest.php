<?php

declare(strict_types=1);

namespace Tests\Feature;

use Database\Seeders\PermissionCatalogSeeder;
use Database\Seeders\SuperAdminRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesOrbitalUsers;
use Tests\TestCase;

/**
 * Blanket smoke test — renders the index page of every Filament admin
 * resource as a super-admin. Catches runtime errors from typos in
 * Filament class references, wrong namespace paths, deleted enums,
 * and similar mistakes that only surface when someone actually clicks
 * the page. The `TextColumnSize` regression is what motivated this —
 * we want a single test that fires every admin URL on every CI run.
 *
 * Add a new entry every time we land a new admin resource. Keep this
 * test fast: no fixture seeding beyond auth scaffolding; every admin
 * resource should render cleanly on an empty table.
 */
class AdminResourceSmokeTest extends TestCase
{
    use CreatesOrbitalUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionCatalogSeeder::class);
        $this->seed(SuperAdminRoleSeeder::class);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function adminUrls(): array
    {
        return [
            // Dashboard
            'admin root' => ['/admin'],
            // Platform group
            'roles' => ['/admin/roles'],
            'groups' => ['/admin/groups'],
            'clients' => ['/admin/clients'],
            'staff' => ['/admin/staff'],
            'platform settings' => ['/admin/platform'],
            'system setup' => ['/admin/setup'],
            // Telephony group
            'sip-trunks' => ['/admin/sip-trunks'],
            'extensions' => ['/admin/extensions'],
            'hold-music' => ['/admin/hold-music'],
            'telephony settings' => ['/admin/telephony'],
            // Conversational AI group
            'personalities' => ['/admin/personalities'],
            'intake-goals' => ['/admin/intake-goals'],
            'intake-flows' => ['/admin/intake-flows'],
            'voices' => ['/admin/voices'],
            'knowledge-stores' => ['/admin/knowledge-stores'],
            // Monitor group
            'call-logs' => ['/admin/call-logs'],
        ];
    }

    #[DataProvider('adminUrls')]
    public function test_admin_resource_page_renders(string $url): void
    {
        $admin = $this->makeUserWithTeamlessRole('super_admin');

        $this->actingAs($admin)
            ->get($url)
            ->assertOk();
    }
}
