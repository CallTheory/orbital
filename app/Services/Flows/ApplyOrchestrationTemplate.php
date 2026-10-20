<?php

declare(strict_types=1);

namespace App\Services\Flows;

use App\Models\AgentPersona;
use App\Models\IntakeFlow;
use App\Models\IntakeFlowStep;
use App\Models\IntakeFlowTransition;
use App\Models\Orchestration;
use App\Models\Team;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\DB;

/**
 * Applies a shared orchestration to a client in one go: adds its flows
 * (and default intake fields) to the client's Default orchestration,
 * connects them to the client's Inbound Phone channel the way the
 * template connects them to its own, sets the client's handoff number on
 * external Transfer Call steps, and points the client's AI persona at
 * the added entry flow so calls to that persona's extension run it.
 *
 * Applying again adds a fresh set and moves the Inbound Phone connection
 * to it. Earlier flows stay on the canvas, disconnected, for the author
 * to delete.
 */
class ApplyOrchestrationTemplate
{
    /** Vertical gap between what's on the canvas and the added flows. */
    private const CANVAS_GAP = 220;

    public function __construct(private readonly ChannelTriggerSeeder $triggers) {}

    public function apply(Orchestration $template, Team $team, ?AgentPersona $persona = null, ?string $handoffNumber = null): Orchestration
    {
        return DB::transaction(function () use ($template, $team, $persona, $handoffNumber) {
            $target = $this->triggers->ensureBootstrap($team);
            $this->triggers->ensureIn($target);

            $idMap = $template->copyFlowsInto($target, $this->offsetBelowExisting($template, $target));
            $added = IntakeFlow::withoutGlobalScope('team')->whereIn('id', $idMap)->orderBy('display_order')->orderBy('id')->get();

            $this->setHandoffNumber($added->pluck('id')->all(), $handoffNumber);
            $entry = $this->connectTriggers($template, $target, $idMap) ?? $added->firstWhere('kind', IntakeFlow::KIND_CALL_FLOW) ?? $added->first();

            if ($persona && $entry) {
                $persona->forceFill(['default_flow_id' => $entry->id])->save();
            }

            return $target->fresh();
        });
    }

    /**
     * For each channel the template connects to one of its flows,
     * connect the client's matching channel to the copy instead, in place
     * of whatever that channel's port led to before. Returns the flow the
     * Inbound Phone channel now leads to.
     *
     * @param  array<int, int>  $idMap
     */
    private function connectTriggers(Orchestration $template, Orchestration $target, array $idMap): ?IntakeFlow
    {
        $templateTriggers = IntakeFlow::withoutGlobalScope('team')
            ->where('orchestration_id', $template->id)
            ->whereIn('trigger_type', IntakeFlow::CHANNEL_TRIGGERS)
            ->with('transitionsOut')
            ->get();
        $targetTriggers = IntakeFlow::withoutGlobalScope('team')
            ->where('orchestration_id', $target->id)
            ->whereIn('trigger_type', IntakeFlow::CHANNEL_TRIGGERS)
            ->get()
            ->keyBy('trigger_type');

        $inboundEntry = null;
        foreach ($templateTriggers as $trigger) {
            $own = $targetTriggers[$trigger->trigger_type] ?? null;
            $links = $trigger->transitionsOut->filter(fn (IntakeFlowTransition $t) => isset($idMap[$t->to_flow_id]));
            if ($own === null || $links->isEmpty()) {
                continue;
            }

            // One flow per port: the template's wiring replaces the
            // client's on the ports it uses.
            IntakeFlowTransition::query()
                ->where('from_flow_id', $own->id)
                ->whereIn('description', $links->pluck('description')->all())
                ->delete();

            foreach ($links as $t) {
                IntakeFlowTransition::create([
                    'from_flow_id' => $own->id,
                    'to_flow_id' => $idMap[$t->to_flow_id],
                    'condition' => $t->condition,
                    'description' => $t->description,
                    'priority' => $t->priority,
                    'source_handle' => $t->source_handle,
                ]);
            }
            $own->forceFill(['is_active' => true])->save();

            if ($trigger->trigger_type === IntakeFlow::TRIGGER_INBOUND_PHONE) {
                $inboundEntry = IntakeFlow::withoutGlobalScope('team')->find($idMap[$links->sortBy('priority')->first()->to_flow_id]);
            }
        }

        return $inboundEntry;
    }

    /**
     * How far to move the template's flows down so they sit below every
     * flow already in the target (besides the channel row at the top).
     */
    private function offsetBelowExisting(Orchestration $template, Orchestration $target): int
    {
        $existingBottom = IntakeFlow::withoutGlobalScope('team')
            ->where('orchestration_id', $target->id)
            ->whereNotIn('trigger_type', IntakeFlow::CHANNEL_TRIGGERS)
            ->max('canvas_y');
        if ($existingBottom === null) {
            return 0;
        }

        $templateTop = IntakeFlow::withoutGlobalScope('team')
            ->where('orchestration_id', $template->id)
            ->whereNotIn('trigger_type', IntakeFlow::CHANNEL_TRIGGERS)
            ->min('canvas_y') ?? 0;

        return max(0, (int) $existingBottom + self::CANVAS_GAP - (int) $templateTop);
    }

    /**
     * @param  array<int, int>  $flowIds
     */
    private function setHandoffNumber(array $flowIds, ?string $handoffNumber): void
    {
        $number = PhoneNumber::toE164($handoffNumber);
        if ($number === null) {
            return;
        }

        IntakeFlowStep::query()
            ->whereIn('flow_id', $flowIds)
            ->whereHas('intakeGoal', fn ($q) => $q->where('key', 'transfer_call'))
            ->get()
            ->filter(fn (IntakeFlowStep $step) => ($step->step_params['destination_type'] ?? null) === 'external')
            ->each(fn (IntakeFlowStep $step) => $step->update([
                'step_params' => ['destination' => $number] + ($step->step_params ?? []),
            ]));
    }
}
