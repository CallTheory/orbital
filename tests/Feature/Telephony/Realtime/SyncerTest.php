<?php

declare(strict_types=1);

namespace Tests\Feature\Telephony\Realtime;

use App\Models\AgentGroup;
use App\Models\CallQueue;
use App\Models\Extension;
use App\Models\QueueStrategyTemplate;
use App\Models\SipTrunk;
use App\Models\Team;
use App\Models\User;
use App\Services\Telephony\Realtime\EndpointSyncer;
use App\Services\Telephony\Realtime\QueueMemberSyncer;
use App\Services\Telephony\Realtime\QueueSyncer;
use App\Services\Telephony\Realtime\TrunkSyncer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 4 sync layer tests. Locks in the contract between Eloquent
 * models and the ARA tables that Asterisk reads:
 *
 *   - EndpointSyncer translates Extension into ps_endpoints +
 *     ps_auths + ps_aors with the right type-specific knobs (webrtc
 *     vs sip_phone).
 *   - TrunkSyncer translates SipTrunk into the same plus
 *     ps_endpoint_id_ips for inbound source-IP identification.
 *   - QueueSyncer writes the queue config row keyed by the
 *     prefixed asteriskName.
 *   - QueueMemberSyncer wipes + re-inserts queue_members for a
 *     given queue.
 *
 * Because the underlying tables are not Eloquent models (Asterisk
 * owns their schema), every assertion goes through the DB facade.
 */
class SyncerTest extends TestCase
{
    use RefreshDatabase;

    // ── EndpointSyncer ──────────────────────────────────────────

    public function test_endpoint_syncer_writes_ps_endpoints_auths_aors_for_a_sip_phone(): void
    {
        $team = $this->makeTeam();
        $ext = Extension::create([
            'team_id' => $team->id,
            'number' => '201',
            'label' => 'Front Desk',
            'type' => 'sip_phone',
            'sip_username' => 'frontdesk',
            'sip_password' => 'secret',
            'transport' => 'udp',
            'context' => 'internal',
            'is_active' => true,
        ]);

        // Observer fires the syncer automatically. Verify the rows.
        $endpointId = "t{$team->id}_201";

        $this->assertDatabaseHas('ps_endpoints', [
            'id' => $endpointId,
            'transport' => 'transport-udp',
            'aors' => $endpointId,
            'auth' => $endpointId,
            'context' => "tenant_{$team->id}",
            'webrtc' => 'no',
        ]);
        $this->assertDatabaseHas('ps_auths', [
            'id' => $endpointId,
            'auth_type' => 'userpass',
            'username' => 'frontdesk',
        ]);
        $this->assertDatabaseHas('ps_aors', [
            'id' => $endpointId,
            'max_contacts' => 1,
        ]);
    }

    public function test_endpoint_syncer_marks_webrtc_endpoints_with_dtls_settings(): void
    {
        $team = $this->makeTeam();
        Extension::create([
            'team_id' => $team->id,
            'number' => '300',
            'type' => 'webrtc_client',
            'sip_username' => 'wrtc',
            'sip_password' => 'secret',
            'is_active' => true,
        ]);

        $row = DB::table('ps_endpoints')->where('id', "t{$team->id}_300")->first();

        $this->assertSame('yes', $row->webrtc);
        // WebRTC endpoints intentionally leave transport unset — Asterisk
        // auto-picks from the active client contact (see EndpointSyncer).
        $this->assertNull($row->transport);
        $this->assertSame('dtls', $row->media_encryption);
        $this->assertSame('yes', $row->ice_support);
        $this->assertSame('yes', $row->rtcp_mux);

        $aor = DB::table('ps_aors')->where('id', "t{$team->id}_300")->first();
        $this->assertSame(5, $aor->max_contacts);
    }

    public function test_endpoint_syncer_deletes_rows_when_extension_is_deleted(): void
    {
        $team = $this->makeTeam();
        $ext = Extension::create([
            'team_id' => $team->id,
            'number' => '202',
            'type' => 'sip_phone',
            'sip_username' => 'a',
            'sip_password' => 'b',
            'is_active' => true,
        ]);
        $endpointId = "t{$team->id}_202";

        $this->assertDatabaseHas('ps_endpoints', ['id' => $endpointId]);
        $ext->delete();
        $this->assertDatabaseMissing('ps_endpoints', ['id' => $endpointId]);
        $this->assertDatabaseMissing('ps_auths', ['id' => $endpointId]);
        $this->assertDatabaseMissing('ps_aors', ['id' => $endpointId]);
    }

    public function test_endpoint_syncer_skips_virtual_extensions(): void
    {
        $team = $this->makeTeam();
        Extension::create([
            'team_id' => $team->id,
            'number' => '999',
            'type' => 'virtual',
            'is_active' => true,
        ]);

        $this->assertDatabaseMissing('ps_endpoints', ['id' => "t{$team->id}_999"]);
    }

    // ── TrunkSyncer ─────────────────────────────────────────────

