<script lang="ts">
    // `useSvelteFlow()` can only be called from inside the <SvelteFlow>
    // tree — it reads from Svelte context set up there. This tiny
    // component mounts inside SvelteFlow and forwards the
    // `screenToFlowPosition` helper up to the parent so drop handlers
    // in App.svelte (outside the SvelteFlow tree) can convert mouse
    // coordinates to flow coordinates.

    import { useSvelteFlow } from '@xyflow/svelte';
    import { onMount } from 'svelte';

    type ScreenToFlow = (p: { x: number; y: number }) => { x: number; y: number };

    let { onReady }: { onReady: (fn: ScreenToFlow) => void } = $props();

    const { screenToFlowPosition } = useSvelteFlow();
    onMount(() => onReady(screenToFlowPosition));
</script>
