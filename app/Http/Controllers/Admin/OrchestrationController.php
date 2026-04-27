<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveOrchestrationRequest;
use App\Models\AgentPersona;
use App\Models\CallQueue;
use App\Models\ClientDid;
use App\Models\ClientSlot;
use App\Models\EmailQueue;
use App\Models\Extension;
use App\Models\IntakeFlow;
use App\Models\IntakeFlowRule;
use App\Models\IntakeFlowStep;
use App\Models\IntakeFlowTransition;
use App\Models\IntakeGoal;
use App\Models\KnowledgeStore;
use App\Models\Orchestration;
use App\Models\OrchestrationBinding;
use App\Models\Team;
use App\Services\Flows\BindingResolver;
use App\Services\Flows\ChannelTriggerSeeder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * JSON API for the Svelte Flow editor.
 *
 *  GET  /api/admin/clients/{client}/orchestrations
 *    Lightweight list of the client's orchestrations (name / flow
 *    count / which queues they're assigned to). Used by the
 *    OrchestrationResource list page and by any future picker.
 *
 *  GET  /api/admin/orchestrations/{orchestration}
 *    Returns everything the editor needs to render one
 *    orchestration: orchestration + client metadata, declared slots
 *    (team-scoped), every flow in the orchestration (with steps +
 *    outbound transitions + rules), the intake-goal primitive
 *    catalog, and the client's data-dictionary lists (knowledge
 *    stores, extensions, call queues, etc.).
 *
 *  PUT  /api/admin/orchestrations/{orchestration}
 *    Accepts the orchestration's full canvas state and applies it
 *    atomically — upserts slots (team-scoped) + flows / steps /
 *    transitions / rules (orchestration-scoped) in one transaction.
 */
class OrchestrationController extends Controller
{
    public function __construct(
        protected BindingResolver $bindings,
    ) {}

    /**
     * GET /api/admin/clients/{client}/orchestrations
     *
     * Returns the client's orchestrations as a lightweight list.
     * "Active on" is derived live from the queues that point at
     * each orchestration. Platform-shared orchestrations
     * (`team_id IS NULL`) are included as a separate flag so the
     * client UI can offer them as assignable templates.
     */
    public function index(Team $client): JsonResponse
    {
        abort_unless(request()->user()?->isSuperAdmin(), 403);

        app(ChannelTriggerSeeder::class)->ensureBootstrap($client);

        $orchestrations = Orchestration::query()
            ->withoutGlobalScope('team')
            ->where(function ($q) use ($client) {
                $q->where('team_id', $client->id)->orWhereNull('team_id');
            })
            ->withCount(['flows', 'callQueues', 'emailQueues'])
            ->with(['callQueues:id,orchestration_id,name', 'emailQueues:id,orchestration_id,name'])
            ->orderBy('name')
            ->get();

        return response()->json([
            'client' => ['id' => $client->id, 'name' => $client->name],
            'orchestrations' => $orchestrations->map(fn (Orchestration $o) => [
                'id' => $o->id,
                'name' => $o->name,
                'description' => $o->description,
                'is_shared' => $o->isShared(),
                'flow_count' => $o->flows_count,
                'is_active' => ($o->call_queues_count + $o->email_queues_count) > 0,
                'assigned_to' => [
                    'call_queues' => $o->callQueues->map(fn ($q) => ['id' => $q->id, 'name' => $q->name])->values(),
                    'email_queues' => $o->emailQueues->map(fn ($q) => ['id' => $q->id, 'name' => $q->name])->values(),
                ],
                'created_at' => $o->created_at?->toIso8601String(),
                'updated_at' => $o->updated_at?->toIso8601String(),
            ]),
        ]);
    }

