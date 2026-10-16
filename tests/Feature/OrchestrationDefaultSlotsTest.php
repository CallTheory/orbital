<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ClientSlot;
use App\Models\Orchestration;
use App\Models\Team;
use App\Models\TeamApiKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrchestrationDefaultSlotsTest extends TestCase
{
    use RefreshDatabase;

    public function test_duplicate_creates_missing_default_slots_without_overwriting(): void
    {
        $team = Team::factory()->create();
        ClientSlot::create(['team_id' => $team->id, 'name' => 'caller_name', 'type' => 'string', 'label' => 'Mine']);

        $template = Orchestration::create([
            'team_id' => null,
            'name' => 'Template',
            'default_slots' => [
                ['name' => 'caller_name', 'label' => 'Name', 'type' => 'string'],
                ['name' => 'service', 'label' => 'Service', 'type' => 'choice', 'choices' => ['a', 'b'], 'description' => 'Which service'],
            ],
        ]);

        $template->duplicate($team);
        $template->duplicate($team); // idempotent

        $slots = ClientSlot::where('team_id', $team->id)->orderBy('id')->get();
        $this->assertCount(2, $slots);
        $this->assertSame('Mine', $slots[0]->label);
        $this->assertSame('choice', $slots[1]->type);
        $this->assertSame(['a', 'b'], $slots[1]->choices);
        $this->assertSame('Which service', $slots[1]->description);
    }

    public function test_api_key_command_prints_a_working_token(): void
    {
        $team = Team::factory()->create();

        $this->artisan('orbital:api-key', ['team' => (string) $team->id, 'name' => 'cli'])->assertSuccessful();
        $this->assertSame(1, TeamApiKey::where('team_id', $team->id)->count());

        $this->artisan('orbital:api-key', ['team' => 'No Such Client', 'name' => 'x'])->assertFailed();
    }
}
