<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A reactive rule attached to a flow (or a specific step within a
 * flow).
 *
 * Rules express side-effects decoupled from the main step chain.
 * Every rule has three pieces:
 *
 *   - trigger: when to check the rule (on_field_set, on_step_enter,
 *     on_step_complete, on_flow_start, on_flow_end)
 *   - condition: expression that must evaluate truthy for the rule to
 *     fire. Null = always fires on the trigger.
 *   - action_prompt: template the LLM reads when the rule fires.
 *     Template variables resolve against the live slots + context.
 *
 * Flow-scoped rules (step_id === null) fire for any step in the flow
 * that hits the trigger. Step-scoped rules (step_id set) only fire on
 * events originating in that step.
 */
class IntakeFlowRule extends Model
{
    public const TRIGGER_ON_FIELD_SET = 'on_field_set';

    public const TRIGGER_ON_STEP_ENTER = 'on_step_enter';

    public const TRIGGER_ON_STEP_COMPLETE = 'on_step_complete';

    public const TRIGGER_ON_FLOW_START = 'on_flow_start';

    public const TRIGGER_ON_FLOW_END = 'on_flow_end';

    public const TRIGGERS = [
        self::TRIGGER_ON_FIELD_SET,
        self::TRIGGER_ON_STEP_ENTER,
        self::TRIGGER_ON_STEP_COMPLETE,
        self::TRIGGER_ON_FLOW_START,
        self::TRIGGER_ON_FLOW_END,
    ];

    protected $fillable = [
        'flow_id',
        'step_id',
        'trigger_event',
        'label',
        'condition',
        'action_prompt',
        'priority',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'priority' => 'integer',
        ];
    }

    public function flow(): BelongsTo
    {
        return $this->belongsTo(IntakeFlow::class, 'flow_id');
    }

    public function step(): BelongsTo
    {
        return $this->belongsTo(IntakeFlowStep::class, 'step_id');
    }
}
