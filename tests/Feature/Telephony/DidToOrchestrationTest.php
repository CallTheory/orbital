<?php

declare(strict_types=1);

namespace Tests\Feature\Telephony;

use App\Jobs\RegenerateTelephonyConfig;
use App\Models\AgentPersona;
use App\Models\CallSessionState;
use App\Models\ClientDid;
use App\Models\IntakeFlow;
use App\Models\IntakeFlowTransition;
use App\Models\Orchestration;
use App\Models\RoutingRule;
use App\Models\Team;
use App\Services\Telephony\AsteriskConfigService;
use Database\Seeders\PermissionCatalogSeeder;
use Database\Seeders\SuperAdminRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesOrbitalUsers;
use Tests\TestCase;

/**
 * A DID can send calls straight to an orchestration's Inbound Phone
 * channel, answered by the persona chosen on that channel — no AI
 * extension needed.
 */
class DidToOrchestrationTest extends TestCase
{
    use CreatesOrbitalUsers;
    use RefreshDatabase;

    private Team $team;

    private Orchestration $orchestration;

    private IntakeFlow $channel;

    private IntakeFlow $intake;

    private AgentPersona $persona;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('asterisk-config');
        Bus::fake([RegenerateTelephonyConfig::class]);
        config(['services.agent_worker.token' => 'worker-secret']);

