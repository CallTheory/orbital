<script lang="ts">
    // Flow-level metadata + outbound transitions editor. Opens when
    // the user clicks the gear icon on a flow card. Contains:
    //  - name / description / is_active
    //  - outbound transitions (list with condition builder)
    //  - delete-flow action
    //
    // Transitions stay here (not on edge-click) because authoring
    // multiple conditions + priorities needs more space than a
    // hover popover.

    import type { FlowDTO, SlotDTO, RuleTriggerEvent } from '../lib/api';
    import type { EditorGraph } from '../lib/graph';
    import { markDirty, addRule, removeRule } from '../lib/graph';
    import Modal from './Modal.svelte';
    import ConditionBuilder from './ConditionBuilder.svelte';
    import ExpressionEditor from './ExpressionEditor.svelte';

    let {
        flow,
        graph,
        onClose,
        onDelete,
    }: {
        flow: FlowDTO;
        graph: EditorGraph;
        onClose: () => void;
        onDelete: () => void;
    } = $props();

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

    // ── Rules (Phase 4) ───────────────────────────────────────────

    const TRIGGER_OPTIONS: Array<{ value: RuleTriggerEvent; label: string }> = [
        { value: 'on_field_set',     label: 'when a slot is set' },
        { value: 'on_step_enter',    label: 'when a step starts' },
        { value: 'on_step_complete', label: 'when a step finishes' },
        { value: 'on_flow_start',    label: 'when this flow begins' },
        { value: 'on_flow_end',      label: 'when this flow ends' },
    ];

    function onAddRule() {
        addRule(flow);
        markDirty(graph);
    }

    function onRemoveRule(i: number) {
        if (!confirm('Remove this rule?')) return;
        removeRule(flow, i);
        markDirty(graph);
    }
</script>

