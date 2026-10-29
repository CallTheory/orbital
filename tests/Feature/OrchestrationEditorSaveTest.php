<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\IntakeFlow;
use App\Models\IntakeFlowTransition;
use App\Models\IntakeGoal;
use App\Models\Orchestration;
use App\Models\Team;
use Database\Seeders\PermissionCatalogSeeder;
use Database\Seeders\SuperAdminRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesOrbitalUsers;
use Tests\TestCase;

/**
 * Saving the flow editor: connections to flows created in the same
 * save keep their target instead of turning into "end the call".
 */
class OrchestrationEditorSaveTest extends TestCase
{
    use CreatesOrbitalUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionCatalogSeeder::class);
        $this->seed(SuperAdminRoleSeeder::class);
        IntakeGoal::firstOrCreate(['key' => 'save_message'], ['name' => 'Save Message']);
    }

    /** @return array{0: Orchestration, 1: IntakeFlow} */
    private function orchestrationWithIntake(): array
    {
        $team = Team::factory()->create();
        $orchestration = Orchestration::withoutGlobalScopes()->forceCreate(['team_id' => $team->id, 'name' => 'Default']);
        $intake = IntakeFlow::withoutGlobalScopes()->create([
            'team_id' => $team->id, 'orchestration_id' => $orchestration->id, 'name' => 'Intake & message',
            'trigger_type' => 'persona_default', 'kind' => IntakeFlow::KIND_CALL_FLOW, 'is_active' => true,
        ]);

        return [$orchestration, $intake];
    }

    /** @param  array<int, array<string, mixed>>  $transitions */
    private function save(Orchestration $orchestration, IntakeFlow $intake, array $transitions)
    {
        return $this->actingAs($this->makeUserWithTeamlessRole('super_admin'))
            ->putJson("/api/admin/orchestrations/{$orchestration->id}", [
                'slots' => [],
                'flows' => [
                    ['id' => $intake->id, 'client_id' => (string) $intake->id, 'name' => $intake->name, 'trigger_type' => 'persona_default', 'kind' => 'call_flow', 'steps' => [
                        ['id' => null, 'intake_goal_key' => 'save_message', 'position' => 0, 'step_params' => []],
                    ]],
                    ['id' => null, 'client_id' => 'flow-1', 'name' => 'Call Completed', 'trigger_type' => 'subflow', 'kind' => 'call_flow', 'steps' => []],
                ],
                'transitions' => $transitions,
            ]);
    }

    public function test_a_connection_to_a_flow_added_in_the_same_save_keeps_its_target(): void
    {
        [$orchestration, $intake] = $this->orchestrationWithIntake();

        $this->save($orchestration, $intake, [
            ['id' => null, 'from_flow_client_id' => (string) $intake->id, 'to_flow_client_id' => 'flow-1', 'description' => 'continue', 'priority' => 10],
        ])->assertOk();

        $completed = IntakeFlow::withoutGlobalScopes()->where('orchestration_id', $orchestration->id)->where('name', 'Call Completed')->sole();
        $this->assertSame($completed->id, IntakeFlowTransition::where('from_flow_id', $intake->id)->sole()->to_flow_id);
    }

    public function test_a_connection_to_an_unknown_flow_is_refused_rather_than_ending_the_call(): void
    {
        [$orchestration, $intake] = $this->orchestrationWithIntake();

        $this->save($orchestration, $intake, [
            ['id' => null, 'from_flow_client_id' => (string) $intake->id, 'to_flow_client_id' => 'flow-99', 'description' => 'continue', 'priority' => 10],
        ])->assertUnprocessable()->assertJsonValidationErrors('transitions');

        $this->assertSame(0, IntakeFlowTransition::where('from_flow_id', $intake->id)->count());
        $this->assertSame(0, IntakeFlow::withoutGlobalScopes()->where('name', 'Call Completed')->count(), 'nothing from the refused save is kept');
    }
}
