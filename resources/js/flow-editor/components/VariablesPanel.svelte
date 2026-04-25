<script lang="ts">
    // The left-side variables panel. Declared slots for this client;
    // editing here updates `graph.slots` and marks dirty. The Svelte
    // Flow canvas reads slots for condition-builder dropdowns and
    // for the `gather_*` step params' slot pickers.

    import type { SlotDTO } from '../lib/api';
    import type { EditorGraph } from '../lib/graph';
    import { addSlot, removeSlot, markDirty } from '../lib/graph';

    let { graph = $bindable() }: { graph: EditorGraph } = $props();

    const TYPES = ['string', 'phone', 'email', 'number', 'boolean', 'date', 'choice'];

    function add() {
        addSlot(graph);
    }
    function drop(slot: SlotDTO) {
        if (!confirm(`Remove variable "${slot.name}"?`)) return;
        removeSlot(graph, slot.name);
    }
</script>

<h2 class="orbital-panel-heading">Variables</h2>

{#if graph.slots.length === 0}
    <div style="font-size: 0.75rem; color: var(--oflow-muted); font-style: italic; margin-bottom: 0.5rem;">
        No variables declared.
    </div>
{:else}
    <div style="display: flex; flex-direction: column; gap: 0.5rem;">
        {#each graph.slots as slot, i (slot.id ?? `new-${i}`)}
            <div style="background: var(--oflow-bg); border: 1px solid var(--oflow-border); border-radius: 0.375rem; padding: 0.5rem;">
                <div style="display: flex; gap: 0.25rem; align-items: center; margin-bottom: 0.25rem;">
                    <input
                        bind:value={slot.name}
                        oninput={() => markDirty(graph)}
                        pattern="[a-z][a-z0-9_]*"
                        style="flex: 1; background: var(--oflow-surface-2); color: var(--oflow-text); border: 1px solid var(--oflow-border); border-radius: 0.25rem; padding: 0.1875rem 0.375rem; font-family: ui-monospace, monospace; font-size: 0.75rem;"
                    />
                    <button class="orbital-btn" onclick={() => drop(slot)} style="color: #fca5a5; padding: 0.125rem 0.375rem;">×</button>
                </div>
                <select
                    bind:value={slot.type}
                    onchange={() => markDirty(graph)}
                    style="width: 100%; background: var(--oflow-surface-2); color: var(--oflow-text); border: 1px solid var(--oflow-border); border-radius: 0.25rem; padding: 0.1875rem 0.375rem; font-size: 0.6875rem;"
                >
                    {#each TYPES as t}
                        <option value={t}>{t}</option>
                    {/each}
                </select>
                {#if slot.type === 'choice'}
                    <input
                        value={(slot.choices ?? []).join(', ')}
                        oninput={(e) => { slot.choices = (e.currentTarget as HTMLInputElement).value.split(',').map((s) => s.trim()).filter(Boolean); markDirty(graph); }}
                        placeholder="choice1, choice2, …"
                        style="width: 100%; margin-top: 0.25rem; background: var(--oflow-surface-2); color: var(--oflow-text); border: 1px solid var(--oflow-border); border-radius: 0.25rem; padding: 0.1875rem 0.375rem; font-size: 0.6875rem;"
                    />
                {/if}
                <input
                    bind:value={slot.description}
                    oninput={() => markDirty(graph)}
                    placeholder="Description"
                    style="width: 100%; margin-top: 0.25rem; background: transparent; color: var(--oflow-soft); border: none; padding: 0 0.25rem; font-size: 0.6875rem;"
                />
            </div>
        {/each}
    </div>
{/if}

<button class="orbital-btn" onclick={add} style="margin-top: 0.5rem;">+ Add variable</button>
