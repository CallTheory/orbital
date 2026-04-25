<script lang="ts">
    // Editor shell. Canvas renders flows as nodes with inline step
    // rows; all editing happens in focused modals opened from
    // buttons on the card. The right-side drawer is gone — no more
    // disconnect between "thing I selected" and "thing I'm editing."

    import { onMount, untrack } from 'svelte';
    import type { Connection, Edge, Node } from '@xyflow/svelte';
    import {
        SvelteFlow,
        Background,
        Controls,
        MiniMap,
        useSvelteFlow,
    } from '@xyflow/svelte';
    import FlowNode from './components/FlowNode.svelte';
    import ReconnectableEdge from './components/ReconnectableEdge.svelte';
    import StepEditModal from './components/StepEditModal.svelte';
    import FlowSettingsModal from './components/FlowSettingsModal.svelte';
    import PrimitivePickerModal from './components/PrimitivePickerModal.svelte';
    import Palette from './components/Palette.svelte';
    import ScreenToFlowBridge from './components/ScreenToFlowBridge.svelte';
    import { fetchFlowGraph, saveFlowGraph, type PrimitiveDTO, type FlowDTO } from './lib/api';
    import {
        fromDTO,
        addFlow as gAddFlow,
        removeFlow as gRemoveFlow,
        addTransition as gAddTransition,
        addStep as gAddStep,
        removeStep as gRemoveStep,
        moveStep as gMoveStep,
        findFlow,
        markDirty,
        toSavePayload,
        type EditorGraph,
    } from './lib/graph';

    let {
        graphId,
        clientId,
        focusFlowId,
        graphName,
        clientName,
    }: {
        graphId: number;
        clientId: number;
        focusFlowId: number | null;
        graphName: string;
        clientName: string;
    } = $props();

    let graph: EditorGraph | null = $state(null);
    let loadError: string | null = $state(null);
    let saving = $state(false);

    // Modal state — at most one open at a time.
    let flowSettingsTarget: string | null = $state(null);
    let primitivePickerTarget: string | null = $state(null);
    let stepEditTarget: { flowId: string; stepIndex: number } | null = $state(null);

    let nodes: Node[] = $state([]);
    let edges: Edge[] = $state([]);

    const nodeTypes = { flow: FlowNode };
    const edgeTypes = { transition: ReconnectableEdge };

    // Set by the <ScreenToFlowBridge> child once SvelteFlow is mounted.
    // Used by the drop handler to convert mouse coords into flow coords
    // so new flows land where the cursor dropped them.
    let screenToFlow: ((p: { x: number; y: number }) => { x: number; y: number }) | null = $state(null);
    let draggingOverCanvas = $state(false);

    // Channel filter. When a channel is in `hiddenChannels`, flows that
    // belong ONLY to that channel get filtered out of the canvas. A
    // flow's channel membership is derived by BFS from each channel-
    // trigger flow along outbound transitions. Flows with no channel
    // owner (subflows / action groups / orphans) are always visible.
    const CHANNELS = ['inbound_phone', 'inbound_email', 'inbound_sms', 'inbound_wctp', 'outbound_phone'] as const;
    type Channel = (typeof CHANNELS)[number];
    const CHANNEL_LABEL: Record<Channel, string> = {
        inbound_phone: 'Phone',
        inbound_email: 'Email',
        inbound_sms: 'SMS',
        inbound_wctp: 'WCTP',
        outbound_phone: 'Out',
    };

    let hiddenChannels: Set<Channel> = $state(loadHiddenChannels());

    function loadHiddenChannels(): Set<Channel> {
        try {
            const raw = localStorage.getItem(`flowEditor:hiddenChannels:${clientId}`);
            if (!raw) return new Set();
            const arr = JSON.parse(raw) as Channel[];
            return new Set(arr.filter((c) => (CHANNELS as readonly string[]).includes(c)));
        } catch {
            return new Set();
        }
    }

    function toggleChannel(channel: Channel) {
        const next = new Set(hiddenChannels);
        if (next.has(channel)) next.delete(channel);
        else next.add(channel);
        hiddenChannels = next;
        try {
            localStorage.setItem(
                `flowEditor:hiddenChannels:${clientId}`,
                JSON.stringify(Array.from(next)),
            );
        } catch {
            /* noop — localStorage quota / private mode */
        }
    }

    /**
     * Map flow.client_id → set of channels that reach it via outbound
     * transitions. A flow can belong to multiple channels (shared
     * subflow). Channel-trigger flows belong to their own channel
     * only.
     */
    function channelOwnership(g: EditorGraph): Map<string, Set<Channel>> {
        const map = new Map<string, Set<Channel>>();
        for (const trigger of g.flows.filter((f) => f.is_channel_trigger)) {
            const channel = trigger.trigger_type as Channel;
            if (!(CHANNELS as readonly string[]).includes(channel)) continue;

            const visited = new Set<string>();
            const queue: string[] = [trigger.client_id];
            while (queue.length > 0) {
                const id = queue.shift()!;
                if (visited.has(id)) continue;
                visited.add(id);
                const set = map.get(id) ?? new Set<Channel>();
                set.add(channel);
                map.set(id, set);
                const flow = g.flows.find((f) => f.client_id === id);
                if (!flow) continue;
                for (const t of flow.transitions_out) {
                    if (t.to_flow_client_id && !visited.has(t.to_flow_client_id)) {
                        queue.push(t.to_flow_client_id);
                    }
                }
            }
        }
        return map;
    }

    function isFlowHidden(clientId: string, ownership: Map<string, Set<Channel>>): boolean {
        const owners = ownership.get(clientId);
        if (!owners || owners.size === 0) return false;
        // Hidden only when EVERY owning channel is hidden — a flow
        // shared by phone + email stays visible if either is on.
        return Array.from(owners).every((c) => hiddenChannels.has(c));
    }

    onMount(async () => {
        try {
            const dto = await fetchFlowGraph(graphId);
            graph = fromDTO(dto);
            ({ nodes, edges } = buildViewModel(graph));
            if (focusFlowId) {
                const match = graph.flows.find((f) => f.id === focusFlowId);
                if (match) flowSettingsTarget = match.client_id;
            }
            window.addEventListener('beforeunload', beforeUnload);
        } catch (e: unknown) {
            loadError = e instanceof Error ? e.message : String(e);
        }
    });

    function beforeUnload(e: BeforeUnloadEvent) {
        if (graph?.dirty) e.preventDefault();
    }

    function primitiveForKey(key: string): PrimitiveDTO | undefined {
        return graph?.primitives.find((p) => p.key === key);
    }

    /**
     * Named exits for a flow, derived from its terminal step's
     * primitive. Returns:
     *   - null          → unbounded exits (current + port behavior)
     *   - []            → terminal flow, no exits
     *   - string[]      → fixed named exits (one port per name)
     *
     * A channel-trigger flow that has no steps yet falls back to
     * the trigger's own exits definition (the trigger primitive row
     * is at key `trigger_<channel>`).
     */
    function flowExits(flow: {
        steps: Array<{ intake_goal_key: string; step_params?: Record<string, unknown> }>;
        is_channel_trigger: boolean;
        trigger_type: string | null;
    }): string[] | null {
        if (!graph) return null;
        // Terminal step drives the exits for a non-empty flow.
        if (flow.steps.length > 0) {
            const last = flow.steps[flow.steps.length - 1];
            // Some primitives derive their exits from their own
            // step_params (e.g. gather_choice pulls from `options`).
            // Check those before falling back to the primitive's
            // static declaration.
            const dynamic = dynamicExitsFor(last.intake_goal_key, last.step_params ?? {});
            if (dynamic !== null) return dynamic;
            const prim = primitiveForKey(last.intake_goal_key);
            return prim?.exits ?? null;
        }
        // Empty channel trigger: look up the matching trigger
        // primitive's declared exits (trigger_inbound_phone, …).
        if (flow.is_channel_trigger && flow.trigger_type) {
            const triggerPrim = primitiveForKey(`trigger_${flow.trigger_type}`);
            if (triggerPrim?.exits !== undefined) return triggerPrim.exits;
        }
        return null;
    }

    /**
     * Primitives whose real exits live in their step_params rather
     * than in the library declaration. Returns null when the
     * primitive isn't one of these or when the params don't yet
     * define enough to derive a useful set — in which case the
     * caller falls back to primitive.exits.
     *
     * A trailing "fallback" exit is appended so authors always have
     * a route for "caller said something we don't recognise".
     */
    function dynamicExitsFor(key: string, params: Record<string, unknown>): string[] | null {
        if (key === 'gather_choice') {
            const opts = params.options;
            if (Array.isArray(opts) && opts.length > 0) {
                const names = (opts as unknown[]).map((o) => String(o)).filter((o) => o !== '');
                if (names.length > 0) return [...names, 'fallback'];
            }
            return null;
        }
        return null;
    }

    function flowMaxTransitions(flow: {
        steps: Array<{ intake_goal_key: string }>;
        is_channel_trigger: boolean;
        trigger_type: string | null;
    }): number | null {
        const exits = flowExits(flow);
        if (exits !== null) return exits.length;
        if (flow.steps.length === 0) return null;
        const last = flow.steps[flow.steps.length - 1];
        const prim = primitiveForKey(last.intake_goal_key);
        return prim?.max_transitions ?? null;
    }

    function findFlowByClientId(clientId: string) {
        return graph?.flows.find((f) => f.client_id === clientId);
    }

    /**
     * Unified port model the FlowNode renders. Exactly one of three
     * shapes per flow:
     *
     *   - Fixed exits:  one port per declared exit name. Handle id
     *                   is `e${exit_index}`. Connected state
     *                   determined by whether a transition's
     *                   description matches the exit name.
     *   - Unbounded:    one port per existing transition (handle id
     *                   `t${idx}`, connected) plus one trailing "+"
     *                   port (handle id "tnew", disconnected).
     *   - Terminal ([]): no ports at all.
     */
    type PortDescriptor = {
        label: string;
        handle_id: string;
        is_connected: boolean;
        is_fallback: boolean;
        is_add: boolean;
    };

    function buildPorts(flow: FlowDTO): {
        ports: PortDescriptor[];
        exits: string[] | null;
    } {
        const exits = flowExits(flow);
        if (exits === null) {
            // Unbounded: port per transition + "+" add port.
            const ports: PortDescriptor[] = flow.transitions_out.map((t, i) => ({
                label: t.description ?? (t.condition ? 'condition' : 'fallback'),
                handle_id: `t${i}`,
                is_connected: true,
                is_fallback: !t.condition && !t.description,
                is_add: false,
            }));
            ports.push({
                label: '+',
                handle_id: 'tnew',
                is_connected: false,
                is_fallback: false,
                is_add: true,
            });
            return { ports, exits: null };
        }
        // Fixed-exit mode. Build ports in three passes so every
        // transition that exists on the flow ends up visible, even
        // if its description doesn't match a declared exit:
        //
        //   1. For each declared exit, look for the first transition
        //      whose description matches. If found, the port anchors
        //      to that transition (handle_id `t${i}` so the edge
        //      can connect to it). If not, the port is an unfilled
        //      slot (handle_id `e${exitIndex}`) that's draggable to
        //      create a new transition tagged with that exit name.
        //
        //   2. Any transition not claimed by step 1 becomes an
        //      "orphan" port appended after the named exits —
        //      labelled with its raw description so legacy edges
        //      (from before named exits existed, or transitions
        //      created under a different terminal step) stay
        //      visible and editable instead of silently disappearing.
        //
        // Without this, the card's "N next steps" counter could
        // claim 3 transitions while only one port shows up.
        const ports: PortDescriptor[] = [];
        const claimed = new Set<number>();
        exits.forEach((name, exitIdx) => {
            const matchIdx = flow.transitions_out.findIndex(
                (t, i) => !claimed.has(i) && t.description === name,
            );
            if (matchIdx >= 0) {
                claimed.add(matchIdx);
                ports.push({
                    label: name,
                    handle_id: `t${matchIdx}`,
                    is_connected: true,
                    is_fallback: false,
                    is_add: false,
                });
            } else {
                ports.push({
                    label: name,
                    handle_id: `e${exitIdx}`,
                    is_connected: false,
                    is_fallback: false,
                    is_add: false,
                });
            }
        });
        flow.transitions_out.forEach((t, i) => {
            if (claimed.has(i)) return;
            ports.push({
                label: t.description ?? (t.condition ? 'condition' : '(unnamed)'),
                handle_id: `t${i}`,
                is_connected: true,
                is_fallback: true,
                is_add: false,
            });
        });
        return { ports, exits };
    }

    function stepSummary(step: { intake_goal_key: string; step_params: Record<string, unknown> }) {
        const prim = primitiveForKey(step.intake_goal_key);
        const params = step.step_params ?? {};
        let hint: string | null = null;
        if (typeof params.slot === 'string' && params.slot) hint = params.slot;
        else if (Array.isArray(params.include_slots) && params.include_slots.length > 0) {
            hint = (params.include_slots as unknown[]).slice(0, 2).join(', ')
                + (params.include_slots.length > 2 ? ',…' : '');
        } else if (typeof params.destination === 'string' && params.destination) {
            hint = params.destination;
        }
        return {
            intake_goal_key: step.intake_goal_key,
            primitive_name: prim?.name ?? step.intake_goal_key,
            primitive_icon: prim?.icon ?? null,
            primitive_category: prim?.category ?? 'unknown',
            hint,
        };
    }

    function buildViewModel(g: EditorGraph): { nodes: Node[]; edges: Edge[] } {
        // Simple top-down auto-layout for flows without persisted
        // canvas coords: root column, then a row beneath for children.
        const autoX = 280;
        const rowY = [80, 360, 640, 920];
        let unpositioned = 0;

        const ownership = channelOwnership(g);
        const visibleFlows = g.flows.filter((f) => ! isFlowHidden(f.client_id, ownership));
        const visibleIds = new Set(visibleFlows.map((f) => f.client_id));

        const nodeList: Node[] = visibleFlows.map((flow) => {
            const pos = flow.canvas_x !== null && flow.canvas_y !== null
                ? { x: flow.canvas_x, y: flow.canvas_y }
                : {
                      x: autoX + (unpositioned % 3 - 1) * 300,
                      y: rowY[Math.floor(unpositioned++ / 3) % rowY.length],
                  };
            return {
                id: flow.client_id,
                type: 'flow',
                position: pos,
                data: {
                    name: flow.name,
                    description: flow.description,
                    is_entry: flow.is_entry,
                    is_active: flow.is_active,
                    is_channel_trigger: flow.is_channel_trigger,
                    is_action_group: flow.kind === 'action_group',
                    trigger_type: flow.trigger_type,
                    steps: flow.steps.map(stepSummary),
                    transition_count: flow.transitions_out.length,
                    max_transitions: flowMaxTransitions(flow),
                    ports: buildPorts(flow).ports,
                    onEditStep: (stepIndex: number) => {
                        stepEditTarget = { flowId: flow.client_id, stepIndex };
                    },
                    onAddStep: () => {
                        primitivePickerTarget = flow.client_id;
                    },
                    onOpenSettings: () => {
                        flowSettingsTarget = flow.client_id;
                    },
                },
            };
        });

        const edgeList: Edge[] = visibleFlows.flatMap((flow) =>
            flow.transitions_out
                .filter((t) => t.to_flow_client_id !== null && visibleIds.has(t.to_flow_client_id))
                .map((t, i) => ({
                    id: `tr:${flow.client_id}:${i}`,
                    type: 'transition',
                    source: flow.client_id,
                    // Each existing transition has a matching `t${i}`
                    // port on its source flow (buildPorts guarantees
                    // it) — whether as a named-exit slot or as an
                    // orphan port, the anchor is the same.
                    sourceHandle: `t${i}`,
                    target: t.to_flow_client_id!,
                    label: t.description ?? (t.condition ? 'condition' : 'fallback'),
                })),
        );

        return { nodes: nodeList, edges: edgeList };
    }

    $effect(() => {
        if (!graph) return;
        // Rebuild when graph shape changes (flow set, step/transition
        // counts, names, active, entry status, or step hint-relevant
        // params). untrack keeps node-position drags from re-entering.
        const _dep = graph.flows.map((f) =>
            [
                f.client_id,
                f.name,
                f.description ?? '',
                f.is_active,
                f.is_entry,
                f.steps.map((s) => `${s.intake_goal_key}:${JSON.stringify(s.step_params ?? {})}`).join(';'),
                f.transitions_out.length,
            ].join('|'),
        ).join('#');
        // Also re-evaluate when channel filter changes.
        const _filterDep = Array.from(hiddenChannels).sort().join(',');
        void _dep;
        void _filterDep;

        untrack(() => {
            const vm = buildViewModel(graph!);
            const posByClientId: Record<string, { x: number; y: number }> = {};
            for (const n of nodes) posByClientId[n.id] = n.position as { x: number; y: number };
            nodes = vm.nodes.map((n) => ({ ...n, position: posByClientId[n.id] ?? n.position }));
            edges = vm.edges;
        });
    });

    function onConnect(detail: Connection) {
        if (!graph || !detail.source || !detail.target) return;
        if (!isValidConnection(detail)) return;
        const t = gAddTransition(graph, detail.source, detail.target);
        // If the drag originated from a named exit port (e.g. `e1`),
        // stamp the new transition with that exit's name so the edge
        // label / port match survives a round-trip.
        const handleId = detail.sourceHandle ?? '';
        if (handleId.startsWith('e')) {
            const exitIdx = Number(handleId.slice(1));
            const sourceFlow = findFlowByClientId(detail.source);
            if (sourceFlow) {
                const exits = flowExits(sourceFlow);
                if (exits && exits[exitIdx]) {
                    t.description = exits[exitIdx];
                }
            }
        }
    }

    /**
     * Live-validate a would-be connection. xyflow calls this during
     * drag so the edge turns red + refuses to drop when it returns
     * false. We also call it from `onConnect` and `onReconnect` as a
     * second line of defense.
     *
     * Rules:
     *   - No self-loops (source === target). If an author wants a
     *     flow to call itself they can do it via a transition
     *     pointing to a subflow copy — self-loops on the same node
     *     are almost always a mis-drag.
     */
    function isValidConnection(conn: {
        source: string | null;
        target: string | null;
    }): boolean {
        if (!conn.source || !conn.target) return false;
        if (conn.source === conn.target) return false;
        // Enforce the source flow's max-transitions cap. At-cap flows
        // refuse new connections; `onReconnect` rechecks this so a
        // reconnect that would blow the cap also fails.
        const sourceFlow = findFlowByClientId(conn.source);
        if (sourceFlow) {
            const max = flowMaxTransitions(sourceFlow);
            if (max !== null && sourceFlow.transitions_out.length >= max) {
                return false;
            }
        }
        return true;
    }

    // Parse an edge id into (sourceClientId, transitionIndex). Edges
    // not produced by us (if any ever slip in) return null and are
    // left alone.
    function parseEdgeId(id: string): { src: string; idx: number } | null {
        if (!id.startsWith('tr:')) return null;
        const rest = id.slice(3);
        const lastColon = rest.lastIndexOf(':');
        if (lastColon < 0) return null;
        const src = rest.slice(0, lastColon);
        const idx = Number(rest.slice(lastColon + 1));
        if (!Number.isInteger(idx)) return null;
        return { src, idx };
    }

    // Rewire an edge the user dragged to a new endpoint.
    //   - Target change only: update to_flow_client_id in place.
    //   - Source change: move the transition from the old source
    //     flow's transitions_out into the new source flow's.
    function onReconnect(oldEdge: Edge, newConnection: Connection) {
        if (!graph) return;
        const parsed = parseEdgeId(oldEdge.id);
        if (!parsed) return;

        const fromFlow = findFlow(graph, parsed.src);
        if (!fromFlow) return;
        const transition = fromFlow.transitions_out[parsed.idx];
        if (!transition) return;

        const newSource = newConnection.source;
        const newTarget = newConnection.target;
        if (!newSource || !newTarget) return;
        if (!isValidConnection({ source: newSource, target: newTarget })) return;

        if (newSource === parsed.src) {
            // Only target changed — update in place.
            transition.to_flow_client_id = newTarget;
        } else {
            // Source changed — move the row to the new source flow.
            const newFrom = findFlow(graph, newSource);
            if (!newFrom) return;
            fromFlow.transitions_out.splice(parsed.idx, 1);
            newFrom.transitions_out.push({
                ...transition,
                from_flow_client_id: newSource,
                to_flow_client_id: newTarget,
                priority: newFrom.transitions_out.length * 10 + 10,
            });
        }
        markDirty(graph);
    }

    // If the user drops an edge endpoint anywhere except on a valid
    // handle, delete the transition. Matches how React Flow and n8n
    // handle drag-to-delete.
    //
    // `connectionState.isValid` is:
    //   true  — dropped on a valid handle (onReconnect already ran)
    //   false — dropped on an invalid handle (e.g. self-loop)
    //   null  — dropped in empty space, no target at all
    // We want to delete for both `false` and `null` — only a true
    // successful reconnect should keep the edge.
    function onReconnectEnd(
        _event: MouseEvent | TouchEvent,
        edge: Edge,
        _handleType: unknown,
        connectionState: { isValid: boolean | null },
    ) {
        if (!graph) return;
        if (connectionState.isValid === true) return; // successful reconnect
        const parsed = parseEdgeId(edge.id);
        if (!parsed) return;
        const fromFlow = findFlow(graph, parsed.src);
        if (!fromFlow) return;
        fromFlow.transitions_out.splice(parsed.idx, 1);
        markDirty(graph);
    }

    // Keyboard delete — xyflow fires `ondelete` with the set of
    // selected nodes/edges after the user presses Delete/Backspace.
    // We only act on edges for now; deleting a whole flow node via
    // keypress feels too easy to fat-finger, so flow delete still
    // lives in the flow-settings modal.
    function onDelete(params: { edges?: Edge[] }) {
        if (!graph) return;
        const toDelete = params.edges ?? [];
        if (toDelete.length === 0) return;

        // Remove in reverse-index order per-source-flow so splicing
        // doesn't shift the index under the next removal.
        const bySource = new Map<string, number[]>();
        for (const e of toDelete) {
            const parsed = parseEdgeId(e.id);
            if (!parsed) continue;
            const arr = bySource.get(parsed.src) ?? [];
            arr.push(parsed.idx);
            bySource.set(parsed.src, arr);
        }
        for (const [src, indices] of bySource) {
            const flow = findFlow(graph, src);
            if (!flow) continue;
            indices.sort((a, b) => b - a);
            for (const i of indices) flow.transitions_out.splice(i, 1);
        }
        markDirty(graph);
    }

    // ── Drag-and-drop from the palette ────────────────────────────

    const DRAG_MIME = 'application/x-orbital-primitive';

    function dragContainsPrimitive(e: DragEvent): boolean {
        // Chrome/Firefox expose dataTransfer.types as a DOMStringList;
        // we also check the text/plain fallback so Safari (which strips
        // custom MIME types during dragover on some versions) still
        // shows the drop cursor.
        if (!e.dataTransfer) return false;
        return (
            Array.from(e.dataTransfer.types).includes(DRAG_MIME) ||
            Array.from(e.dataTransfer.types).includes('text/plain')
        );
    }

    function onCanvasDragOver(e: DragEvent) {
        if (!dragContainsPrimitive(e)) return;
        e.preventDefault();
        if (e.dataTransfer) e.dataTransfer.dropEffect = 'copy';
        draggingOverCanvas = true;

        // Light up the node under the cursor if there is one.
        clearNodeDropHighlights();
        const nodeEl = (e.target as HTMLElement | null)?.closest('.svelte-flow__node');
        if (nodeEl) nodeEl.classList.add('is-drop-target');
    }

    function onCanvasDragLeave() {
        draggingOverCanvas = false;
        clearNodeDropHighlights();
    }

    function clearNodeDropHighlights() {
        document.querySelectorAll('.svelte-flow__node.is-drop-target')
            .forEach((n) => n.classList.remove('is-drop-target'));
    }

    function onCanvasDrop(e: DragEvent) {
        if (!graph || !dragContainsPrimitive(e)) return;
        e.preventDefault();
        draggingOverCanvas = false;
        clearNodeDropHighlights();

        const primitiveKey =
            e.dataTransfer?.getData(DRAG_MIME) ||
            e.dataTransfer?.getData('text/plain');
        if (!primitiveKey) return;
        const prim = primitiveForKey(primitiveKey);
        if (!prim) return;

        // If the drop landed on an existing flow node, append a step
        // to that flow. Otherwise, drop created a new flow at the
        // cursor position with the primitive as its first step.
        const nodeEl = (e.target as HTMLElement | null)?.closest('.svelte-flow__node') as HTMLElement | null;
        if (nodeEl) {
            const flowClientId = nodeEl.dataset.id;
            if (!flowClientId) return;
            const target = findFlow(graph, flowClientId);
            if (!target) return;
            gAddStep(target, primitiveKey);
            markDirty(graph);
            stepEditTarget = { flowId: target.client_id, stepIndex: target.steps.length - 1 };
            return;
        }

        const pos = screenToFlow
            ? screenToFlow({ x: e.clientX, y: e.clientY })
            : { x: 120, y: 120 };
        const newFlow = gAddFlow(graph, {
            name: prim.name,
            canvas_x: Math.round(pos.x),
            canvas_y: Math.round(pos.y),
        });
        gAddStep(newFlow, primitiveKey);
        markDirty(graph);
        stepEditTarget = { flowId: newFlow.client_id, stepIndex: 0 };
    }

    function onAddFlow() {
        if (!graph) return;
        const flow = gAddFlow(graph);
        flowSettingsTarget = flow.client_id;
    }

    /**
     * Create a reusable action group (kind='action_group'). The
     * Invoke Action Group primitive references these by id. Without
     * this button the only way to author an action group is to
     * create a regular flow, open its settings, and flip the Kind
     * dropdown — doable but hidden.
     */
    function onAddActionGroup() {
        if (!graph) return;
        const flow = gAddFlow(graph, {
            name: 'New action group',
            kind: 'action_group',
        });
        flowSettingsTarget = flow.client_id;
    }

    function onDeleteTargetFlow() {
        if (!graph || !flowSettingsTarget) return;
        if (!confirm('Delete this flow? Any transitions pointing at it will also be removed.')) return;
        gRemoveFlow(graph, flowSettingsTarget);
        flowSettingsTarget = null;
    }

    function onPickPrimitive(key: string) {
        if (!graph || !primitivePickerTarget) return;
        const flow = findFlow(graph, primitivePickerTarget);
        if (!flow) return;
        gAddStep(flow, key);
        markDirty(graph);
        // Open the step editor on the new step so the author can
        // configure params immediately.
        stepEditTarget = { flowId: flow.client_id, stepIndex: flow.steps.length - 1 };
        primitivePickerTarget = null;
    }

    function onRemoveStepInModal() {
        if (!graph || !stepEditTarget) return;
        const flow = findFlow(graph, stepEditTarget.flowId);
        if (!flow) return;
        if (!confirm('Remove this step?')) return;
        gRemoveStep(flow, stepEditTarget.stepIndex);
        markDirty(graph);
        stepEditTarget = null;
    }

    function onMoveStepInModal(dir: -1 | 1) {
        if (!graph || !stepEditTarget) return;
        const flow = findFlow(graph, stepEditTarget.flowId);
        if (!flow) return;
        const newIndex = stepEditTarget.stepIndex + dir;
        if (newIndex < 0 || newIndex >= flow.steps.length) return;
        gMoveStep(flow, stepEditTarget.stepIndex, newIndex);
        markDirty(graph);
        stepEditTarget = { flowId: flow.client_id, stepIndex: newIndex };
    }

    async function onSave() {
        if (!graph) return;
        saving = true;
        try {
            const positions: Record<string, { x: number; y: number }> = {};
            for (const n of nodes) positions[n.id] = n.position as { x: number; y: number };

            const payload = toSavePayload(graph, positions);
            const saved = await saveFlowGraph(graphId, payload);
            graph = fromDTO(saved);
            ({ nodes, edges } = buildViewModel(graph));
            flowSettingsTarget = null;
            primitivePickerTarget = null;
            stepEditTarget = null;
        } catch (e: unknown) {
            alert(`Save failed: ${e instanceof Error ? e.message : String(e)}`);
        } finally {
            saving = false;
        }
    }

    function targetFlow(clientId: string | null) {
        if (!graph || !clientId) return null;
        return findFlow(graph, clientId) ?? null;
    }
