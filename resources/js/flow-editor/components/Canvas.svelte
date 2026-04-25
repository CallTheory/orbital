<script lang="ts">
    // Wraps the Svelte Flow <SvelteFlow> component with Orbital's
    // custom node / edge renderers and a handful of default UI
    // affordances (background, controls, minimap).

    import {
        SvelteFlow,
        Background,
        Controls,
        MiniMap,
        type Node,
        type Edge,
    } from '@xyflow/svelte';
    import FlowNode from './FlowNode.svelte';

    let {
        nodes = $bindable([]),
        edges = $bindable([]),
        onNodeClick,
    }: {
        nodes: Node[];
        edges: Edge[];
        onNodeClick?: (nodeId: string) => void;
    } = $props();

    const nodeTypes = { flow: FlowNode };
</script>

<div class="orbital-canvas">
    <SvelteFlow
        bind:nodes
        bind:edges
        {nodeTypes}
        fitView
        on:nodeclick={(e: CustomEvent) => onNodeClick?.(e.detail.node.id)}
    >
        <Background />
        <Controls />
        <MiniMap zoomable pannable />
    </SvelteFlow>
</div>