    /**
     * GET /api/admin/orchestrations/{graph}
     *
     * Editor payload — one graph's worth of flows, plus team-scoped
     * metadata the editor needs.
     */
    public function show(Orchestration $orchestration): JsonResponse
    {
        abort_unless(request()->user()?->isSuperAdmin(), 403);

        $client = $orchestration->team;
        $isShared = $orchestration->isShared();

        // Backfill channel-trigger flows inside this orchestration.
        // No-op once all five exist.
        $this->ensureOrchestrationHasChannelTriggers($orchestration);

        $flows = IntakeFlow::query()
            ->withoutGlobalScope('team')
            ->where('orchestration_id', $orchestration->id)
            ->with(['steps.intakeGoal', 'transitionsOut'])
            ->orderBy('display_order')
            ->orderBy('id')
            ->get();

        // For per-client orchestrations, resolve binding keys back to
        // concrete picker values so the editor's existing dropdowns
        // render their selection. For shared orchestrations the
        // binding keys pass through and the frontend renders a
        // binding-key input instead.
        $bindingsByKey = $isShared
            ? []
            : OrchestrationBinding::query()
                ->withoutGlobalScope('team')
                ->where('team_id', $orchestration->team_id)
                ->where('orchestration_id', $orchestration->id)
                ->get()
                ->keyBy('binding_key')
                ->all();

        return response()->json([
            'orchestration' => [
                'id' => $orchestration->id,
                'name' => $orchestration->name,
                'description' => $orchestration->description,
                'is_shared' => $isShared,
            ],
            'client' => $client
                ? ['id' => $client->id, 'name' => $client->name]
                : null,
            'slots' => $isShared
                ? []
                : ClientSlot::query()
                    ->where('team_id', $client->id)
                    ->orderBy('name')
                    ->get()
                    ->map(fn (ClientSlot $s) => [
                        'id' => $s->id,
                        'name' => $s->name,
                        'type' => $s->type,
                        'choices' => $s->choices,
                        'description' => $s->description,
                    ]),
            'flows' => $flows->map(fn (IntakeFlow $f) => [
                'id' => $f->id,
                'client_id' => (string) $f->id,
                'name' => $f->name,
                'description' => $f->description,
                'is_active' => $f->is_active,
                'is_entry' => $f->isEntry(),
                // Trigger flows are rendered distinctively (channel
                // icons, colored borders) and pin to the top row of
                // the canvas.
                'trigger_type' => $f->trigger_type,
                'kind' => $f->kind,
                'is_channel_trigger' => $f->isChannelTrigger(),
                'display_order' => $f->display_order,
                'canvas_x' => $f->canvas_x,
                'canvas_y' => $f->canvas_y,
                'steps' => $f->steps->map(fn (IntakeFlowStep $s) => [
                    'id' => $s->id,
                    'intake_goal_id' => $s->intake_goal_id,
                    'intake_goal_key' => $s->intakeGoal?->key,
                    'position' => $s->position,
                    'step_params' => $s->intakeGoal
                        ? $this->bindings->resolveStepParams(
                            $s->intakeGoal,
                            $s->step_params ?? [],
                            $bindingsByKey,
                        )
                        : ($s->step_params ?? []),
                ]),
                'transitions_out' => $f->transitionsOut->map(fn (IntakeFlowTransition $t) => [
                    'id' => $t->id,
                    'from_flow_client_id' => (string) $t->from_flow_id,
                    'to_flow_client_id' => $t->to_flow_id ? (string) $t->to_flow_id : null,
                    'condition' => $t->condition,
                    'description' => $t->description,
                    'priority' => $t->priority,
                    'source_handle' => $t->source_handle,
                ]),
                'rules' => $f->rules->map(fn (IntakeFlowRule $r) => [
                    'id' => $r->id,
                    'step_id' => $r->step_id,
                    'trigger_event' => $r->trigger_event,
                    'label' => $r->label,
                    'condition' => $r->condition,
                    'action_prompt' => $r->action_prompt,
                    'priority' => $r->priority,
                    'is_active' => $r->is_active,
                ]),
            ]),
            'primitives' => IntakeGoal::query()
                ->where('is_active', true)
                ->orderBy('category')
                ->orderBy('name')
                ->get()
                ->map(fn (IntakeGoal $g) => [
                    'id' => $g->id,
                    'key' => $g->key,
                    'name' => $g->name,
                    'category' => $g->category,
                    'icon' => $g->icon,
                    'description' => $g->description,
                    'data_fields' => $g->data_fields ?? [],
                    'max_transitions' => $g->max_transitions,
                    'exits' => $g->exits,
                ]),
            // Data-dictionary payloads the editor's pickers read from.
            // Shared orchestrations have no client to scope these to —
            // the editor switches to binding-key-input mode and these
            // arrays stay empty.
            'knowledge_stores' => $isShared
                ? []
                : KnowledgeStore::query()
                    ->where('team_id', $client->id)
                    ->orderBy('name')
                    ->get()
                    ->map(fn (KnowledgeStore $k) => [
                        'id' => $k->id,
                        'name' => $k->name,
                    ]),
            'extensions' => $isShared
                ? []
                : Extension::query()
                    ->withoutGlobalScope('team')
                    ->where('team_id', $client->id)
                    ->orderBy('number')
                    ->get()
                    ->map(fn (Extension $e) => [
                        'id' => $e->id,
                        'number' => $e->number,
                        'label' => $e->label,
                        'type' => $e->type,
                    ]),
            'call_queues' => $isShared
                ? []
                : CallQueue::query()
                    ->withoutGlobalScope('team')
                    ->where('team_id', $client->id)
                    ->orderBy('name')
                    ->get()
                    ->map(fn (CallQueue $q) => [
                        'id' => $q->id,
                        'name' => $q->name,
                        'strategy' => $q->strategy,
                    ]),
            'email_queues' => $isShared
                ? []
                : EmailQueue::query()
                    ->withoutGlobalScope('team')
                    ->where('team_id', $client->id)
                    ->orderBy('name')
                    ->get()
                    ->map(fn (EmailQueue $q) => [
                        'id' => $q->id,
                        'name' => $q->name,
                    ]),
            'agent_personas' => $isShared
                ? []
                : AgentPersona::query()
                    ->withoutGlobalScope('team')
                    ->where('team_id', $client->id)
                    ->where('is_active', true)
                    ->orderBy('name')
                    ->get()
                    ->map(fn (AgentPersona $p) => [
                        'id' => $p->id,
                        'name' => $p->name,
                        'role' => $p->role,
                    ]),
            'dids' => $isShared
                ? []
                : ClientDid::query()
                    ->where('team_id', $client->id)
                    ->where('is_active', true)
                    ->orderBy('priority')
                    ->orderBy('number')
                    ->get()
                    ->map(fn (ClientDid $d) => [
                        'id' => $d->id,
                        'number' => $d->number,
                        'label' => $d->label,
                    ]),
            // Bindings declared by this orchestration: resource_type
            // per binding_key (and the concrete value for private
            // orchestrations). Used by the editor's BindingsPanel when
            // editing a shared orchestration, and by the picker
            // components when surfacing "what binding does this field
            // resolve through".
            'bindings' => $this->bindingDefinitions($orchestration, $bindingsByKey),
        ]);
    }

