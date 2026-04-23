<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One step inside an intake flow. Links the flow to an intake goal from
 * the library and captures the step's position + optional per-step
 * overrides and branching rules.
 *
 * Not tenant-scoped directly — ownership cascades through the parent flow,
 * which is team-scoped via {@see IntakeFlow}.
 */
class IntakeFlowStep extends Model
{
    protected $fillable = [
        'flow_id',
        'intake_goal_id',
        'position',
        'branches',
        'step_params',
        'is_required',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'branches' => 'array',
            'step_params' => 'array',
            'is_required' => 'boolean',
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
