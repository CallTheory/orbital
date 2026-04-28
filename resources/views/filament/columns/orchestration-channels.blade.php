@php
    /**
     * Channel-status indicator column. Renders one icon per channel an
     * orchestration could route. The icon lights green when the
     * orchestration's trigger flow for that channel has been touched —
     * any of: at least one step inside it, at least one outbound
     * transition wiring it to another flow, or the author has flipped
     * `is_active = true` on the trigger. The Assignments section in
     * the details modal handles "is it actually in use by a queue"
     * separately.
     *
     * Two states per channel:
     *   configured — author has wired the trigger flow (green).
     *   inactive   — trigger flow exists but is empty / unwired (gray).
     */
    $configuredTriggers = collect($record->flows ?? [])
        ->filter(fn ($f) => $f->is_active
            || ($f->steps_count ?? 0) > 0
            || ($f->transitions_out_count ?? 0) > 0)
        ->pluck('trigger_type')
        ->all();

    $channels = [
        ['label' => 'Call',    'icon' => 'heroicon-o-phone',                          'trigger' => 'inbound_phone'],
        ['label' => 'Email',   'icon' => 'heroicon-o-envelope',                       'trigger' => 'inbound_email'],
        ['label' => 'Message', 'icon' => 'heroicon-o-chat-bubble-left-right',         'trigger' => 'inbound_message'],
        ['label' => 'Chat',    'icon' => 'heroicon-o-chat-bubble-bottom-center-text', 'trigger' => 'inbound_chat'],
    ];
@endphp

<div style="display: inline-flex; align-items: center; gap: 0.5rem;">
    @foreach ($channels as $ch)
        @php
            $configured = in_array($ch['trigger'], $configuredTriggers, true);
            $color = $configured ? '#16a34a' : '#9ca3af';
        @endphp
        <span
            title="{{ $ch['label'] }}"
            style="display: inline-flex; align-items: center; color: {{ $color }};"
        >
            @svg($ch['icon'], ['style' => 'width: 1.25rem; height: 1.25rem;'])
        </span>
    @endforeach
</div>
