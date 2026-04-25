<script lang="ts">
    // Picker modal shown when the user clicks "+ Add step" on a flow
    // card. Lists the primitive catalog grouped by category with a
    // live filter at the top — Enter picks the first match, Esc
    // closes (Modal handles Esc already).

    import type { PrimitiveDTO } from '../lib/api';
    import Modal from './Modal.svelte';

    let {
        primitives,
        onPick,
        onClose,
    }: {
        primitives: PrimitiveDTO[];
        onPick: (key: string) => void;
        onClose: () => void;
    } = $props();

    let filter = $state('');
    let filterInput: HTMLInputElement | undefined = $state();

    const CATEGORY_ORDER = ['trigger', 'match', 'intake', 'action', 'control', 'queue', 'assign'];

    function sortedCategories(prims: PrimitiveDTO[]): string[] {
        const seen = new Set(prims.map((p) => p.category ?? 'other'));
        const ordered: string[] = [];
        for (const c of CATEGORY_ORDER) {
            if (seen.has(c)) {
                ordered.push(c);
                seen.delete(c);
            }
        }
        return [...ordered, ...Array.from(seen).sort()];
    }

    function matchesFilter(p: PrimitiveDTO, q: string): boolean {
        if (q === '') return true;
        const hay = [p.name, p.key, p.description ?? '', p.category ?? ''].join(' ').toLowerCase();
        return hay.includes(q);
    }

    const filtered = $derived.by(() => {
        const q = filter.trim().toLowerCase();
        return primitives.filter((p) => matchesFilter(p, q));
    });

    const categories = $derived(sortedCategories(filtered));

    function pick(key: string) {
        onPick(key);
        onClose();
    }

    function onFilterKeyDown(e: KeyboardEvent) {
        if (e.key === 'Enter' && filtered.length > 0) {
            e.preventDefault();
            pick(filtered[0].key);
        }
    }

    // Autofocus the filter when the modal mounts so authors can
    // just start typing.
    $effect(() => {
        if (filterInput) filterInput.focus();
    });
</script>

<Modal title="Add a step" {onClose}>
    {#snippet children()}
        <div class="orbital-palette__filter" style="margin-bottom: 0.875rem;">
            <input
                type="search"
                placeholder="Filter primitives…  (Enter picks the first match)"
                bind:value={filter}
                bind:this={filterInput}
                onkeydown={onFilterKeyDown}
                autocomplete="off"
                spellcheck="false"
            />
            {#if filter !== ''}
                <button
                    type="button"
                    class="orbital-palette__filter-clear"
                    onclick={() => (filter = '')}
                    aria-label="Clear filter"
                >×</button>
            {/if}
        </div>

        {#if filtered.length === 0}
            <div class="orbital-palette__empty">No primitives match "{filter}".</div>
        {:else}
            <div style="display: flex; flex-direction: column; gap: 0.875rem;">
                {#each categories as category}
                    {@const inCategory = filtered.filter((p) => (p.category ?? 'other') === category)}
                    {#if inCategory.length > 0}
                        <div>
                            <div style="font-size: 0.625rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: var(--oflow-soft); margin-bottom: 0.375rem;">
                                {category}
                            </div>
                            <div style="display: flex; flex-direction: column; gap: 0.25rem;">
                                {#each inCategory as prim}
                                    <button
                                        class="orbital-btn"
                                        onclick={() => pick(prim.key)}
                                        style="display: flex; flex-direction: column; align-items: flex-start; gap: 0.125rem; padding: 0.5rem 0.75rem; text-align: left;"
                                    >
                                        <div style="font-weight: 600;">{prim.name}</div>
                                        {#if prim.description}
                                            <div style="font-size: 0.6875rem; color: var(--oflow-soft); font-weight: 400;">{prim.description}</div>
                                        {/if}
                                    </button>
                                {/each}
                            </div>
                        </div>
                    {/if}
                {/each}
            </div>
        {/if}
    {/snippet}
</Modal>
