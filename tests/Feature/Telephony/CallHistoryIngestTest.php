<?php

declare(strict_types=1);

namespace Tests\Feature\Telephony;

use App\Models\AgentPersona;
use App\Models\CallLog;
use App\Models\CallQueue;
use App\Models\CallSessionState;
use App\Models\Extension;
use App\Models\RoutingRule;
use App\Models\Team;
use App\Models\User;
use App\Services\Telephony\AsteriskConfigService;
use App\Services\Telephony\CdrIngester;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Call History is built from the call records Asterisk writes to
 * asterisk_cdr (cdr_adaptive_odbc); see CdrIngester.
 */
class CallHistoryIngestTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private AgentPersona $persona;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('asterisk-config');
        config(['services.agent_worker.token' => 'worker-secret']);

        $this->team = Team::forceCreate(['user_id' => User::factory()->create()->id, 'name' => 'Acme Plumbing', 'personal_team' => false]);
        $this->persona = AgentPersona::withoutGlobalScopes()->create(['team_id' => $this->team->id, 'name' => 'Ava', 'role' => 'receptionist']);
        Extension::withoutGlobalScopes()->create([
            'team_id' => $this->team->id, 'number' => '1001', 'type' => 'ai_agent', 'is_active' => true,
            'assignable_type' => AgentPersona::class, 'assignable_id' => $this->persona->id,
        ]);
    }

    /**
     * One CDR row as cdr_adaptive_odbc writes it.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function cdr(array $overrides = []): void
    {
        DB::table(CdrIngester::TABLE)->insert(array_merge([
            'started_at' => '2026-11-11 15:00:00',
            'answered_at' => '2026-11-11 15:00:02',
            'ended_at' => '2026-11-11 15:03:32',
            'clid' => '"JANE DOE" <+15125550100>',
            'src' => '+15125550100',
            'dst' => '+15125559999',
            'dcontext' => 'from-trunk',
            'channel' => 'PJSIP/trunk-00000001',
            'dstchannel' => 'PJSIP/livekit-00000002',
            'lastapp' => 'Dial',
            'lastdata' => 'PJSIP/1001@livekit,120,tTkKb(orbital-ai-headers^s^1)',
            'duration' => 212,
            'billsec' => 210,
            // As cdr_adaptive_odbc writes it: Asterisk's number (8 = answered).
            'disposition' => '8',
            'uniqueid' => '1762873200.1',
            'linkedid' => '1762873200.1',
            'sequence' => 1,
            'orbital_team_id' => (string) $this->team->id,
            'orbital_rule_id' => '7',
            'orbital_did' => '+15125559999',
            'orbital_sip_call_id' => 'abc123@carrier',
        ], $overrides));
    }

    private function ingest(): array
    {
        return app(CdrIngester::class)->ingest();
    }

    public function test_an_answered_ai_call_becomes_a_call_history_row(): void
    {
        $this->cdr();

        $this->assertSame(['calls' => 1, 'logged' => 1, 'skipped' => 0], $this->ingest());

        $log = CallLog::withoutGlobalScopes()->sole();
        $this->assertSame($this->team->id, $log->team_id);
        $this->assertSame('1762873200.1', $log->linked_id);
        $this->assertSame('inbound', $log->direction);
        $this->assertSame('completed', $log->status);
        $this->assertSame('+15125550100', $log->from_number);
        $this->assertSame('+15125559999', $log->to_number);
        $this->assertSame('JANE DOE', $log->caller_id_name);
        $this->assertSame('abc123@carrier', $log->sip_call_id);
        $this->assertSame(212, $log->duration_seconds);
        $this->assertSame(210, $log->billable_seconds);
        $this->assertSame($this->persona->id, $log->agent_persona_id);
        $this->assertSame('ai', $log->metadata['outcome']);
        $this->assertSame(7, $log->metadata['routing_rule_id']);
        $this->assertSame('2026-11-11 15:00:00', $log->started_at->utc()->format('Y-m-d H:i:s'));

        $this->assertSame(0, DB::table(CdrIngester::TABLE)->whereNull('ingested_at')->count());
    }

    public function test_an_unanswered_call_is_missed_and_not_billable(): void
    {
        $this->cdr(['answered_at' => '1970-01-01 00:00:00', 'disposition' => '0', 'billsec' => 0, 'dstchannel' => '',
            'lastdata' => 'PJSIP/2001,18,tTkK', 'dcontext' => 'tenant_'.$this->team->id]);

        $this->ingest();

        $log = CallLog::withoutGlobalScopes()->sole();
        $this->assertSame('missed', $log->status);
        $this->assertSame('NO ANSWER', $log->disposition);
        $this->assertNull($log->answered_at);
        $this->assertSame(0, $log->billable_seconds);
        $this->assertSame(212, $log->duration_seconds);
    }

    public function test_a_ring_then_voicemail_is_one_call(): void
    {
        $this->cdr(['answered_at' => '1970-01-01 00:00:00', 'ended_at' => '2026-11-11 15:00:18', 'disposition' => '0',
            'lastdata' => 'PJSIP/2001,18,tTkK', 'dcontext' => 'tenant_'.$this->team->id, 'sequence' => 1]);
        $this->cdr(['answered_at' => '2026-11-11 15:00:18', 'ended_at' => '2026-11-11 15:00:50', 'disposition' => '8',
            'lastapp' => 'VoiceMail', 'lastdata' => '2001@default,u', 'dstchannel' => '', 'dcontext' => 'tenant_'.$this->team->id,
            'sequence' => 2, 'started_at' => '2026-11-11 15:00:18']);

        $this->assertSame(1, $this->ingest()['calls']);

        $log = CallLog::withoutGlobalScopes()->sole();
        $this->assertSame('completed', $log->status);
        $this->assertSame('voicemail', $log->metadata['outcome']);
        $this->assertSame(50, $log->duration_seconds);
        $this->assertSame(32, $log->billable_seconds);
        $this->assertCount(2, $log->metadata['legs']);
    }

    public function test_busy_and_failed_calls_and_named_dispositions(): void
    {
        $this->cdr(['answered_at' => null, 'disposition' => '4', 'linkedid' => 'busy.1']);
        $this->cdr(['answered_at' => null, 'disposition' => '16', 'linkedid' => 'congested.1']);
        $this->cdr(['disposition' => 'ANSWERED', 'linkedid' => 'named.1']);

        $this->ingest();

        $status = CallLog::withoutGlobalScopes()->pluck('status', 'linked_id');
        $this->assertSame('busy', $status['busy.1']);
        $this->assertSame('failed', $status['congested.1']);
        $this->assertSame('completed', $status['named.1']);
    }

    public function test_the_fallback_message_shows_as_ai_unavailable(): void
    {
        $this->cdr(['answered_at' => null, 'disposition' => 'NO ANSWER', 'sequence' => 1]);
        $this->cdr(['dcontext' => 'orbital-ai-unavailable', 'lastapp' => 'Playback', 'lastdata' => 'sorry&please-try-call-later&goodbye',
            'dstchannel' => '', 'sequence' => 2]);

        $this->ingest();

        $this->assertSame('ai_unavailable', CallLog::withoutGlobalScopes()->sole()->status);
    }

    public function test_a_queue_call_records_the_queue(): void
    {
        $queue = CallQueue::withoutGlobalScopes()->create(['team_id' => $this->team->id, 'name' => 'Front desk']);
        $this->cdr(['dst' => 'queue-'.$queue->id, 'dcontext' => 'tenant_'.$this->team->id, 'lastapp' => 'Queue',
            'lastdata' => 'q-front,tTkK,,,30', 'dstchannel' => 'PJSIP/2001-00000003']);

        $this->ingest();

        $log = CallLog::withoutGlobalScopes()->sole();
        $this->assertSame($queue->id, $log->call_queue_id);
        $this->assertSame('queue', $log->metadata['outcome']);
    }

    public function test_a_call_between_a_clients_extensions_is_internal(): void
    {
        $this->cdr(['orbital_team_id' => '', 'orbital_did' => '', 'orbital_rule_id' => '', 'dcontext' => 'tenant_'.$this->team->id,
            'lastdata' => 'PJSIP/1001@livekit,120,tTkKb(orbital-ai-headers^s^1)', 'dst' => '1001', 'src' => '2001']);

        $this->ingest();

        $log = CallLog::withoutGlobalScopes()->sole();
        $this->assertSame($this->team->id, $log->team_id);
        $this->assertSame('internal', $log->direction);
        $this->assertSame('1001', $log->to_number);
    }

    public function test_calls_that_never_reached_a_client_are_skipped(): void
    {
        $this->cdr(['orbital_team_id' => '', 'orbital_did' => '', 'dcontext' => 'from-trunk', 'lastapp' => 'Hangup', 'lastdata' => '']);

        $this->assertSame(['calls' => 1, 'logged' => 0, 'skipped' => 1], $this->ingest());

        $this->assertSame(0, CallLog::withoutGlobalScopes()->count());
        $this->assertSame(0, DB::table(CdrIngester::TABLE)->whereNull('ingested_at')->count());
    }

    public function test_a_late_record_rebuilds_the_same_call(): void
    {
        $this->cdr(['answered_at' => null, 'disposition' => 'NO ANSWER', 'sequence' => 1]);
        $this->ingest();
        $this->assertSame('missed', CallLog::withoutGlobalScopes()->sole()->status);

        $this->cdr(['dcontext' => 'orbital-ai-unavailable', 'lastapp' => 'Playback', 'dstchannel' => '', 'sequence' => 2]);
        $this->ingest();
        $this->ingest();

        $this->assertSame('ai_unavailable', CallLog::withoutGlobalScopes()->sole()->status);
    }

    public function test_the_ai_session_links_its_persona_and_transcript_to_the_call(): void
    {
        $headers = ['Authorization' => 'Bearer worker-secret'];
        $this->postJson('/api/call-sessions/agent-_1001_abc/context', [
            'caller_number' => '+15125550100', 'called_number' => '+15125559999', 'extension' => '1001', 'call_id' => '1762873200.1',
        ], $headers)->assertOk();
        $this->assertSame('1762873200.1', CallSessionState::sole()->linked_id);

        // Transcript before Asterisk's record is ingested: picked up at ingest.
        $this->postJson('/api/call-sessions/agent-_1001_abc/transcript', ['transcript' => "Agent: Hi\nCaller: Help"], $headers)->assertOk();
        $this->cdr();
        $this->ingest();

        $log = CallLog::withoutGlobalScopes()->sole();
        $this->assertSame("Agent: Hi\nCaller: Help", $log->transcript);
        $this->assertSame('agent-_1001_abc', $log->metadata['ai_session']);
        $this->assertSame(1, $log->callSessions()->count());

        // Transcript after ingest: updates the call directly.
        $this->postJson('/api/call-sessions/agent-_1001_abc/transcript', ['transcript' => 'Agent: Updated'], $headers)->assertOk();
        $this->assertSame('Agent: Updated', $log->fresh()->transcript);
    }

    public function test_the_transcript_endpoint_requires_the_worker_token(): void
    {
        $this->postJson('/api/call-sessions/agent-_1001_abc/transcript', ['transcript' => 'x'])->assertUnauthorized();
    }

    public function test_the_command_ingests_and_prunes_old_raw_records(): void
    {
        $this->cdr();
        $this->cdr(['linkedid' => 'old.1', 'uniqueid' => 'old.1', 'ingested_at' => now()->subDays(CdrIngester::KEEP_RAW_DAYS + 1)]);

        $this->artisan('orbital:ingest-cdrs')->expectsOutputToContain('1 call(s): 1 logged')->assertSuccessful();

        $this->assertSame(1, DB::table(CdrIngester::TABLE)->count());
        $this->assertSame(1, CallLog::withoutGlobalScopes()->count());
    }

    public function test_the_dialplan_tags_inbound_calls_and_tells_livekit_the_call_id(): void
    {
        $rule = RoutingRule::withoutGlobalScopes()->create([
            'team_id' => $this->team->id, 'name' => 'Main line', 'match_pattern' => '+15125559999',
            'destination_type' => 'extension', 'destination_id' => '1001', 'is_active' => true, 'priority' => 1,
        ]);

        $dispatcher = app(AsteriskConfigService::class)->generateFromTrunkDispatcher();

        $this->assertStringContainsString("Gosub(orbital-cdr-inbound,s,1({$this->team->id},{$rule->id}))", $dispatcher);
        $this->assertStringContainsString('Set(CDR(orbital_sip_call_id)=${CHANNEL(pjsip,call-id)})', $dispatcher);
        $this->assertStringContainsString('Set(PJSIP_HEADER(add,X-Orbital-Call)=${CHANNEL(linkedid)})', $dispatcher);
    }

    public function test_durations_read_naturally(): void
    {
        $this->assertSame('—', CallLog::formatDuration(0));
        $this->assertSame('3:30', CallLog::formatDuration(210));
        $this->assertSame('1:02:05', CallLog::formatDuration(3725));
    }
}
