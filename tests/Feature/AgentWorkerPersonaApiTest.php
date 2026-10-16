<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AgentPersona;
use App\Models\Extension;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The worker fetches its persona with the chart's shared secret (not a
 * Sanctum token) and gets the AI provider keys saved in Platform
 * Settings. Nobody else gets the keys.
 */
class AgentWorkerPersonaApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.agent_worker.token' => 'worker-secret',
            'services.anthropic.api_key' => 'sk-ant-test',
            'services.elevenlabs.api_key' => 'el-test',
            'services.openai.api_key' => '',
            'services.openrouter.api_key' => '',
            'services.deepgram.api_key' => '',
        ]);

        $user = User::factory()->create();
        $team = Team::forceCreate(['user_id' => $user->id, 'name' => 'Acme', 'personal_team' => false]);
        $persona = AgentPersona::withoutGlobalScopes()->create(['team_id' => $team->id, 'name' => 'Ava', 'role' => 'receptionist']);
        Extension::withoutGlobalScopes()->create([
            'team_id' => $team->id, 'number' => '1001', 'type' => 'ai_agent',
            'assignable_type' => AgentPersona::class, 'assignable_id' => $persona->id, 'is_active' => true,
        ]);
    }

    public function test_worker_secret_gets_persona_and_provider_keys(): void
    {
        $this->getJson('/api/agent-personas/by-extension/1001', ['Authorization' => 'Bearer worker-secret'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Ava')
            ->assertJsonPath('provider_keys', ['anthropic' => 'sk-ant-test', 'elevenlabs' => 'el-test']);
    }

    public function test_wrong_secret_is_rejected(): void
    {
        $this->getJson('/api/agent-personas/by-extension/1001', ['Authorization' => 'Bearer nope'])
            ->assertUnauthorized();
    }

    public function test_sanctum_user_gets_persona_without_keys(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/agent-personas/by-extension/1001')
            ->assertOk()
            ->assertJsonPath('data.name', 'Ava')
            ->assertJsonPath('provider_keys', []);
    }
}
