// Thin fetch wrapper for the flow-graph API. Session-cookie auth
// (same-origin), CSRF handling via the `XSRF-TOKEN` cookie that
// Laravel sets — we read it and put the value into the
// `X-XSRF-TOKEN` request header.

export type SlotDTO = {
    id: number | null;
    name: string;
    type: string;
    choices: string[] | null;
    description: string | null;
};

export type StepDTO = {
    id: number | null;
    intake_goal_id: number | null;
    intake_goal_key: string;
    position: number;
    step_params: Record<string, unknown>;
};

export type TransitionDTO = {
    id: number | null;
    from_flow_client_id: string;
    to_flow_client_id: string | null;
    condition: Record<string, unknown> | null;
    description: string | null;
    priority: number;
    source_handle: string | null;
};

export type RuleTriggerEvent =
    | 'on_field_set'
    | 'on_step_enter'
    | 'on_step_complete'
    | 'on_flow_start'
    | 'on_flow_end';

export type RuleDTO = {
    id: number | null;
    step_id: number | null;
    trigger_event: RuleTriggerEvent;
    label: string | null;
    condition: string | null;       // expression (Phase 2 syntax)
    action_prompt: string | null;   // template (Phase 2 syntax)
    priority: number;
    is_active: boolean;
};

export type FlowDTO = {
    id: number | null;
    client_id: string;
    name: string;
    description: string | null;
    is_active: boolean;
    is_entry: boolean;
    trigger_type: string | null;
    kind: string;
    is_channel_trigger: boolean;
    display_order: number;
    canvas_x: number | null;
    canvas_y: number | null;
    steps: StepDTO[];
    transitions_out: TransitionDTO[];
    rules: RuleDTO[];
};

export type PrimitiveDTO = {
    id: number;
    key: string;
    name: string;
    category: string;
    icon: string | null;
    description: string | null;
    data_fields: Array<Record<string, unknown>>;
    // Maximum outgoing transitions allowed when this primitive is the
    // terminal step of a flow. null = unlimited. 0 = terminal (no
    // further routing — caller has been handed off).
    max_transitions: number | null;
    // Named exits for the primitive. `null` = unbounded (author adds
    // transitions ad-hoc); `[]` = terminal; `[...names]` = fixed
    // ordered set rendered one-port-per-name.
    exits: string[] | null;
};

export type KnowledgeStoreDTO = {
    id: number;
    name: string;
};

export type ExtensionDTO = { id: number; number: string; label: string | null; type: string };
export type CallQueueDTO = { id: number; name: string; strategy: string | null };
export type EmailQueueDTO = { id: number; name: string };
export type AgentPersonaDTO = { id: number; name: string; role: string | null };
export type DidDTO = { id: number; number: string; label: string | null };

export type FlowGraphDTO = {
    client: { id: number; name: string };
    slots: SlotDTO[];
    flows: FlowDTO[];
    primitives: PrimitiveDTO[];
    knowledge_stores: KnowledgeStoreDTO[];
    extensions: ExtensionDTO[];
    call_queues: CallQueueDTO[];
    email_queues: EmailQueueDTO[];
    agent_personas: AgentPersonaDTO[];
    dids: DidDTO[];
};

function getCsrfToken(): string {
    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
}

async function request<T>(url: string, init: RequestInit = {}): Promise<T> {
    const headers = new Headers(init.headers ?? {});
    headers.set('Accept', 'application/json');
    headers.set('X-Requested-With', 'XMLHttpRequest');
    if (init.method && init.method !== 'GET') {
        headers.set('Content-Type', 'application/json');
        headers.set('X-XSRF-TOKEN', getCsrfToken());
    }
    const response = await fetch(url, { ...init, credentials: 'same-origin', headers });
    if (!response.ok) {
        const body = await response.text();
        throw new Error(`API ${response.status} ${response.statusText}: ${body}`);
    }
    return (await response.json()) as T;
}

export function fetchFlowGraph(graphId: number): Promise<FlowGraphDTO> {
    return request(`/api/admin/flow-graphs/${graphId}`);
}

export function saveFlowGraph(
    graphId: number,
    payload: {
        slots: SlotDTO[];
        flows: Array<Omit<FlowDTO, 'transitions_out'>>;
        transitions: TransitionDTO[];
    },
): Promise<FlowGraphDTO> {
    return request(`/api/admin/flow-graphs/${graphId}`, {
        method: 'PUT',
        body: JSON.stringify(payload),
    });
}
