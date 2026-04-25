<script lang="ts">
    // Left-side palette. Two tabs:
    //   Primitives — draggable intake-goal primitives grouped by category,
    //                with a live filter at the top that matches across
    //                every category (name / key / description).
    //   Variables  — declared slot catalog.

    import type { EditorGraph } from '../lib/graph';
    import type { PrimitiveDTO } from '../lib/api';
    import VariablesPanel from './VariablesPanel.svelte';

    let { graph = $bindable() }: { graph: EditorGraph } = $props();

    type Tab = 'primitives' | 'variables';
    let tab: Tab = $state('primitives');
    let filter = $state('');

    // Preferred category order, then any categories not in the list
    // get appended alphabetically at the end. Derived from whatever
    // categories the loaded primitives actually use, so new
    // categories seeded server-side show up without a code change.
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

    function matchesFilter(prim: PrimitiveDTO, q: string): boolean {
        if (q === '') return true;
        const haystack = [
            prim.name,
            prim.key,
            prim.description ?? '',
            prim.category ?? '',
        ].join(' ').toLowerCase();
        return haystack.includes(q);
    }

    const filtered = $derived.by(() => {
        const q = filter.trim().toLowerCase();
        return graph.primitives.filter((p) => matchesFilter(p, q));
    });

    const categories = $derived(sortedCategories(filtered));

    function startDrag(e: DragEvent, primitiveKey: string) {
        if (!e.dataTransfer) return;
        e.dataTransfer.setData('application/x-orbital-primitive', primitiveKey);
        e.dataTransfer.setData('text/plain', primitiveKey);
        e.dataTransfer.effectAllowed = 'copy';
    }
</script>

<div class="orbital-palette">
    <div class="orbital-palette__tabs" role="tablist">
        <button
            role="tab"
            class="orbital-palette__tab"
            class:is-active={tab === 'primitives'}
            aria-selected={tab === 'primitives'}
            onclick={() => (tab = 'primitives')}
        >Primitives</button>
        <button
            role="tab"
            class="orbital-palette__tab"
            class:is-active={tab === 'variables'}
            aria-selected={tab === 'variables'}
            onclick={() => (tab = 'variables')}
        >Variables</button>
    </div>

    <div class="orbital-palette__body">
        {#if tab === 'primitives'}
            <div class="orbital-palette__filter">
                <input
                    type="search"
                    placeholder="Filter primitives…"
                    bind:value={filter}
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

            <p class="orbital-palette__hint">
                Drag onto an existing flow to add a step, or onto empty canvas to start a new flow.
            </p>

            {#if filtered.length === 0}
                <div class="orbital-palette__empty">No primitives match "{filter}".</div>
            {:else}
                {#each categories as category}
                    {@const inCategory = filtered.filter((p) => (p.category ?? 'other') === category)}
                    {#if inCategory.length > 0}
                        <div class="orbital-palette__group">
                            <div class="orbital-palette__group-label">{category}</div>
                            <div class="orbital-palette__items">
                                {#each inCategory as prim}
                                    <div
                                        class="orbital-palette__item"
                                        draggable="true"
                                        ondragstart={(e) => startDrag(e, prim.key)}
                                        title={prim.description ?? ''}
                                    >
                                        <div class="orbital-palette__item-name">{prim.name}</div>
                                        <div class="orbital-palette__item-key">{prim.key}</div>
                                    </div>
                                {/each}
                            </div>
                        </div>
                    {/if}
                {/each}
            {/if}
        {:else}
            <VariablesPanel bind:graph />
        {/if}
    </div>
</div>
