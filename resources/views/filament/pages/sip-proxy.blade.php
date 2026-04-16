<x-filament-panels::page>
    {{-- Auto-refresh every 5s — gives near-real-time "active calls
         remaining" during drain without Reverb complexity. --}}
    <div wire:poll.5s="refreshState">

        {{-- Status overview cards --}}
        <div class="orbital-dashboard-grid" style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 1rem; margin-bottom: 1.5rem;">
            {{-- Proxy health --}}
            <x-filament::section compact>
                <div style="text-align: center;">
                    <div style="font-size: 0.75rem; color: var(--gray-500); margin-bottom: 0.25rem;">Proxy Status</div>
                    <x-filament::badge :color="$healthy ? 'success' : 'danger'">
                        {{ $healthy ? 'Healthy' : 'Unreachable' }}
                    </x-filament::badge>
                </div>
            </x-filament::section>

            {{-- Uptime --}}
            <x-filament::section compact>
                <div style="text-align: center;">
                    <div style="font-size: 0.75rem; color: var(--gray-500); margin-bottom: 0.25rem;">Uptime</div>
                    <div style="font-size: 1.125rem; font-weight: 600;">{{ $uptime }}</div>
                </div>
            </x-filament::section>

            {{-- Active calls --}}
            <x-filament::section compact>
                <div style="text-align: center;">
                    <div style="font-size: 0.75rem; color: var(--gray-500); margin-bottom: 0.25rem;">Active Calls</div>
                    <div style="font-size: 1.5rem; font-weight: 700; color: {{ $activeDialogs > 0 ? 'var(--primary-500)' : 'var(--gray-400)' }};">
                        {{ $activeDialogs }}
                    </div>
                </div>
            </x-filament::section>
        </div>

        {{-- Backends table --}}
        <x-filament::section heading="Backends" compact>
            @if (empty($dispatchers))
                <p style="color: var(--gray-500); font-size: 0.875rem;">
                    {{ $healthy ? 'No dispatcher entries found.' : 'Cannot reach Kamailio — is the container running?' }}
                </p>
            @else
                <table style="width: 100%; font-size: 0.875rem; border-collapse: collapse;">
                    <thead>
                        <tr style="border-bottom: 1px solid var(--gray-200); text-align: left;">
                            <th style="padding: 0.5rem;">Set</th>
                            <th style="padding: 0.5rem;">Address</th>
                            <th style="padding: 0.5rem;">State</th>
                            <th style="padding: 0.5rem;">Priority</th>
                            <th style="padding: 0.5rem;">Attrs</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($dispatchers as $entry)
                            <tr style="border-bottom: 1px solid var(--gray-100);">
                                <td style="padding: 0.5rem;">{{ $entry['set_id'] }}</td>
                                <td style="padding: 0.5rem; font-family: ui-monospace, monospace;">{{ $entry['address'] }}</td>
                                <td style="padding: 0.5rem;">
                                    @php
                                        $color = match ($entry['state']) {
                                            'active' => 'success',
                                            'draining' => 'warning',
                                            'disabled' => 'danger',
                                            default => 'gray',
                                        };
                                    @endphp
                                    <x-filament::badge :color="$color">
                                        {{ ucfirst($entry['state']) }}
                                    </x-filament::badge>
                                </td>
                                <td style="padding: 0.5rem;">{{ $entry['priority'] }}</td>
                                <td style="padding: 0.5rem; color: var(--gray-500);">{{ $entry['attrs'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </x-filament::section>

        {{-- Info note --}}
        <div style="margin-top: 1rem; font-size: 0.75rem; color: var(--gray-400);">
            Operator softphones (WSS 8089), LiveKit SIP bridge (5069), and RTP media (10000-10099) bypass this proxy — they connect directly to Asterisk.
            Drain only affects inbound SIP trunk signaling.
        </div>
    </div>
</x-filament-panels::page>
