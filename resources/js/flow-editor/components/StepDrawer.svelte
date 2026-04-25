<script lang="ts">
    // Drawer that opens when a flow node is selected. Lets the
    // operator edit the flow's metadata, add / reorder / remove
    // steps, edit per-step parameters, and define outbound
    // transitions (condition + target).

    import type { FlowDTO, PrimitiveDTO, SlotDTO, KnowledgeStoreDTO } from '../lib/api';
    import type { EditorGraph } from '../lib/graph';
    import { addStep, removeStep, moveStep, markDirty } from '../lib/graph';
    import ParamField from './ParamField.svelte';
    import ConditionBuilder from './ConditionBuilder.svelte';

    let {
        flow = $bindable(),
        graph = $bindable(),
        onDelete,
        onClose,
    }: {
        flow: FlowDTO;
        graph: EditorGraph;
        onDelete: () => void;
        onClose: () => void;
    } = $props();

    let showPrimitivePicker = $state(false);

    function addPrimitive(key: string) {
        addStep(flow, key);
        markDirty(graph);
        showPrimitivePicker = false;
    }

    function primitiveFor(key: string): PrimitiveDTO | undefined {
        return graph.primitives.find((p) => p.key === key);
    }

    function dropStep(index: number) {
        if (!confirm('Remove this step?')) return;
        removeStep(flow, index);
        markDirty(graph);
    }

    function nudgeStep(from: number, dir: -1 | 1) {
        moveStep(flow, from, from + dir);
        markDirty(graph);
    }

    function addTransition() {
        flow.transitions_out.push({
            id: null,
            from_flow_client_id: flow.client_id,
            to_flow_client_id: null,
            condition: null,
            description: null,
            priority: flow.transitions_out.length * 10 + 10,
            source_handle: null,
        });
        markDirty(graph);
    }

    function removeTransitionAt(i: number) {
        flow.transitions_out.splice(i, 1);
        markDirty(graph);
    }

    function otherFlows() {
        return graph.flows.filter((f) => f.client_id !== flow.client_id);
    }
</script>

