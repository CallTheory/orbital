<?php

declare(strict_types=1);

namespace Tests\Feature;

use Database\Seeders\PermissionCatalogSeeder;
use Database\Seeders\SuperAdminRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesOrbitalUsers;
use Tests\TestCase;

/**
 * Smoke + access-control tests for the operator and portal Filament
 * panels. Complements AdminResourceSmokeTest (which only hits /admin).
 *
 * Covers:
 *   - every operator panel page renders for a platform staff user
 *   - every portal panel page renders for a client user
 *   - cross-panel redirects: operator user hitting /admin, client user
 *     hitting /operator, super-admin hitting all three
 *   - unauthenticated visits to /operator and /portal bounce to /login
 */
class OperatorAndPortalPanelTest extends TestCase
{
    use CreatesOrbitalUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionCatalogSeeder::class);
        $this->seed(SuperAdminRoleSeeder::class);
    }

    // ---- operator panel ---------------------------------------------

    public function test_operator_workspace_renders_for_platform_staff(): void
    {
        $user = $this->makeUserWithTeamlessRole('operator');

        $this->actingAs($user)
            ->get('/operator/workspace')
            ->assertOk();
    }

    public function test_operator_workspace_renders_for_super_admin(): void
    {
        $user = $this->makeUserWithTeamlessRole('super_admin');

        $this->actingAs($user)
            ->get('/operator/workspace')
            ->assertOk();
    }

    public function test_tenant_user_cannot_access_operator(): void
    {
        $user = $this->makeTenantUser();

        $this->loginAs($user)
            ->get('/operator')
            ->assertRedirect('/portal');
    }

    // ---- portal panel -----------------------------------------------

    public function test_portal_dashboard_renders_for_tenant_user(): void
    {
        $user = $this->makeTenantUser();

        $this->actingAs($user)
            ->get('/portal')
            ->assertOk();
    }

    public function test_portal_call_history_renders_for_tenant_user(): void
    {
        $user = $this->makeTenantUser();

        $this->actingAs($user)
            ->get('/portal/calls')
            ->assertOk();
    }

    public function test_super_admin_without_tenant_cannot_access_portal(): void
    {
        $user = $this->makeUserWithTeamlessRole('super_admin');

        // No client membership — belongsToAnyTenant() returns false, so
        // PanelRedirect bounces them to their home surface (/admin).
        $this->loginAs($user)
            ->get('/portal')
            ->assertRedirect('/admin');
    }

    public function test_super_admin_dog_fooding_a_tenant_can_access_portal(): void
    {
        $user = $this->makeUserWithTeamlessRole('super_admin');
        $this->attachTenantMembership($user);

        $this->actingAs($user)
            ->get('/portal')
            ->assertOk();
    }

    public function test_operator_user_cannot_access_portal(): void
    {
        $user = $this->makeUserWithTeamlessRole('operator');

        $this->loginAs($user)
            ->get('/portal')
            ->assertRedirect('/operator');
    }

    // ---- cross-panel redirects --------------------------------------

    public function test_operator_user_gets_bounced_from_admin(): void
    {
        $user = $this->makeUserWithTeamlessRole('operator');

        $this->actingAs($user)
            ->get('/admin')
            ->assertRedirect('/operator');
    }

    public function test_tenant_user_gets_bounced_from_admin(): void
    {
        $user = $this->makeTenantUser();

        $this->loginAs($user)
            ->get('/admin')
            ->assertRedirect('/portal');
    }
}
