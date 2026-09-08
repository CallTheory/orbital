<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A single structured objective inside a call flow.
 *
 * Intake goals are a flat, platform-wide catalog — the vocabulary of
 * "things an AI agent can do" that flows compose into ordered scripts.
 * There's no per-client scoping: every tenant picks from the same
 * library, and any tenant-specific nuance lives on the flow step
 * itself (`intake_flow_steps.step_params`), not on a duplicated
 * goal row.
 *
 * Intake goals are read by three compilers (AI voice, operator UI,
 * chat) that each render the same structured fields — talking_points,
 * data_fields, completion, tools — into their native form.
 */
class IntakeGoal extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'key',
        'name',
        'description',
        'category',
        'icon',
        'talking_points',
        'data_fields',
        'completion',
        'tools',
        'knowledge_store_ids',
        'max_transitions',
        'exits',
        'voice_overrides',
        'operator_overrides',
        'chat_overrides',
        'is_active',
        'overrides',
        'keep_partial_messages',
    ];

    protected function casts(): array
    {
        return [
            'talking_points' => 'array',
            'data_fields' => 'array',
            'completion' => 'array',
            'tools' => 'array',
            'knowledge_store_ids' => 'array',
            'exits' => 'array',
            'voice_overrides' => 'array',
            'operator_overrides' => 'array',
            'chat_overrides' => 'array',
            'overrides' => 'array',
            'is_active' => 'boolean',
            // Three-state on purpose: null inherits the client's
            // setting, true/false override it. The boolean cast passes
            // null straight through, so the distinction survives — but
            // never add a default to the column, or every new goal
            // silently opts out of a client-wide policy.
            'keep_partial_messages' => 'boolean',
        ];
    }

    /**
     * Return the goal's attributes as a plain array. Kept for API
     * parity with other flow-compilable models that used to inherit
     * template + override merging; for intake goals there are no
     * templates to walk, so this is just the row itself.
     *
     * @return array<string, mixed>
     */
    public function effective(): array
    {
        return $this->attributesToArray();
    }

    /**
     * Convenience accessor used by the flow compiler. No template
     * chain to resolve anymore, so it just reads the field directly.
     */
    public function effectiveField(string $field): mixed
    {
        return $this->{$field};
    }
}
