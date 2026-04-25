<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveFlowGraphRequest;
use App\Models\AgentPersona;
use App\Models\CallQueue;
use App\Models\ClientChannelAssignment;
use App\Models\ClientDid;
use App\Models\ClientSlot;
use App\Models\EmailQueue;
use App\Models\Extension;
use App\Models\FlowGraph;
use App\Models\IntakeFlow;
use App\Models\IntakeFlowRule;
use App\Models\IntakeFlowStep;
use App\Models\IntakeFlowTransition;
use App\Models\IntakeGoal;
use App\Models\KnowledgeStore;
use App\Models\Team;
use App\Services\Flows\ChannelTriggerSeeder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * JSON API for the Svelte Flow editor.
 *
 *  GET  /api/admin/clients/{client}/flow-graphs
 *    Lightweight list of the client's graphs (name / status / flow
 *    count / active-on-channels summary). Used by the
 *    FlowGraphResource list page and by any future "graph picker"
 *    surface.
 *
 *  GET  /api/admin/flow-graphs/{graph}
 *    Returns everything the editor needs to render one graph: graph
 *    + client metadata, declared slots (team-scoped), every flow in
 *    the graph (with steps + outbound transitions + rules), the
 *    intake-goal primitive catalog, and the client's data-dictionary
 *    lists (knowledge stores, extensions, call queues, etc.).
 *
 *  PUT  /api/admin/flow-graphs/{graph}
 *    Accepts the graph's full canvas state and applies it atomically
 *    — upserts slots (team-scoped) + flows/steps/transitions/rules
 *    (graph-scoped) in one transaction.
 */
class FlowGraphController extends Controller
{
    /**
     * GET /api/admin/clients/{client}/flow-graphs
     *
     * Returns the client's flow graphs as a lightweight list.
     */
    public function index(Team $client): JsonResponse
    {
        abort_unless(request()->user()?->isSuperAdmin(), 403);

        // Ensure the client has its Default graph + 5 channel
        // assignments. No-op once bootstrapped.
        app(ChannelTriggerSeeder::class)->ensureBootstrap($client);

        $graphs = FlowGraph::query()
            ->where('team_id', $client->id)
            ->withCount('flows')
            ->orderBy('name')
            ->get();

        // Derive "which channels this graph is active on" for each
        // graph from the client's channel_assignments — purely a
        // display hint for the resource list.
        $assignments = ClientChannelAssignment::query()
            ->where('team_id', $client->id)
            ->get()
            ->groupBy('flow_graph_id');

        return response()->json([
            'client' => ['id' => $client->id, 'name' => $client->name],
            'graphs' => $graphs->map(fn (FlowGraph $g) => [
                'id' => $g->id,
                'name' => $g->name,
                'description' => $g->description,
                'status' => $g->status,
                'flow_count' => $g->flows_count,
                'active_on_channels' => $assignments->get($g->id, collect())
                    ->pluck('channel_type')
                    ->values()
                    ->all(),
                'created_at' => $g->created_at?->toIso8601String(),
                'updated_at' => $g->updated_at?->toIso8601String(),
            ]),
            'channel_assignments' => ClientChannelAssignment::query()
                ->where('team_id', $client->id)
                ->get()
                ->map(fn (ClientChannelAssignment $a) => [
                    'channel_type' => $a->channel_type,
                    'flow_graph_id' => $a->flow_graph_id,
                ])->values(),
        ]);
    }