<aside class="orbital-editor-panel">
    <div class="orbital-editor-panel__header">
        <h2 class="orbital-editor-panel__title">Edit flow</h2>
        <button
            type="button"
            class="orbital-editor-panel__close"
            onclick={onClose}
            aria-label="Close editor"
        >×</button>
    </div>

    <div style="display: flex; flex-direction: column; gap: 0.5rem;">
        <label style="display: flex; flex-direction: column; gap: 0.25rem;">
            <span style="font-size: 0.6875rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: var(--oflow-soft);">Name</span>
            <input
                bind:value={flow.name}
                oninput={() => markDirty(graph)}
                style="background: var(--oflow-bg); color: var(--oflow-strong); border: 1px solid var(--oflow-border); border-radius: 0.375rem; padding: 0.5rem 0.625rem; font-size: 0.9375rem; font-weight: 600;"
            />
        </label>

        <label style="display: flex; flex-direction: column; gap: 0.25rem;">
            <span style="font-size: 0.6875rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: var(--oflow-soft);">Description</span>
            <input
                bind:value={flow.description}
                oninput={() => markDirty(graph)}
                placeholder="What this flow does…"
                style="background: var(--oflow-bg); color: var(--oflow-text); border: 1px solid var(--oflow-border); border-radius: 0.375rem; padding: 0.4375rem 0.625rem;"
            />
        </label>

        <div style="display: flex; gap: 0.75rem; align-items: center; justify-content: space-between;">
            <label style="display: flex; align-items: center; gap: 0.375rem; color: var(--oflow-text);">
                <input
                    type="checkbox"
                    bind:checked={flow.is_active}
                    onchange={() => markDirty(graph)}
                    style="accent-color: #0ea5e9; width: 1rem; height: 1rem;"
                />
                Active
            </label>
            <button class="orbital-btn" onclick={onDelete} style="color: #fca5a5;">Delete flow</button>
        </div>
    </div>

    <h3 class="orbital-panel-heading">Steps</h3>
    {#if flow.steps.length === 0}
        <div style="font-size: 0.75rem; color: var(--oflow-muted); font-style: italic; margin-bottom: 0.625rem;">
            No steps yet. Add one below.
        </div>
    {:else}
        <div style="display: flex; flex-direction: column; gap: 0.625rem; margin-bottom: 0.75rem;">
            {#each flow.steps as step, i (step.id ?? `new-${i}`)}
                {@const prim = primitiveFor(step.intake_goal_key)}
                <div style="background: var(--oflow-bg); border: 1px solid var(--oflow-border); border-radius: 0.375rem; padding: 0.625rem;">
                    <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.5rem;">
                        <span style="font-size: 0.6875rem; color: var(--oflow-soft); font-family: ui-monospace, monospace;">
                            {i + 1}.
                        </span>
                        <span style="font-weight: 600; font-size: 0.8125rem;">{prim?.name ?? step.intake_goal_key}</span>
                        <span style="font-size: 0.6875rem; color: var(--oflow-muted); font-family: ui-monospace, monospace;">{step.intake_goal_key}</span>
                        <span style="flex: 1;"></span>
                        <button class="orbital-btn" onclick={() => nudgeStep(i, -1)} disabled={i === 0}>↑</button>
                        <button class="orbital-btn" onclick={() => nudgeStep(i, 1)} disabled={i === flow.steps.length - 1}>↓</button>
                        <button class="orbital-btn" onclick={() => dropStep(i)} style="color: #fca5a5;">×</button>
                    </div>
                    {#if prim && prim.data_fields.length > 0}
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem;">
                            {#each prim.data_fields as f}
                                <ParamField
                                    field={f}
                                    bind:params={step.step_params}
                                    slots={graph.slots}
                                    knowledgeStores={graph.knowledgeStores}
                                    extensions={graph.extensions}
                                    callQueues={graph.callQueues}
                                    emailQueues={graph.emailQueues}
                                    agentPersonas={graph.agentPersonas}
                                    dids={graph.dids}
                                />
                            {/each}
                        </div>
                    {/if}
                </div>
            {/each}
        </div>
    {/if}

    {#if showPrimitivePicker}
        <div style="background: var(--oflow-bg); border: 1px solid var(--oflow-border); border-radius: 0.375rem; padding: 0.625rem; margin-bottom: 0.75rem;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <strong style="font-size: 0.8125rem;">Pick a primitive</strong>
                <button class="orbital-btn" onclick={() => (showPrimitivePicker = false)}>Cancel</button>
            </div>
            {#each ['intake', 'action', 'control'] as category}
                {@const inCategory = graph.primitives.filter((p) => p.category === category)}
                {#if inCategory.length > 0}
                    <div style="margin-bottom: 0.5rem;">
                        <div style="font-size: 0.625rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--oflow-soft); margin-bottom: 0.25rem;">
                            {category}
                        </div>
                        <div style="display: flex; flex-wrap: wrap; gap: 0.375rem;">
                            {#each inCategory as prim}
                                <button class="orbital-btn" onclick={() => addPrimitive(prim.key)}>
                                    {prim.name}
                                </button>
                            {/each}
                        </div>
                    </div>
                {/if}
            {/each}
        </div>
    {:else}
        <button class="orbital-btn" onclick={() => (showPrimitivePicker = true)} style="margin-bottom: 0.75rem;">+ Add step</button>
    {/if}

    <h3 class="orbital-panel-heading">Next step (transitions)</h3>
    {#if flow.transitions_out.length === 0}
        <div style="font-size: 0.75rem; color: var(--oflow-muted); font-style: italic; margin-bottom: 0.625rem;">
            No transitions — this flow ends the call.
        </div>
    {:else}
        <div style="display: flex; flex-direction: column; gap: 0.5rem; margin-bottom: 0.75rem;">
            {#each flow.transitions_out as t, i (t.id ?? `new-${i}`)}
                <div style="background: var(--oflow-bg); border: 1px solid var(--oflow-border); border-radius: 0.375rem; padding: 0.625rem;">
                    <div style="display: flex; gap: 0.375rem; align-items: center; margin-bottom: 0.5rem;">
                        <span style="font-size: 0.6875rem; color: var(--oflow-soft);">priority</span>
                        <input
                            type="number"
                            bind:value={t.priority}
                            oninput={() => markDirty(graph)}
                            style="width: 4rem; background: var(--oflow-surface-2); color: var(--oflow-text); border: 1px solid var(--oflow-border); border-radius: 0.25rem; padding: 0.25rem 0.375rem; font-size: 0.75rem;"
                        />
                        <select
                            value={t.to_flow_client_id ?? ''}
                            onchange={(e) => { t.to_flow_client_id = (e.currentTarget as HTMLSelectElement).value || null; markDirty(graph); }}
                            style="background: var(--oflow-surface-2); color: var(--oflow-text); border: 1px solid var(--oflow-border); border-radius: 0.25rem; padding: 0.25rem 0.375rem; font-size: 0.75rem;"
                        >
                            <option value="">End call</option>
                            {#each otherFlows() as f}
                                <option value={f.client_id}>→ {f.name}</option>
                            {/each}
                        </select>
                        <input
                            placeholder="Label…"
                            bind:value={t.description}
                            oninput={() => markDirty(graph)}
                            style="flex: 1; background: var(--oflow-surface-2); color: var(--oflow-text); border: 1px solid var(--oflow-border); border-radius: 0.25rem; padding: 0.25rem 0.375rem; font-size: 0.75rem;"
                        />
                        <button class="orbital-btn" onclick={() => removeTransitionAt(i)} style="color: #fca5a5;">×</button>
                    </div>
                    <ConditionBuilder
                        bind:condition={t.condition}
                        slots={graph.slots}
                        onChange={() => markDirty(graph)}
                    />
                </div>
            {/each}
        </div>
    {/if}

    <button class="orbital-btn" onclick={addTransition}>+ Add transition</button>
</aside>
