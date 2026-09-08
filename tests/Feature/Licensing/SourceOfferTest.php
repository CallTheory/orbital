<?php

declare(strict_types=1);

namespace Tests\Feature\Licensing;

use App\Services\Licensing\SupportSubscription;
use App\Support\Release;
use Database\Seeders\PermissionCatalogSeeder;
use Database\Seeders\SuperAdminRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesOrbitalUsers;
use Tests\TestCase;

/**
 * Orbital is AGPL-3.0 and network-accessed, so section 13 obliges it to
 * offer its users the corresponding source. These tests pin the surfaces
 * that discharge that obligation, because they are exactly the kind of
 * thing that gets quietly broken by an unrelated refactor and nobody
 * notices until it is a compliance problem rather than a bug.
 *
 * They also pin the promise made in LICENSING.md: no support key, an
 * expired key, or a garbage key must leave the product behaving
 * identically. Nothing here should ever need loosening — if a change
 * makes one of these fail, the change is wrong.
 */
class SourceOfferTest extends TestCase
{
    use CreatesOrbitalUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionCatalogSeeder::class);
        $this->seed(SuperAdminRoleSeeder::class);
    }

    public function test_source_route_is_public_and_redirects_to_the_configured_repository(): void
    {
        config()->set('orbital.source_url', 'https://example.test/my-orbital-fork');
        config()->set('orbital.commit', null);

        // No authentication: the obligation runs to everyone using the
        // software over the network, so this must not be gated.
        $this->get('/source')
            ->assertRedirect('https://example.test/my-orbital-fork');
    }

    public function test_source_route_deep_links_to_the_running_commit(): void
    {
        config()->set('orbital.source_url', 'https://example.test/my-orbital-fork');
        config()->set('orbital.commit', 'abc123def456abc123def456abc123def456abcd');

        $this->get('/source')
            ->assertRedirect('https://example.test/my-orbital-fork/tree/abc123def456abc123def456abc123def456abcd');
    }

    public function test_version_endpoint_reports_license_and_source(): void
    {
        config()->set('orbital.version', '9.9.9');
        config()->set('orbital.source_url', 'https://example.test/my-orbital-fork');

        $this->getJson('/api/version')
            ->assertOk()
            ->assertJsonPath('name', 'Orbital')
            ->assertJsonPath('version', '9.9.9')
            ->assertJsonPath('license', 'AGPL-3.0-only')
            ->assertJsonPath('source_url', 'https://example.test/my-orbital-fork');
    }

    public function test_about_page_renders_for_platform_staff(): void
    {
        $admin = $this->makeUserWithTeamlessRole('super_admin');

        $this->actingAs($admin)
            ->get('/admin/about')
            ->assertOk()
            ->assertSee('AGPL-3.0-only')
            // The panel FOOTER carries the same offer, and it's the half
            // that reaches users who never open this page. Asserted on
            // all three panels — it's exactly the kind of thing an
            // unrelated layout refactor drops without anyone noticing.
            ->assertSee(route('source'));
    }

    public function test_about_page_is_registered_on_the_operator_panel(): void
    {
        // Section 13 is about users, not administrators. Operators are
        // bounced out of /admin by PanelRedirect, so the About page is
        // registered on their own panel rather than being reachable only
        // to whoever holds super-admin.
        $operator = $this->makeUserWithTeamlessRole('operator');

        $this->actingAs($operator)
            ->get('/operator/about')
            ->assertOk()
            ->assertSee('AGPL-3.0-only')
            ->assertSee(route('source'));
    }

    public function test_about_page_is_registered_on_the_client_portal(): void
    {
        // The portal is the surface where this matters most: a client
        // user interacting with a modified Orbital over the network has
        // no other way to find out what they are using.
        $user = $this->makeTenantUser();

        $this->actingAs($user)
            ->get('/portal/about')
            ->assertOk()
            ->assertSee('AGPL-3.0-only')
            ->assertSee(route('source'));
    }

    public function test_release_helper_falls_back_when_source_url_is_blank(): void
    {
        // A blank ORBITAL_SOURCE_URL must not produce a dead link — an
        // empty offer is worse than an upstream one.
        config()->set('orbital.source_url', '');

        $this->assertNotSame('', Release::sourceUrl());
        $this->assertStringStartsWith('http', Release::sourceUrl());
    }

    public function test_no_support_key_means_no_subscription_and_no_errors(): void
    {
        config()->set('orbital.support.public_key', null);

        $support = app(SupportSubscription::class);

        $this->assertFalse($support->isActive());
        $this->assertFalse($support->isExpired());
        $this->assertNull($support->tier());
        $this->assertSame('None — community support', $support->statusLabel());
    }

    public function test_a_garbage_support_key_is_rejected_without_throwing(): void
    {
        config()->set('orbital.support.public_key', base64_encode(random_bytes(SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES)));

        $support = app(SupportSubscription::class);

        $this->assertNotNull($support->store('not-a-real-key'));
        $this->assertFalse($support->isActive());
    }

    public function test_a_validly_signed_key_activates_support_surfaces_only(): void
    {
        [$publicKey, $secretKey] = $this->issuerKeypair();
        config()->set('orbital.support.public_key', base64_encode($publicKey));

        $key = $this->signKey($secretKey, [
            'tier' => 'priority',
            'licensee' => 'Example Answering Co.',
            'expires_at' => now()->addYear()->toIso8601String(),
        ]);

        $support = app(SupportSubscription::class);
        $this->assertNull($support->store($key));

        $this->assertTrue($support->isActive());
        $this->assertSame('priority', $support->tier());
        $this->assertSame('Example Answering Co.', $support->licensee());
    }

    public function test_an_expired_key_is_distinguished_from_no_key(): void
    {
        [$publicKey, $secretKey] = $this->issuerKeypair();
        config()->set('orbital.support.public_key', base64_encode($publicKey));

        $key = $this->signKey($secretKey, [
            'tier' => 'standard',
            'expires_at' => now()->subDay()->toIso8601String(),
        ]);

        $support = app(SupportSubscription::class);
        $support->store($key);

        $this->assertFalse($support->isActive());
        $this->assertTrue($support->isExpired());
        $this->assertNull($support->tier());
    }

    public function test_a_key_signed_by_the_wrong_issuer_is_rejected(): void
    {
        [$publicKey] = $this->issuerKeypair();
        [, $attackerSecret] = $this->issuerKeypair();

        config()->set('orbital.support.public_key', base64_encode($publicKey));

        $forged = $this->signKey($attackerSecret, [
            'tier' => 'priority',
            'expires_at' => now()->addYear()->toIso8601String(),
        ]);

        $support = app(SupportSubscription::class);

        $this->assertNotNull($support->store($forged));
        $this->assertFalse($support->isActive());
    }

    /**
     * The load-bearing test for the promise in LICENSING.md: whatever the
     * subscription state, the application behaves the same. If Orbital
     * ever grows a paywall, this is the test that should have stopped it.
     */
    public function test_support_state_does_not_change_product_behaviour(): void
    {
        [$publicKey, $secretKey] = $this->issuerKeypair();
        config()->set('orbital.support.public_key', base64_encode($publicKey));

        $admin = $this->makeUserWithTeamlessRole('super_admin');

        $unsubscribed = $this->actingAs($admin)->get('/admin/clients');
        $unsubscribed->assertOk();

        app(SupportSubscription::class)->store($this->signKey($secretKey, [
            'tier' => 'priority',
            'expires_at' => now()->addYear()->toIso8601String(),
        ]));

        $subscribed = $this->actingAs($admin)->get('/admin/clients');
        $subscribed->assertOk();

        $this->assertSame(
            $unsubscribed->getStatusCode(),
            $subscribed->getStatusCode(),
        );
    }

    /**
     * @return array{0: string, 1: string} [publicKey, secretKey] raw bytes
     */
    private function issuerKeypair(): array
    {
        $pair = sodium_crypto_sign_keypair();

        return [
            sodium_crypto_sign_publickey($pair),
            sodium_crypto_sign_secretkey($pair),
        ];
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function signKey(string $secretKey, array $claims): string
    {
        $payload = (string) json_encode($claims);
        $signature = sodium_crypto_sign_detached($payload, $secretKey);

        return $this->base64Url($payload).'.'.$this->base64Url($signature);
    }

    private function base64Url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
