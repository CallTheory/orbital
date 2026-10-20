<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\RegenerateTelephonyConfig;
use App\Models\AgentPersona;
use App\Models\CallSessionState;
use App\Models\Extension;
use App\Models\IntakeFlow;
use App\Models\IntakeFlowStep;
use App\Models\IntakeFlowTransition;
use App\Models\IntakeGoal;
use App\Models\Message;
use App\Models\Orchestration;
use App\Models\Team;
use App\Models\User;
use App\Services\Flows\AgentFlowCompiler;
use App\Services\Flows\ApplyOrchestrationTemplate;
use App\Services\Telephony\HandoffDestinations;
use Database\Seeders\IntakeHandoffTemplateSeeder;
use Database\Seeders\IntakeMessageTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The shared AI intake templates hang off an Inbound Phone channel, and
 * applying one adds it to the client's own Inbound Phone in their
 * Default orchestration instead of creating a separate orchestration.
 */
class AiIntakeTemplatesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('asterisk-config');
        Bus::fake([RegenerateTelephonyConfig::class]);
        config(['services.agent_worker.token' => 'worker-secret']);
        IntakeGoal::firstOrCreate(['key' => 'transfer_call'], ['name' => 'Transfer Call']);
        IntakeGoal::firstOrCreate(['key' => 'save_message'], ['name' => 'Save Message']);
    }

    private function template(string $seeder): Orchestration
    {
        (new $seeder)->run();

        return Orchestration::query()->withoutGlobalScope('team')
            ->whereNull('team_id')->where('name', $seeder::NAME)->sole();
    }

    /** @return array{0: Team, 1: AgentPersona} */
    private function client(): array
    {
        $team = Team::forceCreate(['user_id' => User::factory()->create()->id, 'name' => 'Acme Plumbing', 'personal_team' => false]);
        $persona = AgentPersona::withoutGlobalScopes()->create(['team_id' => $team->id, 'name' => 'Ava', 'role' => 'receptionist']);
        Extension::withoutGlobalScopes()->create([
            'team_id' => $team->id, 'number' => '1001', 'type' => 'ai_agent',
            'assignable_type' => AgentPersona::class, 'assignable_id' => $persona->id, 'is_active' => true,
        ]);

        return [$team, $persona];
    }

    private function inboundPhone(Orchestration $orchestration): IntakeFlow
    {
        return IntakeFlow::withoutGlobalScopes()
            ->where('orchestration_id', $orchestration->id)
            ->where('trigger_type', IntakeFlow::TRIGGER_INBOUND_PHONE)
            ->sole();
    }

    /** @return array<int, int> */
    private function connectedFrom(IntakeFlow $trigger): array
    {
        return IntakeFlowTransition::where('from_flow_id', $trigger->id)->whereNotNull('to_flow_id')->pluck('to_flow_id')->all();
    }

    public function test_templates_start_from_their_inbound_phone_channel(): void
    {
        foreach ([IntakeHandoffTemplateSeeder::class => 'Intake & handoff', IntakeMessageTemplateSeeder::class => 'Intake & message'] as $seeder => $flowName) {
            $template = $this->template($seeder);
            (new $seeder)->run();

            $flows = IntakeFlow::withoutGlobalScopes()->where('orchestration_id', $template->id)->get();
            $this->assertCount(6, $flows, $seeder);
            $intake = $flows->firstWhere('name', $flowName);
            $this->assertNotNull($intake->canvas_y);

            $inbound = $this->inboundPhone($template);
            $this->assertTrue($inbound->is_active);
            $this->assertSame([$intake->id], $this->connectedFrom($inbound));
            $this->assertSame('matched', IntakeFlowTransition::where('from_flow_id', $inbound->id)->value('description'));
        }
    }

    public function test_handoff_template_seeded_before_channels_gets_wired_up(): void
    {
        // What 2026_10_16_120000 seeded: one flow, no channels, no position.
        $template = Orchestration::query()->withoutGlobalScope('team')->forceCreate(['team_id' => null, 'name' => IntakeHandoffTemplateSeeder::NAME]);
        $flow = IntakeFlow::withoutGlobalScopes()->create([
            'team_id' => null, 'orchestration_id' => $template->id, 'name' => 'Intake & handoff',
            'trigger_type' => 'persona_default', 'kind' => IntakeFlow::KIND_CALL_FLOW, 'is_active' => true, 'display_order' => 1,
        ]);

        $migration = require database_path('migrations/2026_10_20_100000_wire_ai_intake_templates_to_inbound_phone.php');
        $migration->up();
        $migration->up();

        $this->assertNotNull($flow->fresh()->canvas_y);
        $this->assertSame([$flow->id], $this->connectedFrom($this->inboundPhone($template)));
        $this->assertSame(1, Orchestration::query()->withoutGlobalScope('team')->where('name', IntakeHandoffTemplateSeeder::NAME)->count());
        $this->assertSame(1, Orchestration::query()->withoutGlobalScope('team')->where('name', IntakeMessageTemplateSeeder::NAME)->count());
    }

    public function test_applying_adds_to_the_clients_inbound_phone_instead_of_a_new_orchestration(): void
    {
        [$team, $persona] = $this->client();

        $default = app(ApplyOrchestrationTemplate::class)->apply($this->template(IntakeHandoffTemplateSeeder::class), $team, $persona, '+19055550199');

        $this->assertSame(['Default'], Orchestration::query()->withoutGlobalScope('team')->where('team_id', $team->id)->pluck('name')->all());
        $flow = IntakeFlow::withoutGlobalScopes()->where('orchestration_id', $default->id)->where('name', 'Intake & handoff')->sole();
        $this->assertSame([$flow->id], $this->connectedFrom($this->inboundPhone($default)));
        $this->assertTrue($this->inboundPhone($default)->is_active);
        $this->assertSame($flow->id, $persona->fresh()->default_flow_id);
        // The channel row stays the client's own: none copied from the template.
        $this->assertSame(5, IntakeFlow::withoutGlobalScopes()->where('orchestration_id', $default->id)->whereIn('trigger_type', IntakeFlow::CHANNEL_TRIGGERS)->count());
    }

    public function test_message_template_takes_a_message_and_ends_the_call(): void
    {
        [$team, $persona] = $this->client();
        $handoff = app(ApplyOrchestrationTemplate::class)->apply($this->template(IntakeHandoffTemplateSeeder::class), $team, $persona, '+19055550199');
        $handoffFlow = IntakeFlow::withoutGlobalScopes()->where('orchestration_id', $handoff->id)->where('name', 'Intake & handoff')->sole();

        // Switch the client to overflow: the message flow replaces the
        // handoff on the Inbound Phone port, and nobody is asked for a number.
        $default = app(ApplyOrchestrationTemplate::class)->apply($this->template(IntakeMessageTemplateSeeder::class), $team, $persona);

        $message = IntakeFlow::withoutGlobalScopes()->where('orchestration_id', $default->id)->where('name', 'Intake & message')->sole();
        $this->assertSame([$message->id], $this->connectedFrom($this->inboundPhone($default)));
        $this->assertNotNull(IntakeFlow::withoutGlobalScopes()->find($handoffFlow->id), 'the earlier flow stays on the canvas');
        $this->assertGreaterThan($handoffFlow->canvas_y, $message->canvas_y, 'added below what was there');
        $this->assertSame($message->id, $persona->fresh()->default_flow_id);

        $compiled = app(AgentFlowCompiler::class)->compile(persona: $persona->fresh())->toArray();
        $this->assertNotContains('transfer_to_operator', array_column($compiled['function_schemas'], 'name'));
        $this->assertStringContainsString('end the call', $compiled['llm_instructions']);
        $this->assertStringContainsString('save_message', $compiled['llm_instructions']);

        // The captured fields become a message for the operators.
        $worker = ['Authorization' => 'Bearer worker-secret'];
        $key = 'agent-1001_msg';
        $this->postJson("/api/call-sessions/{$key}/context", ['caller_number' => '+14165550123', 'called_number' => '+12892271019', 'extension' => '1001'], $worker)->assertOk();
        foreach (['caller_name' => 'Jane Doe', 'callback_number' => '416 555 0123', 'reason' => 'Burst pipe'] as $field => $value) {
            $this->postJson("/api/call-sessions/{$key}/field", ['key' => $field, 'value' => $value, 'extension' => '1001'], $worker)->assertOk();
        }

        $saved = Message::withoutGlobalScopes()->where('team_id', $team->id)->sole();
        $this->assertSame('Jane Doe', $saved->caller_name);
        $this->assertSame('Burst pipe', $saved->reason);
        $this->assertFalse((bool) $saved->is_partial);
    }

    public function test_reapplying_with_a_new_number_hands_off_to_the_new_number(): void
    {
        [$team, $persona] = $this->client();
        $template = $this->template(IntakeHandoffTemplateSeeder::class);

        app(ApplyOrchestrationTemplate::class)->apply($template, $team, $persona, '+19055550199');
        $default = app(ApplyOrchestrationTemplate::class)->apply($template, $team, $persona, '+16475550100');

        $this->assertSame(2, IntakeFlow::withoutGlobalScopes()->where('orchestration_id', $default->id)->where('name', 'Intake & handoff')->count());
        $this->assertCount(1, $this->connectedFrom($this->inboundPhone($default)));

        $session = CallSessionState::create(['session_key' => 'agent-1001_re', 'team_id' => $team->id, 'agent_persona_id' => $persona->id]);
        $this->assertSame('+16475550100', app(HandoffDestinations::class)->forSession($session));
        $this->assertSame('+16475550100', IntakeFlowStep::where('flow_id', $persona->fresh()->default_flow_id)->where('position', 2)->sole()->step_params['destination']);
    }
}
