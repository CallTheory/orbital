<?php

declare(strict_types=1);

namespace Tests\Feature\Health;

use App\Services\Health\HealthCheck;
use App\Services\Health\SystemHealthService;
use Database\Seeders\RtpengineNodeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * rtpengine health on the SIP edge VMs, asked through each edge's
 * Kamailio JSON-RPC rather than NG.
 */
class EdgeRtpengineHealthTest extends TestCase
{
    use RefreshDatabase;

    private const EDGE_1 = 'http://10.42.0.7:8090/jsonrpc';

    private const EDGE_2 = 'http://10.42.0.8:8090/jsonrpc';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'telephony.rtpengine_via_kamailio' => true,
            'telephony.kamailio.jsonrpc_urls' => [self::EDGE_1, self::EDGE_2],
        ]);
    }

    private function check(): HealthCheck
    {
        $method = new \ReflectionMethod(SystemHealthService::class, 'checkRtpengine');

        return $method->invoke(app(SystemHealthService::class));
    }

    private static function pong(): array
    {
        return ['jsonrpc' => '2.0', 'result' => ['url' => 'all', 'status' => 'success'], 'id' => 1];
    }

    public function test_ok_when_every_edge_pings_its_rtpengine(): void
    {
        Http::fake(['*' => Http::response(self::pong())]);

        $check = $this->check();

        $this->assertSame(HealthCheck::OK, $check->status);
        $this->assertSame('2', $check->metrics['Responding']);
        Http::assertSent(fn (Request $r) => $r->url() === self::EDGE_1
            && $r['method'] === 'rtpengine.ping'
            && $r['params'] === ['all']);
    }

    public function test_warns_and_names_the_edge_that_is_down(): void
    {
        Http::fake([
            '10.42.0.7:8090/*' => Http::response(self::pong()),
            '10.42.0.8:8090/*' => Http::response(['jsonrpc' => '2.0', 'error' => ['code' => 500, 'message' => 'No rtpengine responded'], 'id' => 1]),
        ]);

        $check = $this->check();

        $this->assertSame(HealthCheck::WARN, $check->status);
        $this->assertStringContainsString('10.42.0.8', $check->message);
    }

    public function test_down_when_no_edge_answers(): void
    {
        Http::fake(['*' => Http::response('', 500)]);

        $this->assertSame(HealthCheck::DOWN, $this->check()->status);
    }

    public function test_ignores_the_ng_node_registry(): void
    {
        // Seeded compose hostnames that don't resolve on Kubernetes.
        $this->seed(RtpengineNodeSeeder::class);
        Http::fake(['*' => Http::response(self::pong())]);

        $this->assertSame(HealthCheck::OK, $this->check()->status);
    }
}
