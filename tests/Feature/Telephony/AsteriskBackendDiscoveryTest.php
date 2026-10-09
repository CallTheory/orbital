<?php

declare(strict_types=1);

namespace Tests\Feature\Telephony;

use App\Models\AsteriskBackend;
use App\Services\Telephony\AsteriskBackendDiscovery;
use App\Services\Telephony\AsteriskPeerResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Kubernetes: the AsteriskBackend registry follows the Asterisk pods.
 */
class AsteriskBackendDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    /** @param array<string, string> $pods */
    private function discovery(array $pods): AsteriskBackendDiscovery
    {
        $resolver = new class($pods) extends AsteriskPeerResolver
        {
            /** @param array<string, string> $pods */
            public function __construct(private array $pods) {}

            public function pods(): array
            {
                return $this->pods;
            }
        };

        return new AsteriskBackendDiscovery($resolver);
    }

    public function test_creates_backends_keyed_by_node_ip_and_named_by_pod(): void
    {
        $result = $this->discovery([
            'orbital-orbital-asterisk-0' => '10.42.0.5',
            'orbital-orbital-asterisk-1' => '10.42.0.9',
        ])->sync();

        $this->assertSame(['discovered' => 2, 'deactivated' => 0], $result);
        $backend = AsteriskBackend::query()->where('hostname', '10.42.0.5')->firstOrFail();
        $this->assertSame('orbital-orbital-asterisk-0', $backend->nodeName());
        $this->assertSame('10.42.0.5', $backend->amiHost());
        $this->assertSame('sip:10.42.0.5:5060', $backend->sipUri());
        $this->assertTrue($backend->is_active);
    }

    public function test_deactivates_backends_no_longer_discovered_and_keeps_their_drain(): void
    {
        AsteriskBackend::query()->create([
            'hostname' => 'asterisk-1', 'sip_port' => 5060, 'ami_port' => 5038, 'is_active' => true,
        ]);
        AsteriskBackend::query()->create([
            'hostname' => '10.42.0.7', 'sip_port' => 5060, 'ami_port' => 5038,
            'is_active' => true, 'dispatch_state' => 'drain',
        ]);

        $result = $this->discovery(['orbital-orbital-asterisk-0' => '10.42.0.5'])->sync();

        $this->assertSame(2, $result['deactivated']);
        $this->assertFalse(AsteriskBackend::query()->where('hostname', 'asterisk-1')->value('is_active'));
        $moved = AsteriskBackend::query()->where('hostname', '10.42.0.7')->firstOrFail();
        $this->assertFalse($moved->is_active);
        $this->assertSame('drain', $moved->dispatch_state);
    }

    public function test_keeps_registry_untouched_when_nothing_is_discovered(): void
    {
        AsteriskBackend::query()->create([
            'hostname' => '10.42.0.5', 'sip_port' => 5060, 'ami_port' => 5038, 'is_active' => true,
        ]);

        $this->assertSame(['discovered' => 0, 'deactivated' => 0], $this->discovery([])->sync());
        $this->assertTrue(AsteriskBackend::query()->where('hostname', '10.42.0.5')->value('is_active'));
    }

    public function test_resolver_finds_no_pods_without_statefulset_config(): void
    {
        config([
            'telephony.kamailio.asterisk_discovery_host' => 'svc',
            'telephony.kamailio.asterisk_statefulset' => '',
            'telephony.kamailio.asterisk_replicas' => 2,
        ]);

        $this->assertSame([], (new AsteriskPeerResolver)->pods());
    }

    public function test_command_is_a_noop_without_discovery(): void
    {
        config(['telephony.kamailio.asterisk_discovery_host' => '']);

        $this->artisan('orbital:sync-asterisk-backends')
            ->expectsOutputToContain('nothing to do')
            ->assertSuccessful();
    }
}
