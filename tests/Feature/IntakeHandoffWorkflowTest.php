<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\RegenerateTelephonyConfig;
use App\Models\AgentPersona;
use App\Models\ClientSlot;
use App\Models\Extension;
use App\Models\IntakeFlow;
use App\Models\IntakeFlowStep;
use App\Models\IntakeGoal;
use App\Models\Orchestration;
use App\Models\Team;
use App\Models\TeamApiKey;
use App\Models\User;
use App\Services\Flows\AgentFlowCompiler;
use App\Services\Flows\ApplyOrchestrationTemplate;
use Database\Seeders\IntakeHandoffTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The demo workflow end to end: apply the shared intake → handoff
 * template to a client, the AI collects the client's fields and hands
 * off, and the operator's system fetches them with the client's key.
 */
class IntakeHandoffWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('asterisk-config');
        Bus::fake([RegenerateTelephonyConfig::class]);
        config(['services.agent_worker.token' => 'worker-secret']);
        IntakeGoal::firstOrCreate(['key' => 'transfer_call'], ['name' => 'Transfer Call']);
    }

    private function template(): Orchestration
    {
        (new IntakeHandoffTemplateSeeder)->run();

        return Orchestration::query()->withoutGlobalScope('team')
            ->whereNull('team_id')->where('name', IntakeHandoffTemplateSeeder::NAME)->sole();
    }

    public function test_template_seeder_is_idempotent(): void
    {
        $this->template();
        (new IntakeHandoffTemplateSeeder)->run();

        $this->assertSame(1, Orchestration::query()->withoutGlobalScope('team')->where('name', IntakeHandoffTemplateSeeder::NAME)->count());
    }

    public function test_apply_call_handoff_and_lookup(): void
    {
        $team = Team::forceCreate(['user_id' => User::factory()->create()->id, 'name' => 'Acme Plumbing', 'personal_team' => false]);
        $persona = AgentPersona::withoutGlobalScopes()->create(['team_id' => $team->id, 'name' => 'Ava', 'role' => 'receptionist']);
        Extension::withoutGlobalScopes()->create([
            'team_id' => $team->id, 'number' => '1001', 'type' => 'ai_agent',
            'assignable_type' => AgentPersona::class, 'assignable_id' => $persona->id, 'is_active' => true,
        ]);

        // Apply: copies flows + default fields, sets the number, wires the persona.
        $copy = app(ApplyOrchestrationTemplate::class)->apply($this->template(), $team, $persona, '(905) 555-0199');

        $this->assertSame($team->id, $copy->team_id);
        $this->assertSame(IntakeHandoffTemplateSeeder::NAME, $copy->name);
        $this->assertEqualsCanonicalizing(
            ['caller_name', 'callback_number', 'reason'],
            ClientSlot::withoutGlobalScopes()->where('team_id', $team->id)->pluck('name')->all(),
        );
        $flow = IntakeFlow::withoutGlobalScopes()->where('orchestration_id', $copy->id)->sole();
        $this->assertSame($flow->id, $persona->fresh()->default_flow_id);
        $step = IntakeFlowStep::where('flow_id', $flow->id)->where('position', 2)->sole();
        $this->assertSame('+19055550199', $step->step_params['destination']);

        // The AI's compiled flow collects fields and offers the handoff tool.
        $compiled = app(AgentFlowCompiler::class)->compile(persona: $persona->fresh())->toArray();
        $this->assertContains('transfer_to_operator', array_column($compiled['function_schemas'], 'name'));
        $this->assertStringContainsString('callback_number', $compiled['llm_instructions']);

        // The call: worker reports the caller, captures fields, hands off.
        $worker = ['Authorization' => 'Bearer worker-secret'];
        $key = 'agent-1001_xyz';
        $this->postJson("/api/call-sessions/{$key}/context", ['caller_number' => '+14165550123', 'called_number' => '+12892271019', 'extension' => '1001'], $worker)->assertOk();
        foreach (['caller_name' => 'Jane Doe', 'callback_number' => '416 555 0123', 'reason' => 'Burst pipe', 'unit' => '4B'] as $field => $value) {
            $this->postJson("/api/call-sessions/{$key}/field", ['key' => $field, 'value' => $value, 'extension' => '1001'], $worker)->assertOk();
        }
        $this->postJson("/api/call-sessions/{$key}/handoff", ['extension' => '1001'], $worker)
            ->assertOk()
            ->assertJsonPath('data.transfer_to', "sip:handoff-{$team->id}-19055550199@asterisk");

        // The operator's system looks the call up by the caller's number.
        $token = TeamApiKey::issue($team, 'Operator CRM')[1];
        $response = $this->getJson('/api/v1/handoffs/lookup?phone=4165550123', ['Authorization' => "Bearer {$token}"])->assertOk();

        $response->assertJsonPath('data.caller_number', '+14165550123')
            ->assertJsonPath('data.called_number', '+12892271019')
            ->assertJsonPath('data.handoff_number', '+19055550199')
            ->assertJsonPath('data.other_fields.unit', '4B');
        $fields = collect($response->json('data.fields'))->keyBy('key');
        $this->assertSame('Jane Doe', $fields['caller_name']['value']);
        $this->assertSame('Caller name', $fields['caller_name']['label']);
        $this->assertSame('Burst pipe', $fields['reason']['value']);
    }
}