    /**
     * GET /api/admin/flow-graphs/{graph}
     *
     * Editor payload — one graph's worth of flows, plus team-scoped
     * metadata the editor needs.
     */
    public function show(FlowGraph $graph): JsonResponse
    {
        abort_unless(request()->user()?->isSuperAdmin(), 403);

        $client = $graph->team;

        // Backfill triggers inside this specific graph. No-op once
        // all five channel-trigger flows exist here.
        $this->ensureGraphHasChannelTriggers($graph);

        $flows = IntakeFlow::query()
            ->withoutGlobalScope('team')
            ->where('flow_graph_id', $graph->id)
            ->with(['steps.intakeGoal', 'transitionsOut'])
            ->orderBy('display_order')
            ->orderBy('id')
            ->get();

        return response()->json([
            'graph' => [
                'id' => $graph->id,
                'name' => $graph->name,
                'description' => $graph->description,
                'status' => $graph->status,
            ],
            'client' => [
                'id' => $client->id,
                'name' => $client->name,
            ],
            'slots' => ClientSlot::query()
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
                    'step_params' => $s->step_params ?? [],
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
            'knowledge_stores' => KnowledgeStore::query()
                ->where('team_id', $client->id)
                ->orderBy('name')
                ->get()
                ->map(fn (KnowledgeStore $k) => [
                    'id' => $k->id,
                    'name' => $k->name,
                ]),
            // Data-dictionary payloads the new pickers read from.
            'extensions' => Extension::query()
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
            'call_queues' => CallQueue::query()
                ->withoutGlobalScope('team')
                ->where('team_id', $client->id)
                ->orderBy('name')
                ->get()
                ->map(fn (CallQueue $q) => [
                    'id' => $q->id,
                    'name' => $q->name,
                    'strategy' => $q->strategy,
                ]),
            'email_queues' => EmailQueue::query()
                ->withoutGlobalScope('team')
                ->where('team_id', $client->id)
                ->orderBy('name')
                ->get()
                ->map(fn (EmailQueue $q) => [
                    'id' => $q->id,
                    'name' => $q->name,
                ]),
            'agent_personas' => AgentPersona::query()
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
            'dids' => ClientDid::query()
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
        ]);
    }

    public function update(SaveFlowGraphRequest $request, FlowGraph $graph): JsonResponse
    {
        $payload = $request->validated();
        $client = $graph->team;

        DB::transaction(function () use ($client, $graph, $payload) {
            $this->applySlots($client, $payload['slots'] ?? []);
            $clientIdToFlowId = $this->applyFlows($graph, $payload['flows'] ?? []);
            $this->applyTransitions($graph, $payload['transitions'] ?? [], $clientIdToFlowId);

            // Routing resolution at runtime reads the queue → channel
            // assignment → graph chain directly; no synthetic
            // routing_rules need to be materialized from the canvas.
        });

        return $this->show($graph);
    }

    /**
     * Ensure this specific graph holds the five channel-trigger
     * flows. Unlike `ChannelTriggerSeeder::ensureTriggersFor`, which
     * operates on whichever graph already exists for the team, this
     * plants the triggers inside the passed graph — useful when the
     * editor opens a freshly-created draft graph that has no flows
     * yet.
     */
    protected function ensureGraphHasChannelTriggers(FlowGraph $graph): void
    {
        $existing = IntakeFlow::withoutGlobalScope('team')
            ->where('flow_graph_id', $graph->id)
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
                'team_id' => $graph->team_id,
                'flow_graph_id' => $graph->id,
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
    protected function applyFlows(FlowGraph $graph, array $flows): array
    {
        $clientIdMap = [];
        $keepIds = [];

        foreach ($flows as $row) {
            $flow = ! empty($row['id'])
                ? IntakeFlow::withoutGlobalScope('team')->where('flow_graph_id', $graph->id)->find($row['id'])
                : new IntakeFlow([
                    'team_id' => $graph->team_id,
                    'flow_graph_id' => $graph->id,
                ]);
            if (! $flow) {
                continue;
            }
            $flow->fill([
                'team_id' => $graph->team_id,
                'flow_graph_id' => $graph->id,
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
            ->where('flow_graph_id', $graph->id)
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
    protected function applyTransitions(FlowGraph $graph, array $transitions, array $clientIdToFlowId): void
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
            ->where('flow_graph_id', $graph->id)
            ->pluck('id');
        if ($graphFlowIds->isNotEmpty()) {
            IntakeFlowTransition::whereIn('from_flow_id', $graphFlowIds)
                ->when($keepIds, fn ($q) => $q->whereNotIn('id', $keepIds))
                ->delete();
        }
    }
}
