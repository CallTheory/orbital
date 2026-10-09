<?php

declare(strict_types=1);

namespace Tests\Feature\Telephony;

use App\Services\Telephony\AsteriskPeerResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * /api/edge/dispatcher — the dispatcher list the SIP edge VMs poll.
 */
class EdgeDispatcherTest extends TestCase
{
    use RefreshDatabase;

    /** @param list<string> $ips */
    private function fakePeers(array $ips): void
    {
        $this->app->instance(AsteriskPeerResolver::class, new class($ips) extends AsteriskPeerResolver
        {
            /** @param list<string> $ips */
            public function __construct(private array $ips) {}

            public function addresses(): array
            {
                return $this->ips;
            }
        });
    }

    public function test_lists_ready_asterisks_for_a_valid_edge_token(): void
    {
        config(['tls.webhook_token' => 'edge-secret']);
        $this->fakePeers(['10.42.0.5', '10.42.0.9']);

        $response = $this->withToken('edge-secret')->get('/api/edge/dispatcher');

        $response->assertOk();
        $this->assertStringStartsWith('text/plain', (string) $response->headers->get('Content-Type'));
        $lines = array_values(array_filter(
            explode("\n", $response->getContent()),
            fn (string $l) => $l !== '' && ! str_starts_with($l, '#'),
        ));
        $this->assertSame([
            '1 sip:10.42.0.5:5060 0 0 weight=100',
            '1 sip:10.42.0.9:5060 0 0 weight=100',
        ], $lines);
    }

    public function test_rejects_missing_or_wrong_token(): void
    {
        config(['tls.webhook_token' => 'edge-secret']);
        $this->fakePeers(['10.42.0.5']);

        $this->get('/api/edge/dispatcher')->assertUnauthorized();
        $this->withToken('nope')->get('/api/edge/dispatcher')->assertUnauthorized();
    }

    public function test_rejects_everything_when_no_token_is_configured(): void
    {
        config(['tls.webhook_token' => '']);
        $this->fakePeers(['10.42.0.5']);

        $this->withToken('')->get('/api/edge/dispatcher')->assertUnauthorized();
    }

    public function test_503_when_no_asterisk_is_ready_so_the_edge_keeps_its_list(): void
    {
        config(['tls.webhook_token' => 'edge-secret']);
        $this->fakePeers([]);

        $this->withToken('edge-secret')->get('/api/edge/dispatcher')->assertStatus(503);
    }

    public function test_resolver_returns_nothing_without_a_discovery_host(): void
    {
        config(['telephony.kamailio.asterisk_discovery_host' => '']);

        $this->assertSame([], (new AsteriskPeerResolver)->addresses());
    }

    public function test_resolver_sorts_and_dedupes_resolved_addresses(): void
    {
        config(['telephony.kamailio.asterisk_discovery_host' => 'localhost']);

        $this->assertSame(['127.0.0.1'], (new AsteriskPeerResolver)->addresses());
    }
}
