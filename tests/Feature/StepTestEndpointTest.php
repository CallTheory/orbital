<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Orchestration;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\PermissionCatalogSeeder;
use Database\Seeders\SuperAdminRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesOrbitalUsers;
use Tests\TestCase;

/**
 * The step editor's Test button runs an email / web-call step as
 * configured, with the sample values the author typed.
 */
class StepTestEndpointTest extends TestCase
{
    use CreatesOrbitalUsers;
    use RefreshDatabase;

    private Orchestration $orchestration;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionCatalogSeeder::class);
        $this->seed(SuperAdminRoleSeeder::class);
        config(['mail.default' => 'array']);
        $this->orchestration = Orchestration::withoutGlobalScopes()->forceCreate(['team_id' => Team::factory()->create()->id, 'name' => 'Default']);
    }

    /** @param  array<string, mixed>  $payload */
    private function test(array $payload, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->makeUserWithTeamlessRole('super_admin'))
            ->postJson("/api/admin/orchestrations/{$this->orchestration->id}/test-step", $payload);
    }

    public function test_an_email_step_is_sent_with_the_sample_values_and_marked_as_a_test(): void
    {
        $this->test([
            'intake_goal_key' => 'send_email',
            'step_params' => ['recipient' => 'support@calltheory.com', 'subject' => 'Message from {{ caller_name }}', 'body' => 'Reason: {{ reason }}'],
            'fields' => ['caller_name' => 'Test Caller', 'reason' => 'Checking'],
        ])->assertOk()->assertJson([
            'ok' => true,
            'preview' => ['to' => ['support@calltheory.com'], 'subject' => '[Test] Message from Test Caller', 'body' => 'Reason: Checking'],
        ]);

        $sent = app('mailer')->getSymfonyTransport()->messages();
        $this->assertCount(1, $sent);
        $this->assertSame('[Test] Message from Test Caller', $sent->first()->getOriginalMessage()->getSubject());
    }

    public function test_a_web_call_step_reports_what_it_sent_and_what_came_back(): void
    {
        Http::fake(['hooks.example.com/*' => Http::response('{"accepted":true}', 202)]);

        $this->test([
            'intake_goal_key' => 'web_call',
            'step_params' => ['method' => 'POST', 'path' => 'https://hooks.example.com/in', 'body_template' => '{"name": "{{ caller_name }}"}', 'expected_status_codes' => '200'],
            'fields' => ['caller_name' => 'Test Caller'],
        ])->assertOk()->assertJson([
            'ok' => false,
            'detail' => 'POST https://hooks.example.com/in returned HTTP 202',
            'preview' => ['method' => 'POST', 'body' => '{"name": "Test Caller"}', 'response' => ['status' => 202, 'body' => '{"accepted":true}']],
        ]);

        Http::assertSent(fn (Request $r) => $r->data() === ['name' => 'Test Caller']);
    }

    public function test_only_super_admins_can_run_tests_and_only_on_post_call_steps(): void
    {
        $this->test(['intake_goal_key' => 'send_email', 'step_params' => []], User::factory()->create())->assertForbidden();
        $this->test(['intake_goal_key' => 'transfer_call', 'step_params' => []])->assertUnprocessable();
        $this->assertCount(0, app('mailer')->getSymfonyTransport()->messages());
    }
}
