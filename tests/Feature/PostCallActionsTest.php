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
use App\Models\Orchestration;
use App\Models\Team;
use App\Models\User;
use App\Services\Flows\AgentFlowCompiler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/**
 * A take-a-message flow followed by a Call Completed flow that emails
 * and posts to a webhook: Orbital does both once the call ends, with
 * what the AI captured, and the AI is never asked to.
 */
class PostCallActionsTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK = 'https://matrix.example.com/webhook/abc';

    private const WORKER = ['Authorization' => 'Bearer worker-secret'];

    private Team $team;

    private AgentPersona $persona;

    private IntakeFlow $intake;

    private IntakeFlow $completed;

    private int $webhookStatus = 200;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('asterisk-config');
        Bus::fake([RegenerateTelephonyConfig::class]);
        config(['services.agent_worker.token' => 'worker-secret', 'mail.default' => 'array']);
        Http::fake([self::WEBHOOK => fn () => Http::response('', $this->webhookStatus)]);

        foreach (['gather_intake_fields', 'save_message', 'send_email', 'web_call', 'transfer_call'] as $key) {
            IntakeGoal::firstOrCreate(['key' => $key], ['name' => $key]);
        }

        $this->team = Team::forceCreate(['user_id' => User::factory()->create()->id, 'name' => 'Call Theory', 'personal_team' => false]);
        $orchestration = Orchestration::withoutGlobalScopes()->forceCreate(['team_id' => $this->team->id, 'name' => 'Default']);
        $this->intake = $this->flow($orchestration, 'Intake & message', [
            ['gather_intake_fields', ['prompt' => 'A few details.']],
            ['save_message', ['include_slots' => ['caller_name', 'callback_number', 'reason'], 'destination' => 'inbox']],
        ]);
        $this->completed = $this->flow($orchestration, 'Call Completed', [
            ['send_email', [
                'recipient' => 'support@calltheory.com',
                'subject' => 'New on-call message from AI - {{ now() }}',
                'body' => "Name: {{ caller_name }}\nCallback Number: {{ callback_number }}\n\n{{ reason }}",
            ]],
            ['web_call', [
                'endpoint_name' => 'Call Theory Matrix',
                'method' => 'POST',
                'path' => self::WEBHOOK,
                'body_template' => "{\n\"caller_name\" : \"{{ caller_name }}\",\n\"reason\": \"{{ reason }}\"\n}",
                'expected_status_codes' => '200',
            ]],
        ]);
        IntakeFlowTransition::create(['from_flow_id' => $this->intake->id, 'to_flow_id' => $this->completed->id, 'description' => 'continue', 'priority' => 10]);

        $this->persona = AgentPersona::withoutGlobalScopes()->create(['team_id' => $this->team->id, 'name' => 'Steph', 'role' => 'receptionist']);
        $this->persona->forceFill(['default_flow_id' => $this->intake->id])->save();
        Extension::withoutGlobalScopes()->create([
            'team_id' => $this->team->id, 'number' => '1001', 'type' => 'ai_agent',
            'assignable_type' => AgentPersona::class, 'assignable_id' => $this->persona->id, 'is_active' => true,
        ]);
    }

    /** @param  list<array{0: string, 1: array<string, mixed>}>  $steps */
    private function flow(Orchestration $orchestration, string $name, array $steps): IntakeFlow
    {
        $flow = IntakeFlow::withoutGlobalScopes()->create([
            'team_id' => $this->team->id, 'orchestration_id' => $orchestration->id, 'name' => $name,
            'trigger_type' => 'subflow', 'kind' => IntakeFlow::KIND_CALL_FLOW, 'is_active' => true,
        ]);
        foreach ($steps as $i => [$key, $params]) {
            IntakeFlowStep::create(['flow_id' => $flow->id, 'intake_goal_id' => IntakeGoal::where('key', $key)->value('id'), 'position' => $i, 'step_params' => $params]);
        }

        return $flow;
    }

    /** @param  array<string, string>  $fields */
    private function placeCall(string $key, array $fields): CallSessionState
    {
        $this->postJson("/api/call-sessions/{$key}/context", ['caller_number' => '+16148220019', 'called_number' => '+18325790579', 'extension' => '1001'], self::WORKER)->assertOk();
        foreach ($fields as $field => $value) {
            $this->postJson("/api/call-sessions/{$key}/field", ['key' => $field, 'value' => $value, 'extension' => '1001'], self::WORKER)->assertOk();
        }
        $this->postJson("/api/call-sessions/{$key}/end", ['reason' => 'caller_hung_up', 'extension' => '1001'], self::WORKER)->assertOk();

        return CallSessionState::where('session_key', $key)->sole();
    }

    /** @return list<Email> */
    private function sentEmails(): array
    {
        return app('mailer')->getSymfonyTransport()->messages()->map(fn ($m) => $m->getOriginalMessage())->all();
    }

    public function test_a_taken_message_is_emailed_and_posted_after_the_call(): void
    {
        $state = $this->placeCall('agent-1001_ok', ['caller_name' => 'Jane "JJ" Doe', 'callback_number' => '614 822 0019', 'reason' => 'Burst pipe']);

        $emails = $this->sentEmails();
        $this->assertCount(1, $emails);
        $this->assertSame('support@calltheory.com', $emails[0]->getTo()[0]->getAddress());
        $this->assertStringStartsWith('New on-call message from AI - ', $emails[0]->getSubject());
        $this->assertStringNotContainsString('{{', $emails[0]->getSubject());
        $this->assertStringContainsString("Name: Jane \"JJ\" Doe\nCallback Number: 614 822 0019\n\nBurst pipe", $emails[0]->getTextBody());

        // The quote in the caller's name is escaped, so the body is valid JSON.
        Http::assertSent(fn (Request $r) => $r->url() === self::WEBHOOK
            && $r->method() === 'POST'
            && $r->data() === ['caller_name' => 'Jane "JJ" Doe', 'reason' => 'Burst pipe']);

        $this->assertSame([true, true], array_column($state->post_call_actions, 'ok'));
        $this->assertNotNull($state->post_call_actions_at);
    }

    public function test_nothing_is_sent_when_the_caller_hangs_up_before_leaving_a_message(): void
    {
        $state = $this->placeCall('agent-1001_early', ['caller_name' => 'Jane Doe']);

        $this->assertSame([], $this->sentEmails());
        Http::assertNothingSent();
        $this->assertSame([], $state->post_call_actions);
    }

    public function test_actions_run_once_when_both_end_signals_arrive(): void
    {
        $this->placeCall('agent-1001_twice', ['caller_name' => 'Jane Doe', 'reason' => 'Burst pipe']);
        $this->postJson('/api/call-sessions/agent-1001_twice/end', ['extension' => '1001'], self::WORKER)->assertOk();

        $this->assertCount(1, $this->sentEmails());
        Http::assertSentCount(1);
    }

    public function test_a_failing_webhook_is_recorded_without_stopping_the_email(): void
    {
        $this->webhookStatus = 500;

        $state = $this->placeCall('agent-1001_500', ['caller_name' => 'Jane Doe', 'reason' => 'Burst pipe']);

        $this->assertCount(1, $this->sentEmails());
        [$email, $web] = $state->post_call_actions;
        $this->assertTrue($email['ok']);
        $this->assertFalse($web['ok']);
        $this->assertStringContainsString('HTTP 500', $web['detail']);
    }

    public function test_a_conditional_transition_is_followed_only_when_it_matches(): void
    {
        $urgent = $this->flow(Orchestration::withoutGlobalScopes()->sole(), 'Urgent', [
            ['send_email', ['recipient' => 'oncall@calltheory.com', 'subject' => 'Urgent', 'body' => '{{ reason }}']],
        ]);
        IntakeFlowTransition::create([
            'from_flow_id' => $this->intake->id, 'to_flow_id' => $urgent->id, 'priority' => 5,
            'condition' => ['==' => [['var' => 'slots.reason'], 'Flood']],
        ]);

        $this->placeCall('agent-1001_flood', ['caller_name' => 'Jane Doe', 'reason' => 'Flood']);
        $this->placeCall('agent-1001_pipe', ['caller_name' => 'Jane Doe', 'reason' => 'Burst pipe']);

        $this->assertSame(
            ['oncall@calltheory.com', 'support@calltheory.com'],
            array_map(fn (Email $e) => $e->getTo()[0]->getAddress(), $this->sentEmails()),
        );
    }

    public function test_the_ai_is_not_told_about_post_call_actions(): void
    {
        $prompt = app(AgentFlowCompiler::class)->compile(persona: $this->persona->fresh())->toArray()['llm_instructions'];

        $this->assertStringContainsString('Call Completed', $prompt);
        $this->assertStringNotContainsString('support@calltheory.com', $prompt);
        $this->assertStringNotContainsString(self::WEBHOOK, $prompt);
    }
}
