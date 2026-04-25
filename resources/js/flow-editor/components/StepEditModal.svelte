<script lang="ts">
    // Full-size modal for editing a single step's parameters. Renders
    // the primitive's `data_fields` schema in a one- or two-column
    // grid depending on how much room the viewport gives us.

    import type { FlowDTO, PrimitiveDTO } from '../lib/api';
    import type { EditorGraph } from '../lib/graph';
    import Modal from './Modal.svelte';
    import ParamField from './ParamField.svelte';

    let {
        flow,
        stepIndex,
        primitive,
        graph,
        onClose,
        onRemove,
        onMoveUp,
        onMoveDown,
    }: {
        flow: FlowDTO;
        stepIndex: number;
        primitive: PrimitiveDTO | null;
        graph: EditorGraph;
        onClose: () => void;
        onRemove: () => void;
        onMoveUp: () => void;
        onMoveDown: () => void;
    } = $props();

    const step = $derived(flow.steps[stepIndex]);
</script>

{#if step && primitive}
    <Modal
        title={`Step ${stepIndex + 1} — ${primitive.name}`}
        {onClose}
        huge
    >
        {#snippet children()}
            {#if primitive.description}
                <p class="orbital-step-edit__description">{primitive.description}</p>
            {/if}

            <div class="orbital-step-edit__toolbar">
                <button
                    class="orbital-btn"
                    onclick={onMoveUp}
                    disabled={stepIndex === 0}
                >↑ Move up</button>
                <button
                    class="orbital-btn"
                    onclick={onMoveDown}
                    disabled={stepIndex === flow.steps.length - 1}
                >↓ Move down</button>
                <span style="flex: 1;"></span>
                <button
                    class="orbital-btn"
                    onclick={onRemove}
                    style="color: var(--oflow-danger);"
                >Remove step</button>
            </div>

            {#if primitive.data_fields.length === 0}
                <div class="orbital-step-edit__empty">
                    This primitive has no parameters to configure.
                </div>
            {:else}
                <div class="orbital-step-edit__grid">
                    {#each primitive.data_fields as f}
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
                            flows={graph.flows}
                        />
                    {/each}
                </div>
            {/if}

            <div class="orbital-step-edit__footer">
                <button class="orbital-btn is-primary" onclick={onClose}>Done</button>
            </div>
        {/snippet}
    </Modal>
{/if}
