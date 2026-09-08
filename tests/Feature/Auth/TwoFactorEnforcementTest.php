<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\Auth\TwoFactorPolicy;
use Database\Seeders\PermissionCatalogSeeder;
use Database\Seeders\SuperAdminRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesOrbitalUsers;
use Tests\TestCase;

/**
 * Two-factor enforcement.
 *
 * The failure mode this guards against is not "someone skipped 2FA" —
 * it's shipping enforcement and locking every existing user out of a
 * production answering service at once, or trapping a blocked user on a
 * page where they can't enrol. Both are outages caused by a security
 * feature, and both are easy to cause.
 */
class TwoFactorEnforcementTest extends TestCase
{
    use CreatesOrbitalUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionCatalogSeeder::class);
        $this->seed(SuperAdminRoleSeeder::class);

        config()->set('orbital.security.two_factor_required', true);
        config()->set('orbital.security.two_factor_grace_days', 7);
    }

    public function test_the_grace_clock_starts_on_first_request_not_at_account_creation(): void
    {
        // THE test for this feature. Every existing user was created
        // before enforcement existed; counting from created_at would
        // lock out the entire installation the moment it ships.
        $user = $this->makeUserWithTeamlessRole('super_admin');
        $user->forceFill(['created_at' => now()->subYears(2)])->save();

        $this->assertNull($user->two_factor_grace_started_at);

        $this->actingAs($user)->get('/admin')->assertOk();

        $user->refresh();

        $this->assertNotNull($user->two_factor_grace_started_at);
        $this->assertTrue($user->two_factor_grace_started_at->isToday());
    }

    public function test_a_user_inside_their_grace_window_is_not_redirected(): void
    {
        $user = $this->makeUserWithTeamlessRole('super_admin');

        $this->actingAs($user)->get('/admin/clients')->assertOk();
    }

    public function test_a_user_past_their_grace_window_is_pushed_to_the_security_page(): void
    {
        $user = $this->makeUserWithTeamlessRole('super_admin');
        $user->forceFill(['two_factor_grace_started_at' => now()->subDays(8)])->save();

        $this->actingAs($user)
            ->get('/admin/clients')
            ->assertRedirect('/admin/security');
    }

    public function test_a_blocked_user_can_still_reach_the_security_page(): void
    {
        // Otherwise there is nowhere to go and no way to comply — the
        // definition of trapping someone.
        $user = $this->makeUserWithTeamlessRole('super_admin');
        $user->forceFill(['two_factor_grace_started_at' => now()->subDays(8)])->save();

        $this->actingAs($user)->get('/admin/security')->assertOk();
    }

    public function test_a_blocked_user_can_still_use_livewire(): void
    {
        // The security page IS a Livewire component. Redirecting its
        // XHR calls would break the enrolment flow we're pushing them
        // into.
        $user = $this->makeUserWithTeamlessRole('super_admin');
        $user->forceFill(['two_factor_grace_started_at' => now()->subDays(8)])->save();

        $response = $this->actingAs($user)->get('/livewire/livewire.js');

        $this->assertNotSame(302, $response->getStatusCode());
    }

    public function test_an_enrolled_user_is_never_redirected(): void
    {
        $user = $this->makeUserWithTeamlessRole('super_admin');
        $this->enrol($user);
        $user->forceFill(['two_factor_grace_started_at' => now()->subYear()])->save();

        $this->actingAs($user)->get('/admin/clients')->assertOk();
    }

    public function test_enforcement_can_be_switched_off_entirely(): void
    {
        // Turning this on can lock people out. An operator has to be
        // able to turn it back off from the environment.
        config()->set('orbital.security.two_factor_required', false);

        $user = $this->makeUserWithTeamlessRole('super_admin');
        $user->forceFill(['two_factor_grace_started_at' => now()->subYears(5)])->save();

        $this->actingAs($user)->get('/admin/clients')->assertOk();
    }

    public function test_a_client_can_set_its_own_grace_window(): void
    {
        $user = $this->makeTenantUser();
        $user->currentTeam->forceFill(['two_factor_grace_days' => 1])->save();

        $policy = app(TwoFactorPolicy::class);

        $this->assertSame(1, $policy->graceDaysFor($user->fresh()));
    }

    public function test_a_zero_day_grace_window_blocks_immediately(): void
    {
        // Deliberately allowed: a client handling medical or financial
        // calls may want exactly this.
        $user = $this->makeTenantUser();
        $user->currentTeam->forceFill(['two_factor_grace_days' => 0])->save();

        $policy = app(TwoFactorPolicy::class);

        $this->assertTrue($policy->isBlocked($user->fresh()));
    }

    public function test_the_grace_window_is_clamped_to_a_sane_range(): void
    {
        $user = $this->makeTenantUser();
        $user->currentTeam->forceFill(['two_factor_grace_days' => 9999])->save();

        $this->assertSame(30, app(TwoFactorPolicy::class)->graceDaysFor($user->fresh()));
    }

    public function test_the_banner_shows_a_countdown_and_turns_urgent_in_the_last_48_hours(): void
    {
        $user = $this->makeUserWithTeamlessRole('super_admin');
        $policy = app(TwoFactorPolicy::class);

        $user->forceFill(['two_factor_grace_started_at' => now()->subDays(1)])->save();
        $this->assertTrue($policy->shouldWarn($user));
        $this->assertFalse($policy->isUrgent($user));
        $this->assertSame(6, $policy->daysRemaining($user));

        $user->forceFill(['two_factor_grace_started_at' => now()->subDays(6)])->save();
        $this->assertTrue($policy->isUrgent($user->fresh()));
    }

    public function test_the_banner_disappears_once_enrolled(): void
    {
        $user = $this->makeUserWithTeamlessRole('super_admin');
        $this->enrol($user);

        $this->assertFalse(app(TwoFactorPolicy::class)->shouldWarn($user->fresh()));
    }

    public function test_a_half_finished_enrolment_does_not_count(): void
    {
        // A generated secret with no confirmed code protects nothing.
        $user = $this->makeUserWithTeamlessRole('super_admin');
        $user->forceFill([
            'two_factor_secret' => encrypt('secret'),
            'two_factor_confirmed_at' => null,
        ])->save();

        $this->assertFalse(app(TwoFactorPolicy::class)->isEnrolled($user->fresh()));
    }

    private function enrol(User $user): void
    {
        $user->forceFill([
            'two_factor_secret' => encrypt('secret'),
            'two_factor_confirmed_at' => now(),
        ])->save();
    }
}