<Modal title="Flow settings" {onClose} wide>
    {#snippet children()}
        <div style="display: flex; flex-direction: column; gap: 0.75rem;">
            <label style="display: flex; flex-direction: column; gap: 0.25rem;">
                <span style="font-size: 0.6875rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: var(--oflow-soft);">Name</span>
                <input
                    bind:value={flow.name}
                    oninput={() => markDirty(graph)}
                    style="background: var(--oflow-bg); color: var(--oflow-strong); border: 1px solid var(--oflow-border); border-radius: 0.375rem; padding: 0.4375rem 0.625rem; font-size: 0.9375rem; font-weight: 600;"
                />
            </label>

            <label style="display: flex; flex-direction: column; gap: 0.25rem;">
                <span style="font-size: 0.6875rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: var(--oflow-soft);">Description</span>
                <textarea
                    bind:value={flow.description}
                    oninput={() => markDirty(graph)}
                    rows="2"
                    placeholder="What this flow does…"
                    style="background: var(--oflow-bg); color: var(--oflow-text); border: 1px solid var(--oflow-border); border-radius: 0.375rem; padding: 0.4375rem 0.625rem; font-family: inherit; resize: vertical;"
                ></textarea>
            </label>

            <label style="display: flex; flex-direction: column; gap: 0.25rem;">
                <span style="font-size: 0.6875rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: var(--oflow-soft);">Kind</span>
                <select
                    bind:value={flow.kind}
                    onchange={() => markDirty(graph)}
                    disabled={flow.is_channel_trigger}
                    title={flow.is_channel_trigger ? 'Channel-trigger flows are always call flows.' : ''}
                    style="background: var(--oflow-bg); color: var(--oflow-text); border: 1px solid var(--oflow-border); border-radius: 0.375rem; padding: 0.4375rem 0.625rem; font-size: 0.8125rem;"
                >
                    <option value="call_flow">Call flow (runs when triggered or transitioned to)</option>
                    <option value="action_group">Action group (reusable — invoked via Call Action Group step)</option>
                </select>
            </label>

            <label style="display: flex; align-items: center; gap: 0.375rem; color: var(--oflow-text);">
                <input
                    type="checkbox"
                    bind:checked={flow.is_active}
                    onchange={() => markDirty(graph)}
                    style="accent-color: #0ea5e9; width: 1rem; height: 1rem;"
                />
                Active
            </label>
        </div>

        <h3 class="orbital-panel-heading" style="margin-top: 1.25rem;">Next step (transitions)</h3>

        {#if flow.transitions_out.length === 0}
            <div style="font-size: 0.8125rem; color: var(--oflow-muted); font-style: italic; margin-bottom: 0.625rem;">
                No transitions — this flow ends the call when its steps finish.
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

        <h3 class="orbital-panel-heading" style="margin-top: 1.25rem;">Reactive rules</h3>
        <div style="font-size: 0.75rem; color: var(--oflow-muted); margin-bottom: 0.625rem;">
            Rules fire continuously as the flow runs. Use them for
            branchy logic that doesn't belong in the main step chain —
            e.g. "when <code style="color: var(--oflow-text);">caller_state == &quot;FL&quot;</code>,
            tell the caller about the Florida disclosure".
        </div>

        {#if (flow.rules ?? []).length === 0}
            <div style="font-size: 0.8125rem; color: var(--oflow-muted); font-style: italic; margin-bottom: 0.625rem;">
                No rules yet.
            </div>
        {:else}
            <div style="display: flex; flex-direction: column; gap: 0.5rem; margin-bottom: 0.75rem;">
                {#each flow.rules as rule, i (rule.id ?? `new-${i}`)}
                    <div style="background: var(--oflow-bg); border: 1px solid var(--oflow-border); border-radius: 0.375rem; padding: 0.625rem;">
                        <div style="display: flex; gap: 0.375rem; align-items: center; margin-bottom: 0.5rem;">
                            <input
                                type="checkbox"
                                bind:checked={rule.is_active}
                                onchange={() => markDirty(graph)}
                                title="Active?"
                                style="accent-color: #0ea5e9; width: 1rem; height: 1rem;"
                            />
                            <input
                                type="number"
                                bind:value={rule.priority}
                                oninput={() => markDirty(graph)}
                                title="Priority"
                                style="width: 4rem; background: var(--oflow-surface-2); color: var(--oflow-text); border: 1px solid var(--oflow-border); border-radius: 0.25rem; padding: 0.25rem 0.375rem; font-size: 0.75rem;"
                            />
                            <select
                                bind:value={rule.trigger_event}
                                onchange={() => markDirty(graph)}
                                style="background: var(--oflow-surface-2); color: var(--oflow-text); border: 1px solid var(--oflow-border); border-radius: 0.25rem; padding: 0.25rem 0.375rem; font-size: 0.75rem;"
                            >
                                {#each TRIGGER_OPTIONS as opt}
                                    <option value={opt.value}>{opt.label}</option>
                                {/each}
                            </select>
                            <input
                                placeholder="Label…"
                                bind:value={rule.label}
                                oninput={() => markDirty(graph)}
                                style="flex: 1; background: var(--oflow-surface-2); color: var(--oflow-text); border: 1px solid var(--oflow-border); border-radius: 0.25rem; padding: 0.25rem 0.375rem; font-size: 0.75rem;"
                            />
                            <button class="orbital-btn" onclick={() => onRemoveRule(i)} style="color: #fca5a5;">×</button>
                        </div>

                        <div style="display: flex; flex-direction: column; gap: 0.375rem;">
                            <label style="font-size: 0.6875rem; color: var(--oflow-soft); text-transform: uppercase; letter-spacing: 0.05em; font-weight: 600;">
                                Condition (optional)
                            </label>
                            <ExpressionEditor
                                value={rule.condition}
                                slots={graph.slots}
                                mode="expression"
                                rows={2}
                                placeholder={'e.g. caller_state == "FL"'}
                                onchange={(next) => { rule.condition = next; markDirty(graph); }}
                            />

                            <label style="font-size: 0.6875rem; color: var(--oflow-soft); text-transform: uppercase; letter-spacing: 0.05em; font-weight: 600; margin-top: 0.375rem;">
                                Action (what the AI should do)
                            </label>
                            <ExpressionEditor
                                value={rule.action_prompt}
                                slots={graph.slots}
                                mode="template"
                                rows={2}
                                placeholder={'e.g. Remind the caller about the {{ caller_state }} disclosure.'}
                                onchange={(next) => { rule.action_prompt = next; markDirty(graph); }}
                            />
                        </div>
                    </div>
                {/each}
            </div>
        {/if}

        <button class="orbital-btn" onclick={onAddRule}>+ Add rule</button>

        <div style="display: flex; justify-content: space-between; margin-top: 1.25rem; padding-top: 1rem; border-top: 1px solid var(--oflow-border);">
            <button class="orbital-btn" onclick={onDelete} style="color: #fca5a5;">Delete flow</button>
            <button class="orbital-btn is-primary" onclick={onClose}>Done</button>
        </div>
    {/snippet}
</Modal>
