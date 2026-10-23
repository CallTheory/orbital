<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\IntakeFlow;
use App\Models\IntakeFlowStep;
use App\Models\IntakeFlowTransition;
use App\Models\IntakeGoal;
use App\Models\Orchestration;
use App\Services\Flows\ChannelTriggerSeeder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A shared orchestration where the AI collects the client's intake
 * fields and then does one thing (subclasses pick what). The flow hangs
 * off the template's Inbound Phone channel, so the canvas reads the way
 * calls run, and Apply to client connects it to the client's own
 * Inbound Phone (ApplyOrchestrationTemplate).
 *
 * Idempotent. Re-running wires an existing copy of the template to its
 * Inbound Phone channel if it isn't yet, and otherwise leaves it alone.
 */
abstract class AiIntakeTemplateSeeder extends Seeder
{
    /** Exit name the canvas draws for a channel trigger's main port. */
    public const TRIGGER_EXIT = 'matched';

    /** Where the intake flow sits, under the Inbound Phone channel. */
    private const FLOW_X = 40;

    private const FLOW_Y = 260;

    public const DEFAULT_SLOTS = [
        ['name' => 'caller_name', 'label' => 'Caller name', 'type' => 'string', 'description' => 'The caller\'s full name.'],
        ['name' => 'callback_number', 'label' => 'Callback number', 'type' => 'phone', 'description' => 'A number to reach the caller on if the call drops.'],
        ['name' => 'reason', 'label' => 'Reason for calling', 'type' => 'string', 'description' => 'Why they are calling, in their own words.'],
    ];

    abstract protected function name(): string;

    abstract protected function description(): string;

    abstract protected function flowName(): string;

    abstract protected function flowDescription(): string;

    /**
     * The intake flow's steps after gather_intake_fields, as
     * [goal key, step params] pairs.
     *
     * @return array<int, array{0: string, 1: array<string, mixed>}>
     */
    abstract protected function followUpSteps(): array;

    public function run(): void
    {
        $goals = $this->goals();
        if ($goals === null) {
            return;
        }

        DB::transaction(function () use ($goals) {
            $orchestration = Orchestration::query()->withoutGlobalScope('team')
                ->whereNull('team_id')
                ->where('name', $this->name())
                ->first();

            if ($orchestration === null) {
                $orchestration = $this->create($goals);
            }

            $this->wireToInboundPhone($orchestration);
        });
    }

    /**
     * @param  Collection<string, IntakeGoal>  $goals
     */
    private function create(Collection $goals): Orchestration
    {
        $orchestration = Orchestration::query()->withoutGlobalScope('team')->forceCreate([
            'team_id' => null,
            'name' => $this->name(),
            'description' => $this->description(),
            'default_slots' => self::DEFAULT_SLOTS,
        ]);

        $flow = IntakeFlow::withoutGlobalScopes()->create([
            'team_id' => null,
            'orchestration_id' => $orchestration->id,
            'name' => $this->flowName(),
            'description' => $this->flowDescription(),
            'kind' => IntakeFlow::KIND_CALL_FLOW,
            'is_active' => true,
            'display_order' => 10,
            'canvas_x' => self::FLOW_X,
            'canvas_y' => self::FLOW_Y,
        ]);

        $steps = [['gather_intake_fields', ['prompt' => 'I just need a few details so we can help you.']], ...$this->followUpSteps()];
        foreach ($steps as $i => [$key, $params]) {
            IntakeFlowStep::create([
                'flow_id' => $flow->id,
                'intake_goal_id' => $goals[$key]->id,
                'position' => $i + 1,
                'step_params' => $params,
            ]);
        }

        return $orchestration;
    }

    /**
     * Plant the channel triggers and connect Inbound Phone to the intake
     * flow. Templates seeded before this existed had neither.
     */
    private function wireToInboundPhone(Orchestration $orchestration): void
    {
        app(ChannelTriggerSeeder::class)->ensureIn($orchestration);

        $flows = IntakeFlow::withoutGlobalScopes()->where('orchestration_id', $orchestration->id)->get();
        $inbound = $flows->firstWhere('trigger_type', IntakeFlow::TRIGGER_INBOUND_PHONE);
        $intake = $flows->first(fn (IntakeFlow $f) => ! in_array($f->trigger_type, IntakeFlow::CHANNEL_TRIGGERS, true));
        if ($inbound === null || $intake === null) {
            return;
        }

        if ($intake->canvas_x === null || $intake->canvas_y === null) {
            $intake->forceFill(['canvas_x' => self::FLOW_X, 'canvas_y' => self::FLOW_Y])->save();
        }

        $inbound->forceFill([
            'is_active' => true,
            'description' => $inbound->description ?? 'Calls to the client\'s numbers.',
        ])->save();

        IntakeFlowTransition::firstOrCreate(
            ['from_flow_id' => $inbound->id, 'to_flow_id' => $intake->id],
            ['priority' => 10, 'description' => self::TRIGGER_EXIT, 'condition' => null],
        );
    }

    /**
     * The goals this template's steps use, keyed by key, or null when
     * the goal library isn't seeded yet.
     *
     * @return Collection<string, IntakeGoal>|null
     */
    private function goals(): ?Collection
    {
        $keys = array_unique(['gather_intake_fields', ...array_column($this->followUpSteps(), 0)]);
        $goals = IntakeGoal::query()->whereIn('key', $keys)->get()->keyBy('key');

        return $goals->count() === count($keys) ? $goals : null;
    }
}
