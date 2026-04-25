<script lang="ts">
    // A flow card. Each step row is clickable (opens StepEditModal).
    //
    // Bottom of the card renders a port rail driven by the unified
    // `ports` array built in App.svelte:
    //
    //   - Fixed-exit primitives (gather_phone, verify_caller, …)
    //     → one port per declared exit (continue / rejected / failure,
    //       matched / not_matched, etc.). Connected ports are not
    //       draggable; unconnected ones are, and dragging creates a
    //       new transition tagged with that exit's name.
    //
    //   - Unbounded primitives (match_did, gather_choice, empty)
    //     → one port per existing transition + a trailing "+" add port
    //       the author can drag to create additional branches.
    //
    //   - Terminal primitives (transfer_call, assign_to_persona, …)
    //     → zero ports; a "terminal" pill shows instead.

    import { Handle, Position, type NodeProps } from '@xyflow/svelte';

    type StepSummary = {
        intake_goal_key: string;
        primitive_name: string;
        primitive_icon: string | null;
        primitive_category: string;
        hint: string | null;
    };

    type PortDescriptor = {
        label: string;
        handle_id: string;
        is_connected: boolean;
        is_fallback: boolean;
        is_add: boolean;
    };

    type FlowNodeData = {
        name: string;
        description: string | null;
        is_entry: boolean;
        is_active: boolean;
        is_channel_trigger: boolean;
        is_action_group: boolean;
        trigger_type: string | null;
        steps: StepSummary[];
        ports: PortDescriptor[];
        transition_count: number;
        max_transitions: number | null;
        onEditStep: (stepIndex: number) => void;
        onAddStep: () => void;
        onOpenSettings: () => void;
    };

    let { data, selected }: NodeProps<FlowNodeData> = $props();

    const CHANNEL_INFO: Record<string, { label: string; icon: string }> = {
        inbound_phone:  { label: 'Phone in',  icon: '\u260E' },
        inbound_email:  { label: 'Email in',  icon: '\u2709' },
        inbound_sms:    { label: 'SMS in',    icon: '\u{1F4AC}' },
        inbound_wctp:   { label: 'WCTP in',   icon: '\u{1F4F6}' },
        outbound_phone: { label: 'Phone out', icon: '\u260F' },
    };

    const channelInfo = $derived(
        data.is_channel_trigger && data.trigger_type
            ? CHANNEL_INFO[data.trigger_type]
            : null,
    );

    const isTerminalFlow = $derived(data.max_transitions === 0);

    function handleClick(e: MouseEvent, fn: () => void) {
        e.stopPropagation();
        fn();
    }

    /**
     * Spread the port set evenly along the card's bottom edge with
     * margins on both sides so the handles don't hug the corners.
     */
    function portLeftPercent(i: number, total: number): number {
        return ((i + 1) / (total + 1)) * 100;
    }
</script>

<div
    class="orbital-flow-node"
    class:is-entry={data.is_entry}
    class:is-selected={selected}
    class:is-inactive={!data.is_active}
    class:is-channel-trigger={data.is_channel_trigger}
    class:is-action-group={data.is_action_group}
>
    {#if !data.is_channel_trigger}
        <Handle type="target" position={Position.Top} isConnectableStart={false} />
    {/if}

    <div class="orbital-flow-node__title">
        {#if channelInfo}
            <span class="orbital-flow-node__channel-icon">{channelInfo.icon}</span>
        {/if}
        <span>{data.name}</span>
        <span style="flex: 1;"></span>
        {#if data.is_channel_trigger}
            <span class="orbital-flow-node__badge">{channelInfo?.label ?? 'Trigger'}</span>
        {:else if data.is_action_group}
            <span class="orbital-flow-node__badge is-action-group">Shared</span>
        {:else if data.is_entry}
            <span class="orbital-flow-node__badge">Entry</span>
        {/if}
        <button
            class="orbital-flow-node__icon-btn nodrag"
            title="Flow settings"
            onclick={(e) => handleClick(e, data.onOpenSettings)}
            aria-label="Flow settings"
        >⚙</button>
    </div>

    {#if data.description}
        <div class="orbital-flow-node__description">{data.description}</div>
    {/if}

    <div class="orbital-flow-node__steps">
        {#if data.steps.length === 0}
            <div class="orbital-flow-node__empty">No steps yet</div>
        {:else}
            {#each data.steps as summary, i (i)}
                <button
                    type="button"
                    class="orbital-flow-node__step nodrag"
                    onclick={(e) => handleClick(e, () => data.onEditStep(i))}
                    title="Click to edit this step"
                >
                    <span class="orbital-flow-node__step-num">{i + 1}</span>
                    <span class="orbital-flow-node__step-name">{summary.primitive_name}</span>
                    {#if summary.hint}
                        <span class="orbital-flow-node__step-hint">{summary.hint}</span>
                    {/if}
                </button>
            {/each}
        {/if}
    </div>

    <button
        class="orbital-flow-node__add nodrag"
        onclick={(e) => handleClick(e, data.onAddStep)}
    >+ Add step</button>

    <div class="orbital-flow-node__meta">
        <span>{data.transition_count} next step{data.transition_count === 1 ? '' : 's'}</span>
        {#if !data.is_active}
            <span style="color: var(--oflow-warn);">inactive</span>
        {/if}
    </div>

    {#if isTerminalFlow}
        <!-- Terminal: just a label inside the card, no handles. -->
        <div class="orbital-flow-node__ports">
            <span
                class="orbital-flow-node__port-label is-terminal"
                style="left: 50%;"
                title="This flow ends the call — no further transitions"
            >terminal</span>
        </div>
    {:else if data.ports.length > 0}
        <!-- Label rail stays INSIDE the card content so text is
             readable. Handles are rendered as siblings BELOW (direct
             children of .orbital-flow-node) so xyflow's Position.Bottom
             resolves against the card — dots land on the card's
             bottom border instead of inside the content area. -->
        <div class="orbital-flow-node__ports">
            {#each data.ports as port, i (port.handle_id)}
                <span
                    class="orbital-flow-node__port-label"
                    class:is-fallback={port.is_fallback}
                    class:is-add={port.is_add}
                    class:is-unconnected={!port.is_connected && !port.is_add}
                    style={`left: ${portLeftPercent(i, data.ports.length)}%;`}
                    title={port.label}
                >{port.label}</span>
            {/each}
        </div>

        {#each data.ports as port, i (port.handle_id)}
            <Handle
                type="source"
                position={Position.Bottom}
                id={port.handle_id}
                isConnectableStart={!port.is_connected}
                isConnectableEnd={false}
                class={port.is_connected ? 'oflow-port--connected' : 'oflow-port--draggable'}
                style={`left: ${portLeftPercent(i, data.ports.length)}%;`}
            />
        {/each}
    {/if}
</div>
