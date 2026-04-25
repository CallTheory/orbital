<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A directed edge from one intake flow to another (or to call-end).
 *
 * `condition` is a JsonLogic tree. If it's null, the transition is
 * an unconditional fallback — it always matches. Conditions are
 * evaluated in `priority` order (lower first) and the first match
 * wins.
 *
 * `to_flow_id` = null means "end the call." The renderer emits a
 * clear "end the call politely" line in the LLM prompt; the operator
 * UI treats it as a terminal state.
 *
 * `source_handle` is a placeholder for future per-output-port
 * transitions (if we later give flows multiple named exits like
 * "on_success" / "on_timeout"). Unused in v1 and nullable.
 */
class IntakeFlowTransition extends Model
{
    protected $fillable = [
        'from_flow_id',
        'to_flow_id',
        'condition',
        'description',
        'priority',
        'source_handle',
    ];

    protected function casts(): array
    {
        return [
            'condition' => 'array',
            'priority' => 'integer',
        ];
    }

    public function fromFlow(): BelongsTo
    {
        return $this->belongsTo(IntakeFlow::class, 'from_flow_id');
    }

    public function toFlow(): BelongsTo
    {
        return $this->belongsTo(IntakeFlow::class, 'to_flow_id');
    }

    public function isFallback(): bool
    {
        return $this->condition === null || $this->condition === [];
    }

    public function endsCall(): bool
    {
        return $this->to_flow_id === null;
    }
}
