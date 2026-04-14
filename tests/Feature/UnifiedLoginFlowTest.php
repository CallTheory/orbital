<?php

declare(strict_types=1);

namespace Tests\Feature;

use Database\Seeders\PermissionCatalogSeeder;
use Database\Seeders\SuperAdminRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesOrbitalUsers;
use Tests\TestCase;

/**
 * Verifies the unified-login contract:
 *
 *   - All protected URLs funnel through a single /login page
 *   - /admin/login no longer exists
 *   - Post-auth redirects honor intended() when the user has access
 *   - Post-auth fallback is role-based (super_admin → /admin,
 *     platform staff → /operator, everyone else → /portal)
 *   - The root URL is a smart redirect, not a welcome page
 */
class UnifiedLoginFlowTest extends TestCase
{
    use CreatesOrbitalUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionCatalogSeeder::class);
        $this->seed(SuperAdminRoleSeeder::class);
    }

    public function test_admin_login_url_is_gone(): void
    {
        $this->get('/admin/login')->assertStatus(404);
    }

    public function test_unauthenticated_root_redirects_to_login(): void
    {
        $this->get('/')->assertRedirect('/login');
    }

    public function test_unauthenticated_admin_redirects_to_login(): void
    {
        $this->get('/admin')->assertRedirectContains('/login');
    }

    public function test_unauthenticated_operator_redirects_to_login(): void
    {
        $this->get('/operator')->assertRedirectContains('/login');
    }

    public function test_unauthenticated_portal_redirects_to_login(): void
    {
        $this->get('/portal')->assertRedirectContains('/login');
    }

    public function test_login_page_renders(): void
    {
        $this->get('/login')->assertOk();
    }

    public function test_super_admin_landing_goes_to_admin(): void
    {
        $user = $this->makeUserWithTeamlessRole('super_admin');

        $this->actingAs($user)
            ->get('/')
            ->assertRedirect('/admin');
    }

    public function test_operator_landing_goes_to_operator_workspace(): void
    {
        $user = $this->makeUserWithTeamlessRole('operator');

        $this->actingAs($user)
            ->get('/')
            ->assertRedirect('/operator');
    }

    public function test_tenant_user_landing_goes_to_portal(): void
    {
        $user = $this->makeTenantUser();

        $this->actingAs($user)
            ->get('/')
            ->assertRedirect('/portal');
    }

    public function test_login_post_redirects_super_admin_to_admin(): void
    {
        $user = $this->makeUserWithTeamlessRole('super_admin');

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect('/admin');
    }

    public function test_login_post_redirects_operator_to_operator(): void
    {
        $user = $this->makeUserWithTeamlessRole('operator');

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect('/operator');
    }

    public function test_login_post_redirects_tenant_user_to_portal(): void
    {
        $user = $this->makeTenantUser();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect('/portal');
    }

    public function test_login_honors_intended_url_when_permitted(): void
    {
        $user = $this->makeUserWithTeamlessRole('super_admin');

        // Deep-link to a protected page while unauthenticated — Laravel
        // stashes `url.intended` in the session.
        $this->get('/admin/intake-goals');

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect('/admin/intake-goals');
    }
}