    /**
     * Build the editor's `bindings` payload entry. For private
     * orchestrations, emits the actual binding rows (with concrete
     * resource ids) so the editor can pre-fill picker selections. For
     * shared orchestrations, emits only `(binding_key, resource_type)`
     * — values come from each assigning client's bindings.
     *
     * @param  array<string, OrchestrationBinding>  $bindingsByKey
     * @return array<int, array<string, mixed>>
     */
    protected function bindingDefinitions(Orchestration $orchestration, array $bindingsByKey): array
    {
        if (! $orchestration->isShared()) {
            return collect($bindingsByKey)
                ->values()
                ->map(fn (OrchestrationBinding $b) => [
                    'binding_key' => $b->binding_key,
                    'resource_type' => $b->resource_type,
                    'resource_id' => $b->resource_id,
                    'resource_ids' => $b->resource_ids,
                ])
                ->all();
        }

        return collect($orchestration->bindingDefinitions())
            ->map(fn (array $def) => $def + ['resource_id' => null, 'resource_ids' => null])
            ->all();
    }

    public function update(SaveOrchestrationRequest $request, Orchestration $orchestration): JsonResponse
    {
        $payload = $request->validated();
        $client = $orchestration->team;

        DB::transaction(function () use ($client, $orchestration, $payload) {
            // Slots live on the owning client. Shared orchestrations
            // have no client, so slot edits are a no-op there — the
            // running client's slots are what runtime resolves.
            if ($client) {
                $this->applySlots($client, $payload['slots'] ?? []);
            }
            $clientIdToFlowId = $this->applyFlows($orchestration, $payload['flows'] ?? []);
            $this->applyTransitions($orchestration, $payload['transitions'] ?? [], $clientIdToFlowId);
        });

        return $this->show($orchestration);
    }

