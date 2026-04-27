@php
    /**
     * Channel-status indicator column. Renders one icon per channel an
     * orchestration could route. Three states per channel:
     *
     *   active      — at least one queue points at this orchestration
     *                 on this channel (green).
     *   inactive    — channel is wired up platform-wide but no queue
     *                 currently uses this orchestration (gray).
     *   placeholder — channel queue tables don't exist yet (faint, with
     *                 a "coming soon" tooltip).
     *
     * SMS and MMS share infra (same DID, same Twilio/Bandwidth endpoint,
     * same operator pool) so they collapse into one "Messaging" icon.
     * RCS is intentionally separate — different protocol, uneven rollout
     * across carriers, worth surfacing the distinction. WCTP is already
     * a recognised trigger type for pager workflows.
     */
    $channels = [
        ['label' => 'Phone', 'icon' => 'heroicon-o-phone',                  'queues' => $record->callQueues, 'placeholder' => false],
        ['label' => 'Email', 'icon' => 'heroicon-o-envelope',               'queues' => $record->emailQueues, 'placeholder' => false],
        ['label' => 'Messaging (SMS / MMS)', 'icon' => 'heroicon-o-chat-bubble-left-right',    'queues' => collect(), 'placeholder' => true],
        ['label' => 'RCS',   'icon' => 'heroicon-o-chat-bubble-left-ellipsis', 'queues' => collect(), 'placeholder' => true],
        ['label' => 'WCTP / pager', 'icon' => 'heroicon-o-bell-alert',     'queues' => collect(), 'placeholder' => true],
    ];
@endphp

<div style="display: inline-flex; align-items: center; gap: 0.5rem;">
    @foreach ($channels as $ch)
        @php
            $active = $ch['queues']->isNotEmpty();
            if ($ch['placeholder']) {
                $title = $ch['label'];
                $color = '#d1d5db';
            } elseif ($active) {
                $title = $ch['label'].': '.$ch['queues']->pluck('name')->join(', ');
                $color = '#16a34a';
            } else {
                $title = $ch['label'];
                $color = '#9ca3af';
            }
        @endphp
        <span
            title="{{ $title }}"
            style="display: inline-flex; align-items: center; color: {{ $color }};"
        >
            @svg($ch['icon'], ['style' => 'width: 1.25rem; height: 1.25rem;'])
        </span>
    @endforeach
</div>
