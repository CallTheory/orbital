<script lang="ts">
    // Generic renderer for one entry in a primitive's `data_fields`
    // descriptor. The step-params object (on the parent step) gets
    // the value written under `field.key`.
    //
    // Mirrors the PHP RendersStepParamsForm mapping: string →
    // TextInput, textarea → Textarea, boolean → toggle, select →
    // <select>, slot_list → multi-pick from client's slots, etc.
    //
    // For unknown / complex types (step_ref, time_window_list,
    // expression) we fall back to a plain text input with a hint —
    // same posture as the server-side fallback.

    import type {
        SlotDTO,
        KnowledgeStoreDTO,
        ExtensionDTO,
        CallQueueDTO,
        EmailQueueDTO,
        AgentPersonaDTO,
        DidDTO,
        FlowDTO,
    } from '../lib/api';
    import ExpressionEditor from './ExpressionEditor.svelte';

    type FieldDescriptor = {
        key: string;
        label?: string;
        type?: string;
        required?: boolean;
        hint?: string;
        options?: string[];
    };

    let {
        field,
        params = $bindable({}),
        slots,
        knowledgeStores,
        extensions = [],
        callQueues = [],
        emailQueues = [],
        agentPersonas = [],
        dids = [],
        flows = [],
        isShared = false,
    }: {
        field: FieldDescriptor;
        params: Record<string, unknown>;
        slots: SlotDTO[];
        knowledgeStores: KnowledgeStoreDTO[];
        extensions?: ExtensionDTO[];
        callQueues?: CallQueueDTO[];
        emailQueues?: EmailQueueDTO[];
        agentPersonas?: AgentPersonaDTO[];
        dids?: DidDTO[];
        flows?: FlowDTO[];
        isShared?: boolean;
    } = $props();

    // Picker types whose values resolve through orchestration_bindings
    // — those need a binding-key input on shared orchestrations
    // instead of a concrete resource picker.
    const PICKER_TYPES = new Set([
        'agent_persona_picker',
        'call_queue_picker',
        'email_queue_picker',
        'extension_picker',
        'did_picker',
        'knowledge_store_list',
    ]);

    const RESOURCE_TYPE_LABEL: Record<string, string> = {
        agent_persona_picker: 'agent persona',
        call_queue_picker: 'call queue',
        email_queue_picker: 'email queue',
        extension_picker: 'extension',
        did_picker: 'DIDs',
        knowledge_store_list: 'knowledge stores',
    };

    const showBindingHandle = $derived(isShared && PICKER_TYPES.has(field.type ?? ''));

    // Action group flows (kind === 'action_group') — surfaced to the
    // `action_group_picker` field type. Derived so the picker updates
    // if the author marks a new flow as an action group elsewhere in
    // the session.
    const actionGroupFlows = $derived(flows.filter((f) => f.kind === 'action_group'));

    const label = $derived(field.label ?? field.key.replace(/_/g, ' '));
    const type = $derived(field.type ?? 'string');

    function update(value: unknown) {
        params[field.key] = value;
    }

    function asString(v: unknown): string {
        return typeof v === 'string' ? v : v == null ? '' : String(v);
    }

    function asBool(v: unknown): boolean {
        return Boolean(v);
    }

    function asNumber(v: unknown): string {
        return typeof v === 'number' ? String(v) : asString(v);
    }

    function asArray(v: unknown): string[] {
        return Array.isArray(v) ? v.map((x) => String(x)) : [];
    }

    function asNumberArray(v: unknown): number[] {
        return Array.isArray(v) ? v.map((x) => Number(x)).filter((x) => !Number.isNaN(x)) : [];
    }
</script>

