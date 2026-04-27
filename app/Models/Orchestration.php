<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTeam;
use App\Services\Flows\BindingResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * A named bundle of intake_flows — the whole canvas an author edits
 * in the flow editor, addressable as one object.
 *
 * Two flavors live in the same table:
 *   - `team_id IS NOT NULL` — a per-client orchestration. Authored,
 *     assigned, and edited within the owning client's scope.
 *   - `team_id IS NULL` — a platform-shared orchestration. Authored
 *     by super-admins and assignable to many clients. Each assigning
 *     client owns its own `orchestration_bindings` rows that resolve
 *     the orchestration's external resource references (personas,
 *     queues, etc.) against that client's resources at runtime.
 *
 * Lifecycle is intentionally simple: no manual draft/active column.
 * An orchestration is "active" in the UI when at least one queue
 * (`call_queues.orchestration_id` or `email_queues.orchestration_id`)
 * points at it. Authors don't toggle status by hand; assigning to a
 * queue IS the promotion gesture.
 *
 * Deletes are hard. Cascades wipe the whole subtree (flows, steps,
 * transitions, rules, bindings). Any queue that referenced the
 * orchestration has its `orchestration_id` set null (channel goes
 * inert until reassigned).
 */
class Orchestration extends Model
{
    use BelongsToTeam;

    protected $fillable = [
        'team_id',
        'name',
        'description',
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function flows(): HasMany
    {
        return $this->hasMany(IntakeFlow::class, 'orchestration_id');
    }

    public function callQueues(): HasMany
    {
        return $this->hasMany(CallQueue::class, 'orchestration_id');
    }

    public function emailQueues(): HasMany
    {
        return $this->hasMany(EmailQueue::class, 'orchestration_id');
    }

    public function bindings(): HasMany
    {
        return $this->hasMany(OrchestrationBinding::class);
    }

    /**
     * Derived: "active" when at least one queue points at this row.
     */
    public function isActive(): bool
    {
        return $this->callQueues()->exists() || $this->emailQueues()->exists();
    }

    /**
     * Platform-shared orchestrations have no owning team.
     */
    public function isShared(): bool
    {
        return $this->team_id === null;
    }

    /**
     * The unique set of (binding_key, resource_type) pairs declared by
     * this orchestration's step_params. Computed from the canvas — any
     * step_params field whose data_field is a picker type and whose
     * value is a non-empty string surfaces here.
     *
     * Used by per-client queue forms to render the "fill in your
     * resources for this orchestration" UI when a shared orchestration
     * is assigned.
     *
     * @return array<int, array{binding_key: string, resource_type: string}>
     */
    public function bindingDefinitions(): array
    {
        $picker = BindingResolver::PICKER_TYPE_TO_RESOURCE;
        $byKey = [];

        $steps = IntakeFlowStep::query()
            ->whereHas('flow', fn ($q) => $q->where('orchestration_id', $this->id))
            ->with('intakeGoal')
            ->get();

        foreach ($steps as $step) {
            $params = $step->step_params ?? [];
            foreach ($step->intakeGoal?->data_fields ?? [] as $field) {
                $type = $field['type'] ?? null;
                $key = $field['key'] ?? null;
                if (! $type || ! $key || ! isset($picker[$type])) {
                    continue;
                }
                $val = $params[$key] ?? null;
                if (! is_string($val) || $val === '') {
                    continue;
                }
                $byKey[$val] = [
                    'binding_key' => $val,
                    'resource_type' => $picker[$type],
                ];
            }
        }

        return array_values($byKey);
    }

    /**
     * Deep-clone this orchestration: copies flows + steps +
     * transitions + rules + bindings. Slots remain team-scoped
     * (shared, not cloned). The returned orchestration has no queue
     * assignments — it's a draft until an author assigns it.
     *
     * Optional `$targetTeam` reparents the clone into a specific
     * client's scope. Useful for "Duplicate this platform orchestration
     * into Client A" — the resulting copy is a private orchestration
     * the client owns and can edit.
     *
     * When duplicating into the same team, source binding rows clone
     * 1:1 (concrete picker values come along). When duplicating into
     * a different team we prefer the target team's existing binding
     * for the same key (so a client cloning a platform orchestration
     * inherits prior mappings if any). Missing keys land as
     * placeholder rows with `resource_id = null` so the next
     * "Bindings" modal flags them.
     *
     * Name collisions resolve by appending " (copy)", " (copy 2)",
     * etc. until a free slot exists in the target team's namespace.
     */
    public function duplicate(?Team $targetTeam = null): self
    {
        return DB::transaction(function () use ($targetTeam) {
            $targetTeamId = $targetTeam?->id ?? $this->team_id;

            $newName = $this->name.' (copy)';
            $n = 2;
            while (self::query()
                ->withoutGlobalScope('team')
                ->where(fn ($q) => $targetTeamId === null
                    ? $q->whereNull('team_id')
                    : $q->where('team_id', $targetTeamId))
                ->where('name', $newName)
                ->exists()
            ) {
                $newName = $this->name." (copy {$n})";
                $n++;
            }

            $copy = self::create([
                'team_id' => $targetTeamId,
                'name' => $newName,
                'description' => $this->description,
            ]);

            $idMap = [];
            foreach ($this->flows()->with(['steps', 'rules'])->get() as $flow) {
                $newFlow = IntakeFlow::create([
                    'team_id' => $targetTeamId,
                    'orchestration_id' => $copy->id,
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
                        'step_id' => null,
                        'trigger_event' => $rule->trigger_event,
                        'label' => $rule->label,
                        'condition' => $rule->condition,
                        'action_prompt' => $rule->action_prompt,
                        'priority' => $rule->priority,
                        'is_active' => $rule->is_active,
                    ]);
                }
            }

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

            $this->cloneBindings($copy, $targetTeamId);

            return $copy->fresh();
        });
    }