    /**
     * Ensure this specific graph holds the five channel-trigger
     * flows. Unlike `ChannelTriggerSeeder::ensureTriggersFor`, which
     * operates on whichever graph already exists for the team, this
     * plants the triggers inside the passed graph — useful when the
     * editor opens a freshly-created draft graph that has no flows
     * yet.
     */
    protected function ensureOrchestrationHasChannelTriggers(Orchestration $orchestration): void
    {
        $existing = IntakeFlow::withoutGlobalScope('team')
            ->where('orchestration_id', $orchestration->id)
            ->whereIn('trigger_type', IntakeFlow::CHANNEL_TRIGGERS)
            ->pluck('trigger_type')
            ->all();

        $layout = [
            IntakeFlow::TRIGGER_INBOUND_PHONE => ['Inbound Phone',  40,   40],
            IntakeFlow::TRIGGER_INBOUND_EMAIL => ['Inbound Email',  340,  40],
            IntakeFlow::TRIGGER_INBOUND_SMS => ['Inbound SMS',    640,  40],
            IntakeFlow::TRIGGER_INBOUND_WCTP => ['Inbound WCTP',   940,  40],
            IntakeFlow::TRIGGER_OUTBOUND_PHONE => ['Outbound Phone', 1240, 40],
        ];
        $order = 0;
        foreach ($layout as $triggerType => $meta) {
            $order++;
            if (in_array($triggerType, $existing, true)) {
                continue;
            }
            [$name, $x, $y] = $meta;
            IntakeFlow::create([
                'team_id' => $orchestration->team_id,
                'orchestration_id' => $orchestration->id,
                'name' => $name,
                'trigger_type' => $triggerType,
                'kind' => IntakeFlow::KIND_CALL_FLOW,
                'is_active' => false,
                'display_order' => $order,
                'canvas_x' => $x,
                'canvas_y' => $y,
            ]);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $slots
     */
    protected function applySlots(Team $client, array $slots): void
    {
        $keepIds = [];
        foreach ($slots as $row) {
            $slot = ! empty($row['id'])
                ? ClientSlot::where('team_id', $client->id)->find($row['id'])
                : new ClientSlot(['team_id' => $client->id]);
            if (! $slot) {
                continue; // id pointed at a stale / foreign row
            }
            $slot->fill([
                'team_id' => $client->id,
                'name' => $row['name'],
                'type' => $row['type'],
                'choices' => $row['choices'] ?? null,
                'description' => $row['description'] ?? null,
            ])->save();
            $keepIds[] = $slot->id;
        }
        ClientSlot::where('team_id', $client->id)
            ->when($keepIds, fn ($q) => $q->whereNotIn('id', $keepIds))
            ->delete();
    }

    /**
     * @param  array<int, array<string, mixed>>  $flows
     * @return array<string, int> map of client-side id → real flow id
     */
    protected function applyFlows(Orchestration $orchestration, array $flows): array
    {
        $clientIdMap = [];
        $keepIds = [];

        foreach ($flows as $row) {
            $flow = ! empty($row['id'])
                ? IntakeFlow::withoutGlobalScope('team')->where('orchestration_id', $orchestration->id)->find($row['id'])
                : new IntakeFlow([
                    'team_id' => $orchestration->team_id,
                    'orchestration_id' => $orchestration->id,
                ]);
            if (! $flow) {
                continue;
            }
            $flow->fill([
                'team_id' => $orchestration->team_id,
                'orchestration_id' => $orchestration->id,
                'name' => $row['name'],
                'description' => $row['description'] ?? null,
                'is_active' => (bool) ($row['is_active'] ?? true),
                'trigger_type' => $row['trigger_type'] ?? $flow->trigger_type ?? IntakeFlow::TRIGGER_SUBFLOW,
                'kind' => $row['kind'] ?? $flow->kind ?? IntakeFlow::KIND_CALL_FLOW,
                'display_order' => (int) ($row['display_order'] ?? $flow->display_order ?? 0),
                'canvas_x' => $row['canvas_x'] ?? null,
                'canvas_y' => $row['canvas_y'] ?? null,
            ])->save();

            $keepIds[] = $flow->id;
            $clientIdMap[(string) ($row['id'] ?? $row['client_id'] ?? $flow->id)] = $flow->id;

            $this->applySteps($flow, $row['steps'] ?? []);
            $this->applyRules($flow, $row['rules'] ?? []);
        }

        // Detach dropped flows within this graph — steps + transitions
        // cascade via FK.
        IntakeFlow::withoutGlobalScope('team')
            ->where('orchestration_id', $orchestration->id)
            ->when($keepIds, fn ($q) => $q->whereNotIn('id', $keepIds))
            ->get()
            ->each(fn (IntakeFlow $f) => $f->delete());

        return $clientIdMap;
    }

    /**
     * @param  array<int, array<string, mixed>>  $steps
     */
    protected function applySteps(IntakeFlow $flow, array $steps): void
    {
        $goalIdByKey = IntakeGoal::query()->pluck('id', 'key');
        $goalsById = IntakeGoal::query()->get()->keyBy('id');
        $orchestration = $flow->orchestration;
        $keepIds = [];

        foreach ($steps as $row) {
            $goalId = $goalIdByKey[$row['intake_goal_key']] ?? null;
            if (! $goalId) {
                continue; // primitive no longer in the library; skip
            }

            $step = ! empty($row['id'])
                ? IntakeFlowStep::where('flow_id', $flow->id)->find($row['id'])
                : new IntakeFlowStep(['flow_id' => $flow->id]);
            if (! $step) {
                continue;
            }
            $step->fill([
                'flow_id' => $flow->id,
                'intake_goal_id' => $goalId,
                'position' => $row['position'],
                'step_params' => $row['step_params'] ?? [],
            ])->save();

            // Normalize step_params: any concrete picker IDs become
            // binding keys + corresponding orchestration_bindings rows.
            // Idempotent — already-stringified binding keys pass
            // through unchanged.
            if ($orchestration && ($goal = $goalsById[$goalId] ?? null)) {
                $normalized = $this->bindings->normalizeStepParams(
                    $step,
                    $goal,
                    $step->step_params ?? [],
                    $orchestration,
                );
                $step->step_params = $normalized;
                $step->save();
            }

            $keepIds[] = $step->id;
        }

        IntakeFlowStep::where('flow_id', $flow->id)
            ->when($keepIds, fn ($q) => $q->whereNotIn('id', $keepIds))
            ->delete();
    }

    /**
     * Upsert rule rows for a flow. Rules are matched to existing rows
     * by id; unknown ids or omitted rows get pruned.
     *
     * @param  array<int, array<string, mixed>>  $rules
     */
    protected function applyRules(IntakeFlow $flow, array $rules): void
    {
        $keepIds = [];

        foreach ($rules as $row) {
            $rule = ! empty($row['id'])
                ? IntakeFlowRule::where('flow_id', $flow->id)->find($row['id'])
                : new IntakeFlowRule(['flow_id' => $flow->id]);
            if (! $rule) {
                continue;
            }

            // Validate step_id is scoped to this flow (if set).
            $stepId = $row['step_id'] ?? null;
            if ($stepId !== null) {
                $belongs = IntakeFlowStep::where('flow_id', $flow->id)
                    ->where('id', $stepId)
                    ->exists();
                if (! $belongs) {
                    $stepId = null;
                }
            }

            $trigger = $row['trigger_event'] ?? IntakeFlowRule::TRIGGER_ON_FIELD_SET;
            if (! in_array($trigger, IntakeFlowRule::TRIGGERS, true)) {
                $trigger = IntakeFlowRule::TRIGGER_ON_FIELD_SET;
            }

            $rule->fill([
                'flow_id' => $flow->id,
                'step_id' => $stepId,
                'trigger_event' => $trigger,
                'label' => $row['label'] ?? null,
                'condition' => $row['condition'] ?? null,
                'action_prompt' => $row['action_prompt'] ?? null,
                'priority' => (int) ($row['priority'] ?? 100),
                'is_active' => (bool) ($row['is_active'] ?? true),
            ])->save();

            $keepIds[] = $rule->id;
        }

        IntakeFlowRule::where('flow_id', $flow->id)
            ->when($keepIds, fn ($q) => $q->whereNotIn('id', $keepIds))
            ->delete();
    }

    /**
     * @param  array<int, array<string, mixed>>  $transitions
     * @param  array<string, int>  $clientIdToFlowId
     */
    protected function applyTransitions(Orchestration $orchestration, array $transitions, array $clientIdToFlowId): void
    {
        $flowIds = array_values($clientIdToFlowId);
        $keepIds = [];

        foreach ($transitions as $row) {
            $fromId = $clientIdToFlowId[$row['from_flow_client_id']] ?? null;
            if (! $fromId) {
                continue;
            }
            $toId = $row['to_flow_client_id']
                ? ($clientIdToFlowId[$row['to_flow_client_id']] ?? null)
                : null;

            $transition = ! empty($row['id'])
                ? IntakeFlowTransition::find($row['id'])
                : new IntakeFlowTransition;
            if (! $transition) {
                continue;
            }
            $transition->fill([
                'from_flow_id' => $fromId,
                'to_flow_id' => $toId,
                'condition' => $row['condition'] ?? null,
                'description' => $row['description'] ?? null,
                'priority' => $row['priority'] ?? 100,
                'source_handle' => $row['source_handle'] ?? null,
            ])->save();
            $keepIds[] = $transition->id;
        }

        // Only prune transitions originating from flows within this
        // graph. Other graphs' transitions are untouched.
        $graphFlowIds = IntakeFlow::withoutGlobalScope('team')
            ->where('orchestration_id', $orchestration->id)
            ->pluck('id');
        if ($graphFlowIds->isNotEmpty()) {
            IntakeFlowTransition::whereIn('from_flow_id', $graphFlowIds)
                ->when($keepIds, fn ($q) => $q->whereNotIn('id', $keepIds))
                ->delete();
        }
    }
}
