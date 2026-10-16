<?php

declare(strict_types=1);

namespace Tests\Feature\Telephony;

use App\Jobs\RegenerateTelephonyConfig;
use App\Models\AgentPersona;
use App\Models\CallSessionState;
use App\Models\ClientDid;
use App\Models\Extension;
use App\Models\IntakeFlow;
use App\Models\IntakeFlowStep;
use App\Models\IntakeGoal;
use App\Models\Orchestration;
use App\Models\SipTrunk;
use App\Models\Team;
use App\Models\User;
use App\Services\Flows\AgentFlowCompiler;
use App\Services\Telephony\AsteriskConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The AI collects the client's fields, then hands the caller to the
 * client's outside operator (a Transfer Call step with an external
 * destination).
 */
class AiHandoffTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private AgentPersona $persona;

    private IntakeFlow $flow;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('asterisk-config');
        Bus::fake([RegenerateTelephonyConfig::class]);
        config(['services.agent_worker.token' => 'worker-secret']);

        $this->team = Team::forceCreate(['user_id' => User::factory()->create()->id, 'name' => 'Acme', 'personal_team' => false]);
        $orchestration = Orchestration::withoutGlobalScopes()->create(['team_id' => $this->team->id, 'name' => 'Intake & handoff']);
        $this->flow = IntakeFlow::withoutGlobalScopes()->create([
            'team_id' => $this->team->id, 'orchestration_id' => $orchestration->id, 'name' => 'Intake',
        ]);
        $this->persona = AgentPersona::withoutGlobalScopes()->create([
            'team_id' => $this->team->id, 'name' => 'Ava', 'role' => 'receptionist', 'default_flow_id' => $this->flow->id,
        ]);
        Extension::withoutGlobalScopes()->create([
            'team_id' => $this->team->id, 'number' => '1001', 'type' => 'ai_agent',
            'assignable_type' => AgentPersona::class, 'assignable_id' => $this->persona->id, 'is_active' => true,
        ]);
    }

    private function handoffStep(string $number): IntakeFlowStep
    {
        $goal = IntakeGoal::firstOrCreate(['key' => 'transfer_call'], ['name' => 'Transfer Call']);

        return IntakeFlowStep::create([
            'flow_id' => $this->flow->id, 'intake_goal_id' => $goal->id, 'position' => 1,
            'step_params' => ['destination_type' => 'external', 'destination' => $number],
        ]);
    }

    private function worker(): array
    {
        return ['Authorization' => 'Bearer worker-secret'];
    }

    public function test_worker_reports_caller_and_dialled_numbers_normalised(): void
    {
        $this->postJson('/api/call-sessions/agent-1001_abc/context', [
            'caller_number' => '(905) 555-0100', 'called_number' => '12892271019', 'extension' => '1001',
        ], $this->worker())->assertOk();

        $session = CallSessionState::where('session_key', 'agent-1001_abc')->sole();
        $this->assertSame('+19055550100', $session->caller_number);
        $this->assertSame('+12892271019', $session->called_number);
        $this->assertSame($this->team->id, $session->team_id);
    }

    public function test_handoff_records_the_call_and_returns_the_dialplan_target(): void
    {
        $this->handoffStep('905-555-0199');

        $this->postJson('/api/call-sessions/agent-1001_abc/handoff', ['extension' => '1001'], $this->worker())
            ->assertOk()
            ->assertJsonPath('data.number', '+19055550199')
            ->assertJsonPath('data.transfer_to', "sip:handoff-{$this->team->id}-19055550199@asterisk");

        $session = CallSessionState::where('session_key', 'agent-1001_abc')->sole();
        $this->assertSame('+19055550199', $session->handoff_number);
        $this->assertNotNull($session->handed_off_at);
    }

    public function test_handoff_without_a_configured_number_is_refused(): void
    {
        $this->postJson('/api/call-sessions/agent-1001_abc/handoff', ['extension' => '1001'], $this->worker())
            ->assertStatus(422);

        $this->assertNull(CallSessionState::where('session_key', 'agent-1001_abc')->value('handed_off_at'));
    }

    public function test_worker_endpoints_need_the_worker_token(): void
    {
        $this->handoffStep('+19055550199');

        $this->postJson('/api/call-sessions/agent-1001_abc/handoff', ['extension' => '1001'])->assertUnauthorized();
        $this->postJson('/api/call-sessions/agent-1001_abc/context', ['caller_number' => '1'])->assertUnauthorized();
    }

    public function test_dialplan_dials_only_configured_numbers_out_the_clients_trunk(): void
    {
        $trunk = SipTrunk::withoutGlobalScopes()->create(['name' => 'Carrier', 'host' => 'sip.carrier.test', 'port' => 5060, 'transport' => 'udp', 'is_active' => true]);
        ClientDid::create(['team_id' => $this->team->id, 'sip_trunk_id' => $trunk->id, 'number' => '+12892271019', 'is_active' => true]);
        $this->handoffStep('+19055550199');

        $dialplan = app(AsteriskConfigService::class)->generateFromTrunkDispatcher();

        $this->assertStringContainsString("exten => handoff-{$this->team->id}-19055550199,1", $dialplan);
        $this->assertStringContainsString("Dial(PJSIP/+19055550199@trunk_{$trunk->id},60,rb(orbital-handoff-headers^s^1))", $dialplan);
        $this->assertStringContainsString('Set(_ORBITAL_DIVERSION_HOST=sip.carrier.test)', $dialplan);
        $this->assertStringContainsString('PJSIP_HEADER(add,Diversion)', $dialplan);
        $this->assertSame(1, substr_count($dialplan, 'exten => handoff-'));
    }

    public function test_changing_a_handoff_step_regenerates_the_dialplan(): void
    {
        $this->handoffStep('+19055550199');

        Bus::assertDispatched(RegenerateTelephonyConfig::class, fn ($job) => $job->teamId === $this->team->id);
    }

    public function test_compiled_flow_offers_the_handoff_tool_without_the_number(): void
    {
        $this->handoffStep('+19055550199');

        $compiled = app(AgentFlowCompiler::class)->compile(persona: $this->persona)->toArray();

        $names = array_column($compiled['function_schemas'], 'name');
        $this->assertContains('transfer_to_operator', $names);
        $this->assertStringContainsString('transfer_to_operator', $compiled['llm_instructions']);
        $this->assertStringNotContainsString('9055550199', $compiled['llm_instructions']);
    }
}
