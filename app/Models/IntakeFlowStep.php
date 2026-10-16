<?php

declare(strict_types=1);

namespace App\Models;

use App\Jobs\RegenerateTelephonyConfig;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One primitive placement inside an intake flow.
 *
 * Each step links an intake-goal primitive (from the library) to a
 * specific parent flow, at a specific position, with a JSON bag of
 * `step_params` that configure this placement (which slot to write,
 * which knowledge stores to search, etc.).
 *
 * Branching between flows lives on `intake_flow_transitions`, not
 * here — steps are purely sequential within a flow.
 */
class IntakeFlowStep extends Model
{
    protected $fillable = [
        'flow_id',
        'intake_goal_id',
        'position',
        'step_params',
    ];

    protected static function booted(): void
    {
        // A Transfer Call step can carry an outside handoff number, which
        // only becomes dialable once it's in the from-livekit dialplan.
        $regenerate = function (IntakeFlowStep $step): void {
            if ($step->intakeGoal()->value('key') !== 'transfer_call') {
                return;
            }
            $teamId = IntakeFlow::withoutGlobalScopes()->whereKey($step->flow_id)->value('team_id');
            if ($teamId) {
                RegenerateTelephonyConfig::dispatch((int) $teamId);
            }
        };

        static::saved($regenerate);
        static::deleted($regenerate);
    }

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'step_params' => 'array',
        ];
    }

    public function flow(): BelongsTo
    {
        return $this->belongsTo(IntakeFlow::class, 'flow_id');
    }

    public function intakeGoal(): BelongsTo
    {
        return $this->belongsTo(IntakeGoal::class);
    }
}