<label style="display: flex; flex-direction: column; gap: 0.25rem; font-size: 0.75rem;">
    <span style="color: var(--oflow-text); font-weight: 500;">
        {label}{#if field.required}<span style="color: #fca5a5; margin-left: 0.25rem;">*</span>{/if}
    </span>

    {#if showBindingHandle}
        <!-- Shared orchestration: pickers become binding-key inputs.
             The author types a stable handle (e.g. "primary_agent");
             each assigning client supplies their own concrete resource
             via the queue's bindings panel. -->
        <input
            type="text"
            value={asString(params[field.key])}
            oninput={(e) => update((e.currentTarget as HTMLInputElement).value)}
            placeholder={`binding key — ${RESOURCE_TYPE_LABEL[type] ?? 'resource'}`}
            pattern="^[a-z][a-z0-9_]*$"
            style="background: var(--oflow-bg); color: var(--oflow-text); border: 1px solid var(--oflow-border); border-radius: 0.25rem; padding: 0.375rem 0.5rem; font-size: 0.75rem; font-family: var(--oflow-mono, monospace);"
        />
        <span style="color: var(--oflow-muted); font-size: 0.6875rem;">
            Handle for a {RESOURCE_TYPE_LABEL[type] ?? 'resource'}. Each client maps this to one of their own when they assign this orchestration to a queue.
        </span>
    {:else if type === 'textarea'}
        <textarea
            rows="3"
            value={asString(params[field.key])}
            oninput={(e) => update((e.currentTarget as HTMLTextAreaElement).value)}
            style="background: var(--oflow-bg); color: var(--oflow-text); border: 1px solid var(--oflow-border); border-radius: 0.25rem; padding: 0.375rem 0.5rem; font-family: inherit; font-size: 0.75rem; resize: vertical;"
        ></textarea>
    {:else if type === 'expression' || type === 'template'}
        <ExpressionEditor
            value={asString(params[field.key])}
            {slots}
            mode={type === 'expression' ? 'expression' : 'template'}
            placeholder={field.hint ?? ''}
            rows={3}
            onchange={(next) => update(next)}
        />
    {:else if type === 'number'}
        <input
            type="number"
            value={asNumber(params[field.key])}
            oninput={(e) => update(Number((e.currentTarget as HTMLInputElement).value))}
            style="background: var(--oflow-bg); color: var(--oflow-text); border: 1px solid var(--oflow-border); border-radius: 0.25rem; padding: 0.375rem 0.5rem; font-size: 0.75rem;"
        />
    {:else if type === 'boolean'}
        <input
            type="checkbox"
            checked={asBool(params[field.key])}
            onchange={(e) => update((e.currentTarget as HTMLInputElement).checked)}
            style="width: 1rem; height: 1rem; accent-color: #0ea5e9;"
        />
    {:else if type === 'select'}
        <select
            value={asString(params[field.key])}
            onchange={(e) => update((e.currentTarget as HTMLSelectElement).value)}
            style="background: var(--oflow-bg); color: var(--oflow-text); border: 1px solid var(--oflow-border); border-radius: 0.25rem; padding: 0.375rem 0.5rem; font-size: 0.75rem;"
        >
            <option value="">—</option>
            {#each field.options ?? [] as opt}
                <option value={opt}>{opt}</option>
            {/each}
        </select>
    {:else if type === 'string_list'}
        <!-- Free-form list of strings — one per line. Used by
             gather_choice.options and anywhere else the caller is
             picking from a finite set the author wrote. -->
        <textarea
            rows="4"
            value={asArray(params[field.key]).join('\n')}
            oninput={(e) => {
                const raw = (e.currentTarget as HTMLTextAreaElement).value;
                update(raw.split('\n').map((s) => s.trim()).filter((s) => s !== ''));
            }}
            style="background: var(--oflow-bg); color: var(--oflow-text); border: 1px solid var(--oflow-border); border-radius: 0.25rem; padding: 0.375rem 0.5rem; font-family: inherit; font-size: 0.75rem; resize: vertical;"
        ></textarea>
    {:else if type === 'slot_list'}
        <select
            multiple
            onchange={(e) => {
                const target = e.currentTarget as HTMLSelectElement;
                update(Array.from(target.selectedOptions).map((o) => o.value));
            }}
            style="background: var(--oflow-bg); color: var(--oflow-text); border: 1px solid var(--oflow-border); border-radius: 0.25rem; padding: 0.375rem 0.5rem; font-size: 0.75rem; min-height: 4rem;"
        >
            {#each slots as slot}
                <option value={slot.name} selected={asArray(params[field.key]).includes(slot.name)}>
                    {slot.name} ({slot.type})
                </option>
            {/each}
        </select>
    {:else if type === 'knowledge_store_list'}
        <select
            multiple
            onchange={(e) => {
                const target = e.currentTarget as HTMLSelectElement;
                update(Array.from(target.selectedOptions).map((o) => Number(o.value)));
            }}
            style="background: var(--oflow-bg); color: var(--oflow-text); border: 1px solid var(--oflow-border); border-radius: 0.25rem; padding: 0.375rem 0.5rem; font-size: 0.75rem; min-height: 4rem;"
        >
            {#each knowledgeStores as store}
                <option
                    value={store.id}
                    selected={asNumberArray(params[field.key]).includes(store.id)}
                >
                    {store.name}
                </option>
            {/each}
        </select>
    {:else if type === 'extension_picker'}
        <select
            multiple={(field as FieldDescriptor & { multi?: boolean }).multi ?? true}
            onchange={(e) => {
                const target = e.currentTarget as HTMLSelectElement;
                update(Array.from(target.selectedOptions).map((o) => Number(o.value)));
            }}
            style="background: var(--oflow-bg); color: var(--oflow-text); border: 1px solid var(--oflow-border); border-radius: 0.25rem; padding: 0.375rem 0.5rem; font-size: 0.75rem; min-height: 4rem;"
        >
            {#each extensions as ext}
                <option value={ext.id} selected={asNumberArray(params[field.key]).includes(ext.id)}>
                    {ext.number} — {ext.label ?? ext.type}
                </option>
            {/each}
        </select>
    {:else if type === 'call_queue_picker'}
        <select
            value={asString(params[field.key])}
            onchange={(e) => update(Number((e.currentTarget as HTMLSelectElement).value) || null)}
            style="background: var(--oflow-bg); color: var(--oflow-text); border: 1px solid var(--oflow-border); border-radius: 0.25rem; padding: 0.375rem 0.5rem; font-size: 0.75rem;"
        >
            <option value="">—</option>
            {#each callQueues as q}
                <option value={q.id}>{q.name}</option>
            {/each}
        </select>
    {:else if type === 'email_queue_picker'}
        <select
            value={asString(params[field.key])}
            onchange={(e) => update(Number((e.currentTarget as HTMLSelectElement).value) || null)}
            style="background: var(--oflow-bg); color: var(--oflow-text); border: 1px solid var(--oflow-border); border-radius: 0.25rem; padding: 0.375rem 0.5rem; font-size: 0.75rem;"
        >
            <option value="">—</option>
            {#each emailQueues as q}
                <option value={q.id}>{q.name}</option>
            {/each}
        </select>
    {:else if type === 'agent_persona_picker'}
        <select
            value={asString(params[field.key])}
            onchange={(e) => update(Number((e.currentTarget as HTMLSelectElement).value) || null)}
            style="background: var(--oflow-bg); color: var(--oflow-text); border: 1px solid var(--oflow-border); border-radius: 0.25rem; padding: 0.375rem 0.5rem; font-size: 0.75rem;"
        >
            <option value="">—</option>
            {#each agentPersonas as p}
                <option value={p.id}>{p.name}{p.role ? ` — ${p.role}` : ''}</option>
            {/each}
        </select>
    {:else if type === 'action_group_picker'}
        <!-- Picks one flow marked as an action group. Value is the
             flow's real DB id so the server-side compiler can resolve
             it; if the flow is newly-minted and hasn't been saved yet
             the id is null and we fall back to `client_id`. -->
        <select
            value={asString(params[field.key])}
            onchange={(e) => {
                const raw = (e.currentTarget as HTMLSelectElement).value;
                update(raw === '' ? null : (Number.isNaN(Number(raw)) ? raw : Number(raw)));
            }}
            style="background: var(--oflow-bg); color: var(--oflow-text); border: 1px solid var(--oflow-border); border-radius: 0.25rem; padding: 0.375rem 0.5rem; font-size: 0.75rem;"
        >
            <option value="">—</option>
            {#each actionGroupFlows as f}
                <option value={f.id ?? f.client_id}>{f.name}</option>
            {/each}
        </select>
        {#if actionGroupFlows.length === 0}
            <span style="color: var(--oflow-muted); font-size: 0.6875rem; font-style: italic; margin-top: 0.25rem;">
                No action groups defined yet — open a flow's settings and set Kind to "Action group".
            </span>
        {/if}
    {:else if type === 'did_picker'}
        <select
            multiple
            onchange={(e) => {
                const target = e.currentTarget as HTMLSelectElement;
                update(Array.from(target.selectedOptions).map((o) => Number(o.value)));
            }}
            style="background: var(--oflow-bg); color: var(--oflow-text); border: 1px solid var(--oflow-border); border-radius: 0.25rem; padding: 0.375rem 0.5rem; font-size: 0.75rem; min-height: 4rem;"
        >
            {#each dids as did}
                <option value={did.id} selected={asNumberArray(params[field.key]).includes(did.id)}>
                    {did.number}{did.label ? ` — ${did.label}` : ''}
                </option>
            {/each}
        </select>
    {:else if type === 'slot_ref'}
        <!-- Slot reference: pick a single declared slot by name. -->
        <select
            value={asString(params[field.key])}
            onchange={(e) => update((e.currentTarget as HTMLSelectElement).value)}
            style="background: var(--oflow-bg); color: var(--oflow-text); border: 1px solid var(--oflow-border); border-radius: 0.25rem; padding: 0.375rem 0.5rem; font-size: 0.75rem;"
        >
            <option value="">—</option>
            {#each slots as slot}
                <option value={slot.name}>{slot.name}</option>
            {/each}
        </select>
    {:else}
        <!-- Default: free text. Covers `string`, `phone`, `email`,
             `date`, and unknown types we don't have a richer widget
             for yet (step_ref / time_window_list / expression). -->
        <input
            type="text"
            value={asString(params[field.key])}
            oninput={(e) => update((e.currentTarget as HTMLInputElement).value)}
            style="background: var(--oflow-bg); color: var(--oflow-text); border: 1px solid var(--oflow-border); border-radius: 0.25rem; padding: 0.375rem 0.5rem; font-size: 0.75rem;"
        />
    {/if}

    {#if field.hint}
        <span style="color: var(--oflow-muted); font-size: 0.6875rem;">{field.hint}</span>
    {/if}
</label>
