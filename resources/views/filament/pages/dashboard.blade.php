<x-filament-panels::page>
    {{-- Polls every 60s — matches the server-side health cache TTL so
         a fresh set of checks runs on each tick instead of replaying
         stale cached results. `refresh()` on the Dashboard class
         clears the cache before re-running. --}}
    <div class="orbital-dashboard-grid" wire:poll.60s="refresh">
        @foreach ($this->checks as $check)
            <div x-data="{ open: false }">
                <x-filament::section
                    :heading="$check['name']"
                    :icon="$check['icon']"
                    :icon-color="$check['color']"
                    compact
                >
                    <x-slot name="afterHeader">
                        {{-- Wrap both badges in an inline-flex row
                             with align-items: center so the icon
                             badge's baseline lines up with the plain
                             text status badge. Without the wrapper
                             they render as separate inline elements
                             with different content heights and the
                             "Acked" pill drifts slightly high. --}}
                        <div style="display: inline-flex; align-items: center; gap: 0.375rem;">
                            <x-filament::badge :color="$check['color']">
                                {{ $check['statusLabel'] }}
                            </x-filament::badge>
                            @if (! empty($check['ack']))
                                <x-filament::badge color="info" icon="heroicon-m-bell-slash">
                                    Acked
                                </x-filament::badge>
                            @endif
                        </div>
                    </x-slot>

                    {{-- Every card is the same shape: one-line message
                         + optional Details button + ack toggle. --}}
                    <div class="orbital-card-body">
                        <p class="orbital-card-message">
                            {{ $check['message'] }}
                        </p>

                        @if (! empty($check['ack']))
                            <p
                                style="
                                    margin-top: 0.5rem;
                                    font-size: 0.75rem;
                                    color: var(--gray-500);
                                    display: flex;
                                    align-items: center;
                                    gap: 0.375rem;
                                "
                            >
                                <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9.143 17.082a24.248 24.248 0 0 0 3.844.148m-3.844-.148a23.856 23.856 0 0 1-5.455-1.31 8.964 8.964 0 0 0 2.3-5.542m3.155 6.852a3 3 0 0 0 5.667 1.97m1.965-2.277L21 21m-4.225-4.225a23.81 23.81 0 0 0 3.536-1.003A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6.53 6.53m12.74 12.74L3 3" />
                                </svg>
                                Acknowledged by {{ $check['ack']['user_name'] }}
                                @php
                                    $tz = auth()->user()?->displayTimezone() ?? config('app.timezone');
                                    $at = \Carbon\Carbon::parse($check['ack']['acknowledged_at'])->timezone($tz);
                                @endphp
                                · {{ $at->format('M j, g:i a') }}
                            </p>
                        @endif

                        @php
                            $hasAckAction = ! empty($check['ack']) || $check['status'] !== 'ok';
                            $hasDetails = ! empty($check['metrics']);
                        @endphp
                        @if ($hasAckAction || $hasDetails)
                            {{-- Two-slot action row: Acknowledge /
                                 Clear on the left, Details on the
                                 right. Using space-between on the
                                 parent so a single-button row still
                                 sticks to the correct side — ack
                                 lives on the left even when there's
                                 no Details button, and vice versa.
                                 Overrides the default flex-end on
                                 .orbital-card-actions inline. --}}
                            <div
                                class="orbital-card-actions"
                                style="gap: 0.5rem; justify-content: space-between;"
                            >
                                <div>
                                    {{-- Ack / Clear toggle. Only shown
                                         on non-OK cards so a healthy
                                         component can't be acked by
                                         accident. An acked card always
                                         shows Clear regardless of raw
                                         state so the operator can undo
                                         their own action. --}}
                                    @if (! empty($check['ack']))
                                        <x-filament::button
                                            size="xs"
                                            color="gray"
                                            icon="heroicon-m-bell"
                                            wire:click="clearAcknowledgment('{{ $check['key'] }}')"
                                        >
                                            Clear ack
                                        </x-filament::button>
                                    @elseif ($check['status'] !== 'ok')
                                        <x-filament::button
                                            size="xs"
                                            color="gray"
                                            icon="heroicon-m-bell-slash"
                                            wire:click="acknowledgeCheck('{{ $check['key'] }}')"
                                        >
                                            Acknowledge
                                        </x-filament::button>
                                    @endif
                                </div>
                                <div>
                                    @if ($hasDetails)
                                        <button type="button"
                                                class="orbital-card-details-btn"
                                                @click="open = true">
                                            Details →
                                        </button>
                                    @endif
                                </div>
                            </div>
                        @endif
                    </div>
                </x-filament::section>

                {{-- Modal — rendered once per card but only visible
                     when that card's Details button is clicked.
                     Backdrop closes on click; Esc key closes too. --}}
                @if (! empty($check['metrics']))
                    <div
                        x-cloak
                        x-show="open"
                        @keydown.escape.window="open = false"
                        class="orbital-modal-backdrop"
                        @click.self="open = false"
                    >
                        <div class="orbital-modal">
                            <div class="orbital-modal-header">
                                <div class="orbital-modal-title">
                                    {{ $check['name'] }}
                                </div>
                                <button type="button"
                                        class="orbital-modal-close"
                                        @click="open = false"
                                        aria-label="Close">
                                    ✕
                                </button>
                            </div>
                            <div class="orbital-modal-body">
                                <p class="orbital-card-message">
                                    {{ $check['message'] }}
                                </p>
                                <dl class="orbital-card-metrics">
                                    @foreach ($check['metrics'] as $label => $value)
                                        <div>
                                            <dt>{{ $label }}:</dt>
                                            <dd>{{ $value }}</dd>
                                        </div>
                                    @endforeach
                                </dl>
                            </div>
                        </div>
                    </div>
                @endif
            </div>
        @endforeach
    </div>
</x-filament-panels::page>