    /**
     * Clone bindings into the target orchestration. Same-team copy:
     * 1:1 mirror of the source's binding rows. Cross-team copy: try
     * the target team's existing binding for the same key first, then
     * fall back to a placeholder row with `resource_id = null` so the
     * "Bindings" modal flags it for attention.
     */
    protected function cloneBindings(self $copy, ?int $targetTeamId): void
    {
        if (! $targetTeamId) {
            return; // Cloning into a platform-shared orchestration — no bindings live there.
        }

        $defs = $this->bindingDefinitions();
        if ($defs === []) {
            return;
        }

        $existingForTarget = OrchestrationBinding::query()
            ->withoutGlobalScope('team')
            ->where('team_id', $targetTeamId)
            ->whereIn('binding_key', collect($defs)->pluck('binding_key'))
            ->get()
            ->keyBy('binding_key');

        $sourceBindings = OrchestrationBinding::query()
            ->withoutGlobalScope('team')
            ->where('orchestration_id', $this->id)
            ->where('team_id', $this->team_id)
            ->get()
            ->keyBy('binding_key');

        $sameTeam = $this->team_id === $targetTeamId;

        foreach ($defs as $def) {
            $key = $def['binding_key'];
            $payload = [
                'team_id' => $targetTeamId,
                'orchestration_id' => $copy->id,
                'binding_key' => $key,
                'resource_type' => $def['resource_type'],
                'resource_id' => null,
                'resource_ids' => null,
            ];

            if ($sameTeam && isset($sourceBindings[$key])) {
                $src = $sourceBindings[$key];
                $payload['resource_id'] = $src->resource_id;
                $payload['resource_ids'] = $src->resource_ids;
            } elseif (isset($existingForTarget[$key])) {
                $reuse = $existingForTarget[$key];
                $payload['resource_id'] = $reuse->resource_id;
                $payload['resource_ids'] = $reuse->resource_ids;
            }

            OrchestrationBinding::create($payload);
        }
    }
}
