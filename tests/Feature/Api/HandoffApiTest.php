<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\CallSessionState;
use App\Models\ClientSlot;
use App\Models\Team;
use App\Models\TeamApiKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HandoffApiTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private string $token;

    private TeamApiKey $key;

    protected function setUp(): void
    {
        parent::setUp();
        $this->team = Team::factory()->create();
        [$this->key, $this->token] = TeamApiKey::issue($this->team, 'test');
    }

    private function makeSession(array $attrs = [], ?Team $team = null): CallSessionState
    {
        return CallSessionState::create([
            'session_key' => 'room-'.uniqid(),
            'team_id' => ($team ?? $this->team)->id,
            'caller_number' => '+15551234567',
            'called_number' => '+15559990000',
            'handoff_number' => '+15558880000',
            'handed_off_at' => now()->subMinutes(2),
            'fields' => [],
            ...$attrs,
        ]);
    }

    private function api(string $url, ?string $token = null)
    {
        return $this->getJson($url, ['Authorization' => 'Bearer '.($token ?? $this->token)]);
    }

    public function test_requires_a_valid_key(): void
    {
        $this->getJson('/api/v1/handoffs/lookup?phone=5551234567')
            ->assertStatus(401)->assertExactJson(['message' => 'Unauthenticated.']);
        $this->api('/api/v1/handoffs/lookup?phone=5551234567', 'orb_nope')->assertStatus(401);

        $this->key->revoke();
        $this->api('/api/v1/handoffs/lookup?phone=5551234567')->assertStatus(401);
    }

    public function test_key_is_stored_hashed_and_last_used_is_updated(): void
    {
        $this->assertStringStartsWith('orb_', $this->token);
        $this->assertSame(44, strlen($this->token));
        $this->assertNotSame($this->token, $this->key->token_hash);

        $this->makeSession();
        $this->api('/api/v1/handoffs/lookup?phone=5551234567')->assertOk();
        $this->assertNotNull($this->key->fresh()->last_used_at);
    }

    public function test_phone_variants_match(): void
    {
        $s = $this->makeSession();

        foreach (['5551234567', '(555) 123-4567', '1-555-123-4567', '+15551234567'] as $phone) {
            $this->api('/api/v1/handoffs/lookup?phone='.urlencode($phone))
                ->assertOk()->assertJsonPath('data.id', $s->id);
        }
    }

    public function test_team_isolation(): void
    {
        $other = Team::factory()->create();
        $theirs = $this->makeSession([], $other);

        $this->api('/api/v1/handoffs/lookup?phone=5551234567')->assertNotFound()
            ->assertExactJson(['message' => 'No handed-off call found for that number.']);
        $this->api('/api/v1/handoffs/'.$theirs->id)->assertNotFound();
    }

    public function test_window_and_not_handed_off(): void
    {
        $this->makeSession(['handed_off_at' => now()->subMinutes(45)]);
        $this->api('/api/v1/handoffs/lookup?phone=5551234567')->assertNotFound();
        $this->api('/api/v1/handoffs/lookup?phone=5551234567&within=60')->assertOk();
        $this->api('/api/v1/handoffs/lookup?phone=5551234567&within=5000')->assertStatus(422);

        CallSessionState::query()->delete();
        $this->makeSession(['handed_off_at' => null]);
        $this->api('/api/v1/handoffs/lookup?phone=5551234567')->assertNotFound();
    }

    public function test_most_recent_wins(): void
    {
        $this->makeSession(['handed_off_at' => now()->subMinutes(10)]);
        $newer = $this->makeSession(['handed_off_at' => now()->subMinute()]);

        $this->api('/api/v1/handoffs/lookup?phone=5551234567')->assertJsonPath('data.id', $newer->id);
    }

    public function test_payload_shape_labels_and_other_fields(): void
    {
        ClientSlot::create(['team_id' => $this->team->id, 'name' => 'caller_name', 'type' => 'string']);
        ClientSlot::create(['team_id' => $this->team->id, 'name' => 'pickup_zip', 'label' => 'Pickup ZIP', 'type' => 'string']);
        ClientSlot::create(['team_id' => $this->team->id, 'name' => 'notes', 'type' => 'string']);
        $s = $this->makeSession(['fields' => ['caller_name' => 'Pat', 'pickup_zip' => '90210', 'surprise' => 'x']]);

        $this->api('/api/v1/handoffs/'.$s->id)
            ->assertOk()
            ->assertJsonPath('data.caller_number', '+15551234567')
            ->assertJsonPath('data.called_number', '+15559990000')
            ->assertJsonPath('data.handoff_number', '+15558880000')
            ->assertJsonPath('data.fields.0', ['key' => 'caller_name', 'label' => 'Caller Name', 'type' => 'string', 'value' => 'Pat'])
            ->assertJsonPath('data.fields.1.label', 'Pickup ZIP')
            ->assertJsonPath('data.fields.2.value', null)
            ->assertJsonPath('data.other_fields', ['surprise' => 'x'])
            ->assertJsonStructure(['data' => ['handed_off_at', 'started_at', 'ended_at']]);
    }

    public function test_empty_other_fields_is_an_object(): void
    {
        $s = $this->makeSession();
        $this->assertStringContainsString('"other_fields":{}', $this->api('/api/v1/handoffs/'.$s->id)->getContent());
    }

    public function test_unknown_id_and_missing_phone(): void
    {
        $this->api('/api/v1/handoffs/999999')->assertNotFound();
        $this->api('/api/v1/handoffs/lookup')->assertStatus(422);
    }
}
