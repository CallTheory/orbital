<?php

declare(strict_types=1);

namespace App\Services\Flows;

use App\Models\AgentPersona;
use App\Models\IntakeFlow;
use App\Models\IntakeFlowStep;
use App\Models\Orchestration;
use App\Models\Team;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\DB;

/**
 * Applies a shared orchestration to a client in one go: copies it (and
 * its default intake fields) into the client, sets the client's handoff
 * number on its external Transfer Call steps, and points the client's AI
 * persona at the copy's entry flow so calls to that persona's extension
 * run it.
 */
class ApplyOrchestrationTemplate
{
    public function apply(Orchestration $template, Team $team, ?AgentPersona $persona = null, ?string $handoffNumber = null): Orchestration
    {
        return DB::transaction(function () use ($template, $team, $persona, $handoffNumber) {
            $copy = $template->duplicate($team);
            $copy->forceFill(['name' => $template->name])->save();

            $flows = IntakeFlow::withoutGlobalScopes()
                ->where('orchestration_id', $copy->id)
                ->orderBy('display_order')
                ->orderBy('id')
                ->get();

            $number = PhoneNumber::toE164($handoffNumber);
            if ($number !== null) {
                IntakeFlowStep::query()
                    ->whereIn('flow_id', $flows->pluck('id'))
                    ->whereHas('intakeGoal', fn ($q) => $q->where('key', 'transfer_call'))
                    ->get()
                    ->filter(fn (IntakeFlowStep $step) => ($step->step_params['destination_type'] ?? null) === 'external')
                    ->each(fn (IntakeFlowStep $step) => $step->update([
                        'step_params' => ['destination' => $number] + ($step->step_params ?? []),
                    ]));
            }

            $entry = $flows->firstWhere('kind', IntakeFlow::KIND_CALL_FLOW) ?? $flows->first();
            if ($persona && $entry) {
                $persona->forceFill(['default_flow_id' => $entry->id])->save();
            }

            return $copy->fresh();
        });
    }
}