</script>

<div class="orbital-toolbar">
    <h1>Flow Editor — {graphName} <span style="color: var(--oflow-muted); font-weight: 400;">· {clientName}</span></h1>

    {#if graph}
        <div class="orbital-channel-filter" role="toolbar" aria-label="Channel visibility">
            {#each CHANNELS as channel}
                <button
                    class="orbital-channel-chip"
                    class:is-on={!hiddenChannels.has(channel)}
                    onclick={() => toggleChannel(channel)}
                    aria-pressed={!hiddenChannels.has(channel)}
                    title={hiddenChannels.has(channel) ? `Show ${CHANNEL_LABEL[channel]} flows` : `Hide ${CHANNEL_LABEL[channel]} flows`}
                >
                    {CHANNEL_LABEL[channel]}
                </button>
            {/each}
        </div>
    {/if}

    <div class="orbital-toolbar__spacer"></div>
    {#if graph}
        <span style="font-size: 0.6875rem; color: var(--oflow-soft);">
            {graph.flows.length} flow{graph.flows.length === 1 ? '' : 's'} ·
            {graph.slots.length} variable{graph.slots.length === 1 ? '' : 's'}
            {#if graph.dirty}<span style="color: #fbbf24; margin-left: 0.375rem;">unsaved</span>{/if}
        </span>
        <button class="orbital-btn" onclick={onAddFlow}>+ Add flow</button>
        <button
            class="orbital-btn"
            onclick={onAddActionGroup}
            title="Create a reusable action group — invoke it from any flow via the Invoke Action Group primitive."
        >+ Add action group</button>
        <button class="orbital-btn is-primary" onclick={onSave} disabled={saving || !graph.dirty}>
            {saving ? 'Saving…' : 'Save'}
        </button>
    {/if}
    <button class="orbital-btn" onclick={() => window.close()}>Close</button>
</div>

{#if loadError}
    <div style="padding: 1.5rem; color: #fca5a5;">Failed to load flow graph: {loadError}</div>
{:else if !graph}
    <div style="padding: 1.5rem; color: var(--oflow-soft);">Loading…</div>
{:else}
    <div class="orbital-shell">
        <aside class="orbital-side-panel">
            <Palette bind:graph />
        </aside>

        <div
            class="orbital-canvas"
            class:is-drop-target={draggingOverCanvas}
            ondragover={onCanvasDragOver}
            ondragleave={onCanvasDragLeave}
            ondrop={onCanvasDrop}
            role="application"
        >
            <SvelteFlow
                bind:nodes
                bind:edges
                {nodeTypes}
                {edgeTypes}
                colorMode="dark"
                fitView
                isValidConnection={isValidConnection}
                onconnect={onConnect}
                onreconnect={onReconnect}
                onreconnectend={onReconnectEnd}
                ondelete={onDelete}
            >
                <Background />
                <Controls />
                <MiniMap zoomable pannable />
                <ScreenToFlowBridge onReady={(fn) => (screenToFlow = fn)} />
            </SvelteFlow>
        </div>
    </div>

    {#if stepEditTarget && targetFlow(stepEditTarget.flowId)}
        {@const flow = targetFlow(stepEditTarget.flowId)!}
        {@const step = flow.steps[stepEditTarget.stepIndex]}
        {@const prim = step ? graph.primitives.find((p) => p.key === step.intake_goal_key) ?? null : null}
        <StepEditModal
            {flow}
            stepIndex={stepEditTarget.stepIndex}
            primitive={prim}
            {graph}
            onClose={() => { markDirty(graph!); stepEditTarget = null; }}
            onRemove={onRemoveStepInModal}
            onMoveUp={() => onMoveStepInModal(-1)}
            onMoveDown={() => onMoveStepInModal(1)}
        />
    {/if}

    {#if flowSettingsTarget && targetFlow(flowSettingsTarget)}
        {@const flow = targetFlow(flowSettingsTarget)!}
        <FlowSettingsModal
            {flow}
            {graph}
            onClose={() => (flowSettingsTarget = null)}
            onDelete={onDeleteTargetFlow}
        />
    {/if}

    {#if primitivePickerTarget && graph}
        <PrimitivePickerModal
            primitives={graph.primitives}
            onPick={onPickPrimitive}
            onClose={() => (primitivePickerTarget = null)}
        />
    {/if}
{/if}
