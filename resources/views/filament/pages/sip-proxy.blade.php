<x-filament-panels::page>
    {{-- Auto-refresh every 5s — gives near-real-time "active calls
         remaining" during drain without Reverb complexity. --}}
    <div wire:poll.5s="refreshState">

        {{-- Status overview --}}
        <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 1rem; margin-bottom: 1.5rem;">
            <x-filament::section compact>
                <div style="text-align: center; padding: 0.5rem 0;">
                    <div style="font-size: 0.75rem; color: var(--gray-500); margin-bottom: 0.375rem;">Proxy Status</div>
                    <x-filament::badge :color="$healthy ? 'success' : 'danger'">
                        {{ $healthy ? 'Healthy' : 'Unreachable' }}
                    </x-filament::badge>
                </div>
            </x-filament::section>

            <x-filament::section compact>
                <div style="text-align: center; padding: 0.5rem 0;">
                    <div style="font-size: 0.75rem; color: var(--gray-500); margin-bottom: 0.375rem;">Uptime</div>
                    <div style="font-size: 1.125rem; font-weight: 600;">{{ $uptime }}</div>
                </div>
            </x-filament::section>

            <x-filament::section compact>
                <div style="text-align: center; padding: 0.5rem 0;">
                    <div style="font-size: 0.75rem; color: var(--gray-500); margin-bottom: 0.375rem;">Active Calls</div>
                    <div style="font-size: 1.5rem; font-weight: 700; {{ $activeDialogs > 0 ? 'color: var(--primary-500);' : 'color: var(--gray-400);' }}">
                        {{ $activeDialogs }}
                    </div>
                </div>
            </x-filament::section>
        </div>

        {{-- Backends --}}
        <x-filament::section heading="Backends">
            @if (empty($dispatchers))
                <div style="display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 2rem 0; text-align: center;">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width: 3rem; height: 3rem; color: var(--gray-400); margin-bottom: 0.75rem;">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M7.5 21 3 16.5m0 0L7.5 12M3 16.5h13.5m0-13.5L21 7.5m0 0L16.5 12M21 7.5H7.5" />
                    </svg>
                    <div style="font-size: 0.875rem; font-weight: 500;">
                        {{ $healthy ? 'No dispatcher entries' : 'Kamailio unreachable' }}
                    </div>
                    <div style="font-size: 0.75rem; color: var(--gray-500); margin-top: 0.25rem;">
                        {{ $healthy ? 'The dispatcher set is empty.' : 'Is the kamailio container running?' }}
                    </div>
                </div>
            @else
                <div style="display: flex; flex-direction: column; gap: 0.75rem;">
                    @foreach ($dispatchers as $entry)
                        @php
                            $color = match ($entry['state']) {
                                'active' => 'success',
                                'draining' => 'warning',
                                'disabled' => 'danger',
                                default => 'gray',
                            };
                            $icon = match ($entry['state']) {
                                'active' => 'heroicon-o-check-circle',
                                'draining' => 'heroicon-o-pause-circle',
                                'disabled' => 'heroicon-o-x-circle',
                                default => 'heroicon-o-question-mark-circle',
                            };
                        @endphp
                        <x-filament::section
                            :icon="$icon"
                            :icon-color="$color"
                            compact
                        >
                            <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 0.75rem;">
                                <div style="display: flex; align-items: center; gap: 0.75rem;">
                                    <x-filament::badge :color="$color">
                                        {{ ucfirst($entry['state']) }}
                                    </x-filament::badge>
                                    <span style="font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 0.875rem;">
                                        {{ $entry['address'] }}
                                    </span>
                                </div>
                                <div style="display: flex; align-items: center; gap: 1rem; font-size: 0.75rem; color: var(--gray-500);">
                                    <span>Set {{ $entry['set_id'] }}</span>
                                    <span>Priority {{ $entry['priority'] }}</span>
                                    @if ($entry['attrs'])
                                        <span>{{ $entry['attrs'] }}</span>
                                    @endif
                                </div>
                            </div>
                        </x-filament::section>
                    @endforeach
                </div>
            @endif
        </x-filament::section>

        {{-- Info note --}}
        <div style="margin-top: 1rem; font-size: 0.75rem; color: var(--gray-400);">
            Operator softphones (WSS 8089), LiveKit SIP bridge (5069), and RTP media (10000-10099) bypass this proxy — they connect directly to Asterisk.
            Drain only affects inbound SIP trunk signaling.
        </div>
    </div>
</x-filament-panels::page>
