<?php

declare(strict_types=1);

namespace Tests\Feature\Telephony;

use App\Models\AsteriskBackend;
use App\Services\HighAvailability\HAProxyStatsClient;
use App\Services\Telephony\AsteriskDrainService;
use App\Services\Telephony\AsteriskPeerResolver;
use App\Services\Telephony\KamailioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

/**
 * Draining on the SIP-edge (Kubernetes) setup: no HAProxy, and the
 * state is kept for the edges' dispatcher list.
 */
class AsteriskDrainEdgeModeTest extends TestCase
{
    use RefreshDatabase;

    private function service(bool $kamailioOk = true): AsteriskDrainService
    {
        $kamailio = Mockery::mock(KamailioService::class);
        $kamailio->shouldReceive('setBackendState')->andReturn($kamailioOk);
        $haproxy = Mockery::mock(HAProxyStatsClient::class);
        $haproxy->shouldNotReceive('disableServer', 'enableServer');

        return new AsteriskDrainService($kamailio, $haproxy);
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['telephony.kamailio.asterisk_discovery_host' => 'orbital-orbital-asterisk-headless']);
        AsteriskBackend::query()->create([
            'hostname' => '10.42.0.5', 'node_name' => 'orbital-orbital-asterisk-0',
            'sip_port' => 5060, 'ami_port' => 5038, 'is_active' => true,
        ]);
    }

    public function test_drain_skips_haproxy_and_records_state(): void
    {
        $result = $this->service()->drain('10.42.0.5');

        $this->assertSame(['kamailio' => true, 'haproxy' => true], $result);
        $this->assertSame('drain', AsteriskBackend::query()->where('hostname', '10.42.0.5')->value('dispatch_state'));
    }

    public function test_drain_finds_operators_registered_under_the_pod_name(): void
    {
        DB::table('ps_contacts')->insert([
            'id' => 'ext-100;@abc', 'uri' => 'sip:100@203.0.113.7;transport=ws',
            'endpoint' => 'ext-100', 'reg_server' => 'orbital-orbital-asterisk-0',
        ]);
        Log::spy();

        $this->service()->drain('10.42.0.5');

        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $msg, array $ctx = []) => $msg === 'asterisk-drain: affected endpoints lookup'
                && $ctx['affected_endpoints'] === ['ext-100'])
            ->once();
    }

    public function test_activate_and_disable_record_state(): void
    {
        $service = $this->service();

        $service->disable('10.42.0.5');
        $this->assertSame('disable', AsteriskBackend::query()->where('hostname', '10.42.0.5')->value('dispatch_state'));

        $service->activate('10.42.0.5');
        $this->assertSame('active', AsteriskBackend::query()->where('hostname', '10.42.0.5')->value('dispatch_state'));
    }

    public function test_edge_list_carries_drain_flags(): void
    {
        config(['tls.webhook_token' => 'edge-secret']);
        AsteriskBackend::query()->create([
            'hostname' => '10.42.0.9', 'sip_port' => 5060, 'ami_port' => 5038,
            'is_active' => true, 'dispatch_state' => 'disable',
        ]);
        $this->service()->drain('10.42.0.5');
        $this->app->instance(AsteriskPeerResolver::class, new class extends AsteriskPeerResolver
        {
            public function addresses(): array
            {
                return ['10.42.0.5', '10.42.0.9', '10.42.0.11'];
            }
        });

        $body = $this->withToken('edge-secret')->get('/api/edge/dispatcher')->assertOk()->getContent();

        $this->assertStringContainsString("1 sip:10.42.0.5:5060 12 0 weight=100\n", $body);
        $this->assertStringContainsString("1 sip:10.42.0.9:5060 4 0 weight=100\n", $body);
        $this->assertStringContainsString("1 sip:10.42.0.11:5060 0 0 weight=100\n", $body);
    }
}
