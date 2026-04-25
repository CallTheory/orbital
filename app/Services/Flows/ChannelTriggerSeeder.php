<?php

declare(strict_types=1);

namespace App\Services\Flows;

use App\Models\ClientChannelAssignment;
use App\Models\FlowGraph;
use App\Models\IntakeFlow;
use App\Models\Team;
use Illuminate\Support\Facades\DB;

/**
 * Bootstraps a client's flow-graph infrastructure and ensures the
 * five channel-trigger flows exist on the canvas.
 *
 * Two responsibilities:
 *   1. `ensureBootstrap(Team)` — make sure the team has a Default
 *      FlowGraph and five ClientChannelAssignment rows (one per
 *      channel type, all pointing at the Default graph). Safe to
 *      re-run; existing rows are left alone.
 *   2. `ensureTriggersFor(Team)` — run the bootstrap, then make
 *      sure the Default graph has the five channel-trigger flows
 *      present (inbound phone, email, sms, wctp, outbound phone),
 *      laid out across the top row. Triggers start inactive so they
 *      don't fire anything until the author wires an outbound edge.
 *
 * Called lazily from FlowGraphController::show() for legacy
 * clients, and on-demand from seeders. Idempotent.
 */
class ChannelTriggerSeeder
{
    /**
     * Channel → (default name, canvas x, canvas y).
     */
    private const CHANNELS = [
        IntakeFlow::TRIGGER_INBOUND_PHONE => ['Inbound Phone',  40,   40],
        IntakeFlow::TRIGGER_INBOUND_EMAIL => ['Inbound Email',  340,  40],
        IntakeFlow::TRIGGER_INBOUND_SMS => ['Inbound SMS',    640,  40],
        IntakeFlow::TRIGGER_INBOUND_WCTP => ['Inbound WCTP',   940,  40],
        IntakeFlow::TRIGGER_OUTBOUND_PHONE => ['Outbound Phone', 1240, 40],
    ];

    /**
     * Ensure the team has a Default FlowGraph + five
     * ClientChannelAssignment rows. Returns the Default graph so
     * callers can thread it through.
     */
    public function ensureBootstrap(Team $team): FlowGraph
    {
        return DB::transaction(function () use ($team) {
            $graph = FlowGraph::query()
                ->where('team_id', $team->id)
                ->where('status', FlowGraph::STATUS_ACTIVE)
                ->orderBy('id')
                ->first();

            if (! $graph) {
                $graph = FlowGraph::create([
                    'team_id' => $team->id,
                    'name' => 'Default',
                    'description' => 'Default flow graph for this client.',
                    'status' => FlowGraph::STATUS_ACTIVE,
                ]);
            }

            $existing = ClientChannelAssignment::query()
                ->where('team_id', $team->id)
                ->pluck('channel_type')
                ->all();

            foreach (ClientChannelAssignment::CHANNELS as $channel) {
                if (in_array($channel, $existing, true)) {
                    continue;
                }
                ClientChannelAssignment::create([
                    'team_id' => $team->id,
                    'channel_type' => $channel,
                    'flow_graph_id' => $graph->id,
                ]);
            }

            return $graph;
        });
    }

    /**
     * Create any missing trigger flows for the team. Existing trigger
     * flows are left untouched. Returns the set of flow IDs that were
     * created.
     *
     * @return array<int, int>
     */
    public function ensureTriggersFor(Team $team): array
    {
        $graph = $this->ensureBootstrap($team);

        $existing = IntakeFlow::query()
            ->where('flow_graph_id', $graph->id)
            ->whereIn('trigger_type', array_keys(self::CHANNELS))
            ->pluck('trigger_type')
            ->all();

        $created = [];
        $order = 0;

        DB::transaction(function () use ($team, $graph, $existing, &$created, &$order) {
            foreach (self::CHANNELS as $triggerType => $meta) {
                $order++;
                if (in_array($triggerType, $existing, true)) {
                    continue;
                }

                [$name, $x, $y] = $meta;

                $flow = IntakeFlow::create([
                    'team_id' => $team->id,
                    'flow_graph_id' => $graph->id,
                    'name' => $name,
                    'description' => null,
                    'trigger_type' => $triggerType,
                    'kind' => IntakeFlow::KIND_CALL_FLOW,
                    'is_active' => false,
                    'display_order' => $order,
                    'canvas_x' => $x,
                    'canvas_y' => $y,
                ]);

                $created[] = $flow->id;
            }
        });

        return $created;
    }
}