        $this->team = Team::factory()->create(['personal_team' => false]);
        $this->persona = AgentPersona::withoutGlobalScopes()->create([
            'team_id' => $this->team->id, 'name' => 'Steph', 'role' => 'receptionist', 'is_active' => true,
        ]);
        $this->orchestration = Orchestration::withoutGlobalScopes()->forceCreate(['team_id' => $this->team->id, 'name' => 'Default']);
        $this->channel = IntakeFlow::withoutGlobalScopes()->create([
            'team_id' => $this->team->id, 'orchestration_id' => $this->orchestration->id, 'name' => 'Inbound Phone',
            'trigger_type' => IntakeFlow::TRIGGER_INBOUND_PHONE, 'kind' => IntakeFlow::KIND_CALL_FLOW, 'is_active' => true,
            'agent_persona_id' => $this->persona->id,
        ]);
        $this->intake = IntakeFlow::withoutGlobalScopes()->create([
            'team_id' => $this->team->id, 'orchestration_id' => $this->orchestration->id, 'name' => 'Intake',
            'trigger_type' => IntakeFlow::TRIGGER_SUBFLOW, 'kind' => IntakeFlow::KIND_CALL_FLOW, 'is_active' => true,
        ]);
        IntakeFlowTransition::create([
            'from_flow_id' => $this->channel->id, 'to_flow_id' => $this->intake->id, 'priority' => 10, 'description' => 'matched',
        ]);
    }

    private function routeDid(): ?RoutingRule
    {
        ClientDid::create([
            'team_id' => $this->team->id, 'number' => '+18325790579', 'is_active' => true,
            'destination_type' => 'orchestration', 'destination_id' => $this->orchestration->id,
        ]);

        return RoutingRule::withoutGlobalScopes()->first();
    }

    public function test_the_did_rule_points_at_the_channel_and_dials_the_ai_directly(): void
    {
        $rule = $this->routeDid();

        $this->assertSame('orchestration', $rule->destination_type);
        $this->assertSame($this->channel->id, (int) $rule->intake_flow_id);

        $dispatcher = app(AsteriskConfigService::class)->generateFromTrunkDispatcher();
        $this->assertStringContainsString('exten => +18325790579,1', $dispatcher);
        $this->assertStringContainsString("Dial(PJSIP/r{$rule->id}@livekit,120,tTkKb(orbital-ai-headers^s^1))", $dispatcher);
    }

    public function test_an_orchestration_without_an_inbound_phone_channel_is_not_routed(): void
    {
        $this->channel->forceDelete();

        $this->assertNull($this->routeDid());
    }

    public function test_the_worker_gets_the_channel_persona_starting_at_the_channels_flow(): void
    {
        $rule = $this->routeDid();

        $this->getJson("/api/agent-personas/by-extension/r{$rule->id}", ['Authorization' => 'Bearer worker-secret'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Steph')
            ->assertJsonPath('compiled_flow.flow_id', $this->intake->id)
            ->assertJsonPath('compiled_flow.resolution_source', 'routing_rule');
    }

    public function test_the_worker_gets_nothing_when_the_channel_has_no_persona(): void
    {
        $rule = $this->routeDid();
        $this->channel->forceFill(['agent_persona_id' => null])->save();

        $this->getJson("/api/agent-personas/by-extension/r{$rule->id}", ['Authorization' => 'Bearer worker-secret'])
            ->assertNotFound();
    }

    public function test_a_rule_that_does_not_route_to_an_orchestration_is_not_a_target(): void
    {
        $rule = RoutingRule::withoutGlobalScopes()->create([
            'team_id' => $this->team->id, 'name' => 'Queue DID', 'match_type' => 'did', 'match_pattern' => '+15550001111',
            'destination_type' => 'queue', 'destination_id' => 1, 'intake_flow_id' => $this->channel->id,
            'priority' => 10, 'is_active' => true,
        ]);

        $this->getJson("/api/agent-personas/by-extension/r{$rule->id}", ['Authorization' => 'Bearer worker-secret'])
            ->assertNotFound();
    }

    public function test_the_call_session_records_its_client_persona_and_entry_flow(): void
    {
        $rule = $this->routeDid();

        $this->postJson('/api/call-sessions/agent-_r'.$rule->id.'_abc/context', [
            'caller_number' => '+14165550123', 'called_number' => '+18325790579', 'extension' => 'r'.$rule->id,
        ], ['Authorization' => 'Bearer worker-secret'])->assertOk();

        $state = CallSessionState::where('session_key', 'agent-_r'.$rule->id.'_abc')->sole();
        $this->assertSame($this->team->id, (int) $state->team_id);
        $this->assertSame($this->persona->id, (int) $state->agent_persona_id);
        $this->assertSame($this->intake->id, (int) $state->entry_flow_id);
    }

    public function test_the_editor_saves_the_channel_persona_but_not_another_clients(): void
    {
        $this->seed(PermissionCatalogSeeder::class);
        $this->seed(SuperAdminRoleSeeder::class);
        $other = AgentPersona::withoutGlobalScopes()->create([
            'team_id' => Team::factory()->create()->id, 'name' => 'Elsewhere', 'role' => 'receptionist', 'is_active' => true,
        ]);
        $admin = $this->makeUserWithTeamlessRole('super_admin');

        $save = fn (?int $personaId) => $this->actingAs($admin)
            ->putJson("/api/admin/orchestrations/{$this->orchestration->id}", [
                'slots' => [],
                'flows' => [
                    ['id' => $this->channel->id, 'client_id' => (string) $this->channel->id, 'name' => 'Inbound Phone', 'trigger_type' => 'inbound_phone', 'kind' => 'call_flow', 'agent_persona_id' => $personaId, 'steps' => []],
                    ['id' => $this->intake->id, 'client_id' => (string) $this->intake->id, 'name' => 'Intake', 'trigger_type' => 'subflow', 'kind' => 'call_flow', 'agent_persona_id' => $personaId, 'steps' => []],
                ],
                'transitions' => [
                    ['id' => null, 'from_flow_client_id' => (string) $this->channel->id, 'to_flow_client_id' => (string) $this->intake->id, 'description' => 'matched', 'priority' => 10],
                ],
            ]);

        $save(null)->assertOk()->assertJsonPath('flows.0.agent_persona_id', null);
        $this->assertNull($this->channel->fresh()->agent_persona_id);

        $save($this->persona->id)->assertOk();
        $this->assertSame($this->persona->id, (int) $this->channel->fresh()->agent_persona_id);
        $this->assertNull($this->intake->fresh()->agent_persona_id, 'only the Inbound Phone channel carries a persona');

        $save($other->id)->assertOk();
        $this->assertNull($this->channel->fresh()->agent_persona_id);
    }
}