    public function test_trunk_syncer_writes_endpoint_aor_and_identify_rows(): void
    {
        $trunk = SipTrunk::create([
            'team_id' => null,
            'name' => 'Provider',
            'host' => 'sip.provider.test',
            'port' => 5060,
            'transport' => 'udp',
            'username' => 'orbital',
            'password' => 'changeme',
            'is_active' => true,
        ]);

        $endpointId = 'trunk_'.$trunk->id;

        $this->assertDatabaseHas('ps_endpoints', [
            'id' => $endpointId,
            'transport' => 'transport-udp',
            'context' => 'from-trunk',
            'identify_by' => 'ip,username',
        ]);
        $this->assertDatabaseHas('ps_aors', [
            'id' => $endpointId,
            'contact' => 'sip:sip.provider.test:5060',
        ]);
        $this->assertDatabaseHas('ps_endpoint_id_ips', [
            'id' => $endpointId,
            'endpoint' => $endpointId,
            'match' => 'sip.provider.test',
        ]);
        $this->assertDatabaseHas('ps_auths', [
            'id' => $endpointId,
            'username' => 'orbital',
        ]);
    }

    public function test_trunk_syncer_skips_auth_when_no_username(): void
    {
        $trunk = SipTrunk::create([
            'team_id' => null,
            'name' => 'IP-only',
            'host' => 'sip.iponly.test',
            'port' => 5060,
            'transport' => 'udp',
            'username' => null,
            'is_active' => true,
        ]);

        $endpointId = 'trunk_'.$trunk->id;
        $this->assertDatabaseHas('ps_endpoints', ['id' => $endpointId]);
        $this->assertDatabaseMissing('ps_auths', ['id' => $endpointId]);
    }

    public function test_trunk_syncer_deletes_all_rows_on_delete(): void
    {
        $trunk = SipTrunk::create([
            'team_id' => null,
            'name' => 'Delete Me',
            'host' => 'sip.delete.test',
            'port' => 5060,
            'transport' => 'udp',
            'username' => 'a',
            'password' => 'b',
            'is_active' => true,
        ]);
        $endpointId = 'trunk_'.$trunk->id;

        $trunk->delete();

        $this->assertDatabaseMissing('ps_endpoints', ['id' => $endpointId]);
        $this->assertDatabaseMissing('ps_auths', ['id' => $endpointId]);
        $this->assertDatabaseMissing('ps_aors', ['id' => $endpointId]);
        $this->assertDatabaseMissing('ps_endpoint_id_ips', ['id' => $endpointId]);
    }

    // ── QueueSyncer + QueueMemberSyncer ─────────────────────────

    public function test_queue_syncer_writes_queues_row_with_prefixed_name(): void
    {
        $team = $this->makeTeam();
        $template = QueueStrategyTemplate::create([
            'name' => 'Test Strategy',
            'strategy' => 'rrmemory',
            'timeout' => 25,
            'retry' => 5,
            'wrapup_time' => 10,
        ]);
        $group = AgentGroup::create([
            'name' => 'test_group',
            'label' => 'Test Group',
            'strategy_template_id' => $template->id,
        ]);
        $queue = CallQueue::create([
            'team_id' => $team->id,
            'name' => 'Support',
            'music_on_hold' => 'default',
            'agent_group_id' => $group->id,
        ]);

        $this->assertDatabaseHas('queues', [
            'name' => "t{$team->id}_support",
            'strategy' => 'rrmemory',
            'timeout' => 25,
        ]);
    }

    public function test_queue_member_syncer_wipes_existing_rows_and_re_inserts(): void
    {
        $team = $this->makeTeam();
        $queue = CallQueue::create([
            'team_id' => $team->id,
            'name' => 'Support',
        ]);

        // Hand-insert a stale member row to confirm the syncer wipes it.
        DB::table('queue_members')->insert([
            'queue_name' => $queue->asteriskName(),
            'interface' => 'PJSIP/stale',
            'membername' => 'stale',
            'state_interface' => 'PJSIP/stale',
            'penalty' => 0,
            'paused' => 0,
        ]);

        app(QueueMemberSyncer::class)->syncForQueue($queue->fresh());

        // No agent group, so post-sync there should be zero member rows
        // for this queue (stale row gone, no replacement).
        $this->assertSame(0, DB::table('queue_members')->where('queue_name', $queue->asteriskName())->count());
    }

    public function test_queue_syncer_deletes_queue_and_members_on_delete(): void
    {
        $team = $this->makeTeam();
        $queue = CallQueue::create([
            'team_id' => $team->id,
            'name' => 'Delete Me',
        ]);
        $name = $queue->asteriskName();

        $this->assertDatabaseHas('queues', ['name' => $name]);

        $queue->delete();

        $this->assertDatabaseMissing('queues', ['name' => $name]);
        $this->assertSame(0, DB::table('queue_members')->where('queue_name', $name)->count());
    }

    // ── helpers ─────────────────────────────────────────────────

    protected function makeTeam(string $name = 'Acme'): Team
    {
        $owner = User::factory()->create();

        return Team::forceCreate([
            'user_id' => $owner->id,
            'name' => $name,
            'personal_team' => false,
        ]);
    }
}
