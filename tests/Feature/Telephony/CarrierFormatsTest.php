<?php

declare(strict_types=1);

namespace Tests\Feature\Telephony;

use App\Jobs\RegenerateTelephonyConfig;
use App\Models\ClientDid;
use App\Models\SipTrunk;
use App\Models\Team;
use App\Models\User;
use App\Services\Telephony\AsteriskConfigService;
use App\Services\Telephony\Realtime\OutboundTrunkAuditor;
use App\Services\Telephony\Realtime\RealtimeValue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * What the first real Bandwidth call on Dallas showed: the carrier sends
 * the bare national number, and Asterisk's realtime driver splits stored
 * values on ";".
 */
class CarrierFormatsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('asterisk-config');
        Bus::fake([RegenerateTelephonyConfig::class]);
    }

    public function test_an_e164_did_also_answers_to_the_forms_carriers_send(): void
    {
        $team = Team::forceCreate(['user_id' => User::factory()->create()->id, 'name' => 'Acme', 'personal_team' => false]);
        ClientDid::create([
            'team_id' => $team->id, 'number' => '+18325790579', 'is_active' => true,
            'destination_type' => 'extension', 'destination_id' => '1001',
        ]);

        $dialplan = app(AsteriskConfigService::class)->generateFromTrunkDispatcher();

        $this->assertStringContainsString('exten => +18325790579,1,NoOp', $dialplan);
        $this->assertStringContainsString('exten => 18325790579,1,Goto(from-trunk,+18325790579,1)', $dialplan);
        $this->assertStringContainsString('exten => 8325790579,1,Goto(from-trunk,+18325790579,1)', $dialplan);
        // The aliases come before the catch-all hangup.
        $this->assertLessThan(strpos($dialplan, 'exten => _X.,1'), strpos($dialplan, 'exten => 8325790579,1'));
    }

    public function test_trunk_outbound_proxy_keeps_its_lr_parameter_for_asterisk(): void
    {
        config(['telephony.outbound_proxy' => 'sip:orbital-orbital-sip-edge.orbital.svc.cluster.local;lr']);

        $trunk = SipTrunk::create([
            'name' => 'Bandwidth', 'host' => '67.231.4.70', 'port' => 5060, 'transport' => 'udp',
            'username' => 'orbital', 'password' => 'pa;ss', 'is_active' => true,
        ]);
        $id = 'trunk_'.$trunk->id;

        $this->assertSame('sip:orbital-orbital-sip-edge.orbital.svc.cluster.local^3Blr', DB::table('ps_endpoints')->where('id', $id)->value('outbound_proxy'));
        $this->assertSame('pa^3Bss', DB::table('ps_auths')->where('id', $id)->value('password'));
        // The auditor reads the decoded value, so this isn't a leak.
        $this->assertSame([], app(OutboundTrunkAuditor::class)->findLeaks());
    }

    public function test_migration_encodes_rows_written_before_the_fix_once(): void
    {
        DB::table('ps_endpoints')->insert(['id' => 'trunk_9', 'outbound_proxy' => 'sip:edge;lr']);
        DB::table('ps_auths')->insert(['id' => 'trunk_9', 'password' => 'a^b;c']);

        $migration = require database_path('migrations/2026_10_19_100000_encode_realtime_semicolons.php');
        $migration->up();
        $migration->up();

        $this->assertSame('sip:edge^3Blr', DB::table('ps_endpoints')->where('id', 'trunk_9')->value('outbound_proxy'));
        $this->assertSame('a^5Eb^3Bc', DB::table('ps_auths')->where('id', 'trunk_9')->value('password'));
        $this->assertSame('a^b;c', RealtimeValue::decode('a^5Eb^3Bc'));
    }

    public function test_writing_a_new_clients_dialplan_also_includes_it_in_the_index(): void
    {
        $team = Team::forceCreate(['user_id' => User::factory()->create()->id, 'name' => 'New Client', 'personal_team' => false]);

        app(AsteriskConfigService::class)->writeDialplanForTenant($team->id);

        $index = Storage::disk('asterisk-config')->get('dialplan_index.conf');
        $this->assertStringContainsString("#tryinclude \"generated/clients/{$team->id}-dialplan.conf\"", $index);
    }
}
