// Editable graph state + save payload builder.
//
// The Svelte components mutate this store directly (Svelte 5 $state).
// `dirty` flips true on any mutation; the toolbar Save button reads
// this to warn on beforeunload and to enable/disable itself.
//
// Save builds the three-section payload (slots, flows, transitions)
// the PUT endpoint expects — with canvas positions pulled from the
// Svelte Flow node objects so we don't need to mirror them into the
// store on every drag.

import type {
    FlowGraphDTO,
    FlowDTO,
    SlotDTO,
    StepDTO,
    TransitionDTO,
    RuleDTO,
} from './api';

let nextClientIdSeq = 0;
export function nextClientId(prefix = 'new'): string {
    nextClientIdSeq++;
    return `${prefix}-${nextClientIdSeq}`;
}

export type EditorGraph = {
    client: { id: number; name: string };
    slots: SlotDTO[];
    flows: FlowDTO[];
    primitives: FlowGraphDTO['primitives'];
    knowledgeStores: FlowGraphDTO['knowledge_stores'];
    extensions: FlowGraphDTO['extensions'];
    callQueues: FlowGraphDTO['call_queues'];
    emailQueues: FlowGraphDTO['email_queues'];
    agentPersonas: FlowGraphDTO['agent_personas'];
    dids: FlowGraphDTO['dids'];
    dirty: boolean;
};

export function fromDTO(dto: FlowGraphDTO): EditorGraph {
    return {
        client: dto.client,
        slots: dto.slots.map((s) => ({ ...s })),
        flows: dto.flows.map((f) => ({
            ...f,
            client_id: f.client_id ?? String(f.id),
            steps: f.steps.map((s) => ({ ...s, step_params: { ...(s.step_params ?? {}) } })),
            transitions_out: f.transitions_out.map((t) => ({ ...t })),
            rules: (f.rules ?? []).map((r) => ({ ...r })),
        })),
        primitives: dto.primitives,
        knowledgeStores: dto.knowledge_stores,
        extensions: dto.extensions ?? [],
        callQueues: dto.call_queues ?? [],
        emailQueues: dto.email_queues ?? [],
        agentPersonas: dto.agent_personas ?? [],
        dids: dto.dids ?? [],
        dirty: false,
    };
}

export function toSavePayload(graph: EditorGraph, nodePositions: Record<string, { x: number; y: number }>) {
    return {
        slots: graph.slots,
        flows: graph.flows.map((f) => {
            const pos = nodePositions[f.client_id];
            return {
                id: f.id,
                client_id: f.client_id,
                name: f.name,
                description: f.description,
                is_active: f.is_active,
                is_entry: f.is_entry,
                trigger_type: f.trigger_type,
                kind: f.kind,
                display_order: f.display_order,
                canvas_x: pos ? Math.round(pos.x) : f.canvas_x,
                canvas_y: pos ? Math.round(pos.y) : f.canvas_y,
                steps: f.steps,
                rules: f.rules ?? [],
            };
        }),
        transitions: graph.flows.flatMap((f) => f.transitions_out),
    };
}

export function markDirty(graph: EditorGraph): void {
    graph.dirty = true;
}

export function addFlow(graph: EditorGraph, partial?: Partial<FlowDTO>): FlowDTO {
    const flow: FlowDTO = {
        id: null,
        client_id: nextClientId('flow'),
        name: partial?.name ?? 'New flow',
        description: partial?.description ?? null,
        is_active: true,
        is_entry: false,
        trigger_type: partial?.trigger_type ?? 'subflow',
        kind: partial?.kind ?? 'call_flow',
        is_channel_trigger: partial?.is_channel_trigger ?? false,
        display_order: partial?.display_order ?? 0,
        canvas_x: partial?.canvas_x ?? 120,
        canvas_y: partial?.canvas_y ?? 120,
        steps: [],
        transitions_out: [],
        rules: [],
    };
    graph.flows.push(flow);
    markDirty(graph);
    return flow;
}

export function removeFlow(graph: EditorGraph, clientId: string): void {
    graph.flows = graph.flows.filter((f) => f.client_id !== clientId);
    for (const f of graph.flows) {
        f.transitions_out = f.transitions_out.filter((t) => t.to_flow_client_id !== clientId);
    }
    markDirty(graph);
}

export function findFlow(graph: EditorGraph, clientId: string): FlowDTO | undefined {
    return graph.flows.find((f) => f.client_id === clientId);
}

export function addStep(flow: FlowDTO, primitiveKey: string): StepDTO {
    const step: StepDTO = {
        id: null,
        intake_goal_id: null,
        intake_goal_key: primitiveKey,
        position: flow.steps.length,
        step_params: {},
    };
    flow.steps.push(step);
    return step;
}

export function removeStep(flow: FlowDTO, index: number): void {
    flow.steps.splice(index, 1);
    flow.steps.forEach((s, i) => (s.position = i));
}

export function moveStep(flow: FlowDTO, from: number, to: number): void {
    if (to < 0 || to >= flow.steps.length || from === to) return;
    const [item] = flow.steps.splice(from, 1);
    flow.steps.splice(to, 0, item);
    flow.steps.forEach((s, i) => (s.position = i));
}

export function addTransition(
    graph: EditorGraph,
    fromClientId: string,
    toClientId: string | null,
): TransitionDTO {
    const source = findFlow(graph, fromClientId);
    if (!source) throw new Error(`unknown source flow ${fromClientId}`);
    const t: TransitionDTO = {
        id: null,
        from_flow_client_id: fromClientId,
        to_flow_client_id: toClientId,
        condition: null,
        description: null,
        priority: source.transitions_out.length * 10 + 10,
        source_handle: null,
    };
    source.transitions_out.push(t);
    markDirty(graph);
    return t;
}

export function removeTransition(graph: EditorGraph, fromClientId: string, index: number): void {
    const source = findFlow(graph, fromClientId);
    if (!source) return;
    source.transitions_out.splice(index, 1);
    markDirty(graph);
}

export function addRule(flow: FlowDTO, partial?: Partial<RuleDTO>): RuleDTO {
    const rule: RuleDTO = {
        id: null,
        step_id: partial?.step_id ?? null,
        trigger_event: partial?.trigger_event ?? 'on_field_set',
        label: partial?.label ?? null,
        condition: partial?.condition ?? null,
        action_prompt: partial?.action_prompt ?? null,
        priority: partial?.priority ?? (flow.rules?.length ?? 0) * 10 + 100,
        is_active: partial?.is_active ?? true,
    };
    flow.rules = [...(flow.rules ?? []), rule];
    return rule;
}

export function removeRule(flow: FlowDTO, index: number): void {
    flow.rules = (flow.rules ?? []).filter((_, i) => i !== index);
}

export function addSlot(graph: EditorGraph, partial?: Partial<SlotDTO>): SlotDTO {
    const slot: SlotDTO = {
        id: null,
        name: partial?.name ?? 'new_slot',
        type: partial?.type ?? 'string',
        choices: partial?.choices ?? null,
        description: partial?.description ?? null,
    };
    graph.slots.push(slot);
    markDirty(graph);
    return slot;
}

export function removeSlot(graph: EditorGraph, name: string): void {
    graph.slots = graph.slots.filter((s) => s.name !== name);
    markDirty(graph);
}
