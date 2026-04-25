<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTeam;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A named bundle of intake_flows — the whole canvas an author edits
 * in the flow editor, addressable as one object.
 *
 * Lifecycle is intentionally simple:
 *   - status=draft   → editable; not selectable on channel_assignments
 *   - status=active  → editable AND selectable
 * There is no archive. Deletes are hard; cascades wipe the whole
 * subtree (flows, steps, transitions, rules). Any
 * `client_channel_assignments` row that referenced the graph gets
 * its `flow_graph_id` nulled (channel goes inert until reassigned).
 */
class FlowGraph extends Model
{
    use BelongsToTeam;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_ACTIVE,
    ];

    protected $fillable = [
        'team_id',
        'name',
        'description',
        'status',
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function flows(): HasMany
    {
        return $this->hasMany(IntakeFlow::class, 'flow_graph_id');
    }

    public function channelAssignments(): HasMany
    {
        return $this->hasMany(ClientChannelAssignment::class, 'flow_graph_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * Deep-clone this graph: copies flows + steps + transitions +
     * rules into a new FlowGraph. Slots remain team-scoped (shared,
     * not cloned). The returned graph starts as `draft` so authors
     * can iterate without affecting active assignments.
     *
     * The name collision is avoided by appending " (copy)" — if that
     * already exists, keep appending numbers.
     */
    public function duplicate(): self
    {
        return \DB::transaction(function () {
            $newName = $this->name.' (copy)';
            $n = 2;
            while (self::where('team_id', $this->team_id)->where('name', $newName)->exists()) {
                $newName = $this->name." (copy {$n})";
                $n++;
            }

            $copy = self::create([
                'team_id' => $this->team_id,
                'name' => $newName,
                'description' => $this->description,
                'status' => self::STATUS_DRAFT,
            ]);

            // client_id → new flow id, so we can rewire transitions
            // that point between cloned flows.
            $idMap = [];
            foreach ($this->flows()->with(['steps', 'rules'])->get() as $flow) {
                $newFlow = IntakeFlow::create([
                    'team_id' => $copy->team_id,
                    'flow_graph_id' => $copy->id,
                    'name' => $flow->name,
                    'description' => $flow->description,
                    'trigger_type' => $flow->trigger_type,
                    'kind' => $flow->kind,
                    'is_active' => $flow->is_active,
                    'display_order' => $flow->display_order,
                    'canvas_x' => $flow->canvas_x,
                    'canvas_y' => $flow->canvas_y,
                ]);
                $idMap[$flow->id] = $newFlow->id;

                foreach ($flow->steps as $step) {
                    IntakeFlowStep::create([
                        'flow_id' => $newFlow->id,
                        'intake_goal_id' => $step->intake_goal_id,
                        'position' => $step->position,
                        'step_params' => $step->step_params,
                    ]);
                }

                foreach ($flow->rules as $rule) {
                    IntakeFlowRule::create([
                        'flow_id' => $newFlow->id,
                        'step_id' => null, // step_id references old step ids; leave unscoped on copy
                        'trigger_event' => $rule->trigger_event,
                        'label' => $rule->label,
                        'condition' => $rule->condition,
                        'action_prompt' => $rule->action_prompt,
                        'priority' => $rule->priority,
                        'is_active' => $rule->is_active,
                    ]);
                }
            }

            // Transitions: rewire from/to by the id map. Transitions
            // that point outside the graph (to_flow_id null = ends
            // call) keep their null target.
            foreach ($this->flows as $flow) {
                foreach ($flow->transitionsOut as $t) {
                    IntakeFlowTransition::create([
                        'from_flow_id' => $idMap[$t->from_flow_id] ?? null,
                        'to_flow_id' => $t->to_flow_id ? ($idMap[$t->to_flow_id] ?? null) : null,
                        'condition' => $t->condition,
                        'description' => $t->description,
                        'priority' => $t->priority,
                        'source_handle' => $t->source_handle,
                    ]);
                }
            }

            return $copy->fresh();
        });
    }
}
