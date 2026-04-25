<script lang="ts">
    // Custom edge that renders the path + label plus two reconnect
    // anchors (one at each endpoint). Svelte Flow's default edge
    // types don't expose reconnect handles — you have to render an
    // `<EdgeReconnectAnchor>` inside a custom edge component, which
    // the library's `onreconnect` / `onreconnectend` handlers then
    // dispatch from.

    import {
        BaseEdge,
        EdgeReconnectAnchor,
        Position,
        getBezierPath,
    } from '@xyflow/svelte';
    import type { EdgeProps } from '@xyflow/svelte';

    let {
        id,
        sourceX,
        sourceY,
        targetX,
        targetY,
        sourcePosition = Position.Bottom,
        targetPosition = Position.Top,
        label,
        labelStyle,
        markerStart,
        markerEnd,
        style,
        interactionWidth,
    }: EdgeProps = $props();

    const bezier = $derived(
        getBezierPath({
            sourceX,
            sourceY,
            targetX,
            targetY,
            sourcePosition,
            targetPosition,
        }),
    );

    const path = $derived(bezier[0]);
    const labelX = $derived(bezier[1]);
    const labelY = $derived(bezier[2]);
</script>

<BaseEdge
    {id}
    {path}
    {labelX}
    {labelY}
    {label}
    {labelStyle}
    {markerStart}
    {markerEnd}
    {style}
    {interactionWidth}
/>

<EdgeReconnectAnchor type="source" position={{ x: sourceX, y: sourceY }} size={14} />
<EdgeReconnectAnchor type="target" position={{ x: targetX, y: targetY }} size={14} />
