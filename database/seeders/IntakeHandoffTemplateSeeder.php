<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\IntakeFlow;
use App\Models\IntakeFlowStep;
use App\Models\IntakeGoal;
use App\Models\Orchestration;
use Illuminate\Database\Seeder;

/**
 * The shared "AI intake → live operator handoff" orchestration.
 *
 * The AI answers, collects every intake field the client defines, then
 * hands the caller to the client's outside operator, whose system fetches
 * the fields through the handoff API (GET /api/v1/handoffs/lookup).
 * Applied to a client from Orchestrations → Apply to client, which copies
 * it with its default fields and sets the client's handoff number.
 *
 * Idempotent: does nothing if the template already exists.
 */
class IntakeHandoffTemplateSeeder extends Seeder
{
    public const NAME = 'AI Intake → Live Operator Handoff';

    public function run(): void
    {
        $exists = Orchestration::query()->withoutGlobalScope('team')
            ->whereNull('team_id')
            ->where('name', self::NAME)
            ->exists();
        $gather = IntakeGoal::query()->where('key', 'gather_intake_fields')->first();
        $transfer = IntakeGoal::query()->where('key', 'transfer_call')->first();

        if ($exists || ! $gather || ! $transfer) {
            return;
        }

        $orchestration = Orchestration::query()->withoutGlobalScope('team')->forceCreate([
            'team_id' => null,
            'name' => self::NAME,
            'description' => 'The AI answers, collects the client\'s intake fields, then transfers the caller to the client\'s live operator, whose system fetches the fields through the handoff API.',
            'default_slots' => [
                ['name' => 'caller_name', 'label' => 'Caller name', 'type' => 'string', 'description' => 'The caller\'s full name.'],
                ['name' => 'callback_number', 'label' => 'Callback number', 'type' => 'phone', 'description' => 'A number to reach the caller on if the call drops.'],
                ['name' => 'reason', 'label' => 'Reason for calling', 'type' => 'string', 'description' => 'Why they are calling, in their own words.'],
            ],
        ]);

        $flow = IntakeFlow::withoutGlobalScopes()->create([
            'team_id' => null,
            'orchestration_id' => $orchestration->id,
            'name' => 'Intake & handoff',
            'description' => 'Collect the intake fields, then hand off to the live operator.',
            'trigger_type' => 'persona_default',
            'kind' => IntakeFlow::KIND_CALL_FLOW,
            'is_active' => true,
            'display_order' => 1,
        ]);

        IntakeFlowStep::create([
            'flow_id' => $flow->id,
            'intake_goal_id' => $gather->id,
            'position' => 1,
            'step_params' => ['prompt' => 'I just need a few details so the operator can help you.'],
        ]);
        IntakeFlowStep::create([
            'flow_id' => $flow->id,
            'intake_goal_id' => $transfer->id,
            'position' => 2,
            // Each client sets its own number when the template is applied.
            'step_params' => ['destination_type' => 'external', 'destination' => '', 'mode' => 'cold'],
        ]);
    }
}
