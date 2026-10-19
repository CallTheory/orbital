<?php

declare(strict_types=1);

namespace Tests\Feature\Telephony;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * orbital:sync-livekit-sip — both ends of the Asterisk ↔ LiveKit path.
 */
class LiveKitSipSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'telephony.livekit.mode' => 'local',
            'telephony.livekit.local.url' => 'ws://livekit.test:7880',
            'telephony.livekit.local.api_key' => 'key',
            'telephony.livekit.local.api_secret' => str_repeat('s', 32),
            'telephony.livekit.sip.host' => 'orbital-orbital-livekit-sip',
            'telephony.livekit.sip.port' => 5069,
        ]);
    }

    public function test_writes_asterisk_livekit_endpoint_and_anonymous_context(): void
    {
        config(['telephony.anonymous_context' => 'from-trunk']);
        Http::fake(['*' => Http::response(['items' => []])]);

        $this->artisan('orbital:sync-livekit-sip')->assertSuccessful();

        $endpoint = DB::table('ps_endpoints')->where('id', 'livekit')->first();
        $this->assertSame('from-livekit', $endpoint->context);
        $this->assertSame('livekit', $endpoint->aors);
        $this->assertSame('sip:orbital-orbital-livekit-sip:5069', DB::table('ps_aors')->where('id', 'livekit')->value('contact'));
        $this->assertSame('from-trunk', DB::table('ps_endpoints')->where('id', 'anonymous')->value('context'));
    }

    public function test_dispatch_rule_names_the_agent_and_uses_http_for_the_api(): void
    {
        Http::fake(['*' => Http::response(['items' => []])]);

        $this->artisan('orbital:sync-livekit-sip')->assertSuccessful();

        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'http://livekit.test:7880/twirp/'));
        Http::assertSent(function (Request $r) {
            if (! str_ends_with($r->url(), '/CreateSIPDispatchRule')) {
                return false;
            }

            return $r['rule']['dispatchRuleCallee'] === ['roomPrefix' => 'agent-', 'pin' => '', 'randomize' => true]
                && $r['roomConfig']['agents'][0]['agentName'] === 'orbital-agent';
        });
    }

    public function test_asterisk_rows_land_even_when_livekit_is_down(): void
    {
        Http::fake(['*' => Http::response('down', 503)]);

        $this->artisan('orbital:sync-livekit-sip')->assertFailed();

        $this->assertTrue(DB::table('ps_endpoints')->where('id', 'livekit')->exists());
    }

    public function test_list_calls_send_a_json_object(): void
    {
        Http::fake(['*' => Http::response(['items' => []])]);

        $this->artisan('orbital:sync-livekit-sip')->assertSuccessful();

        // Twirp rejects `[]` as malformed, which made every list look empty.
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/ListSIPInboundTrunk') && $r->body() === '{}');
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/ListSIPDispatchRule') && $r->body() === '{}');
    }

    public function test_resync_replaces_the_rule_and_keeps_one_trunk(): void
    {
        // Shapes as LiveKit returns them (snake_case ids).
        Http::fake([
            '*/ListSIPDispatchRule' => Http::response(['items' => [['sip_dispatch_rule_id' => 'SDR_old', 'name' => 'orbital-ai-agents']]]),
            '*/ListSIPInboundTrunk' => Http::response(['items' => [
                ['sip_trunk_id' => 'ST_keep', 'name' => 'Asterisk'],
                ['sip_trunk_id' => 'ST_dup', 'name' => 'Asterisk'],
            ]]),
            '*' => Http::response([]),
        ]);

        $this->artisan('orbital:sync-livekit-sip')->assertSuccessful();

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/DeleteSIPDispatchRule') && $r['sip_dispatch_rule_id'] === 'SDR_old');
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/DeleteSIPTrunk') && $r['sip_trunk_id'] === 'ST_dup');
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/DeleteSIPTrunk') && $r['sip_trunk_id'] === 'ST_keep');
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/CreateSIPInboundTrunk'));
    }

    public function test_a_failed_list_does_not_create_a_trunk(): void
    {
        Http::fake([
            '*/ListSIPDispatchRule' => Http::response(['items' => []]),
            '*/ListSIPInboundTrunk' => Http::response(['code' => 'malformed'], 400),
            '*' => Http::response([]),
        ]);

        $this->artisan('orbital:sync-livekit-sip')->assertFailed();

        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/CreateSIPInboundTrunk'));
    }
}
