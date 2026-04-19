<x-filament-panels::page>
    <div wire:poll.5s="refreshState" style="display: flex; flex-direction: column; gap: 1.5rem;">

        {{-- =========================================================
             Postgres (Patroni)
             ========================================================= --}}
        <x-filament::section
            icon="heroicon-o-circle-stack"
            :icon-color="$patroni ? 'success' : 'danger'"
        >
            <x-slot name="heading">Postgres — Patroni cluster</x-slot>
            <x-slot name="description">
                @if ($patroni && $patroni['leader'])
                    Leader <strong>{{ $patroni['leader'] }}</strong> · {{ count($patroni['members']) }} members
                @else
                    <span style="color: var(--danger-600);">cluster unreachable</span>
                @endif
            </x-slot>
            <x-slot name="headerEnd">
                <x-filament::badge :color="$patroni && $patroni['leader'] ? 'success' : 'danger'">
                    {{ $patroni && $patroni['leader'] ? 'Healthy' : 'Unreachable' }}
                </x-filament::badge>
            </x-slot>

            @if ($patroni)
                <div style="display: flex; flex-direction: column; gap: 0.5rem;">
                    @foreach ($patroni['members'] as $m)
                        @php
                            $isLeader = $m['role'] === 'leader';
                            $color = $isLeader ? 'success' : ($m['state'] === 'streaming' ? 'primary' : 'warning');
                        @endphp
                        <x-filament::section compact>
                            <div style="display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap;">
                                <div style="display: flex; align-items: center; gap: 0.75rem;">
                                    <x-filament::badge :color="$color">
                                        {{ $isLeader ? 'LEADER' : str_replace('_', ' ', $m['role']) }}
                                    </x-filament::badge>
                                    <span style="font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 0.875rem; font-weight: 600;">
                                        {{ $m['name'] }}
                                    </span>
                                    <span style="font-size: 0.75rem; color: var(--gray-500);">
                                        {{ $m['state'] }}
                                    </span>
                                </div>
                                <div style="display: flex; align-items: center; gap: 1rem; font-size: 0.75rem; color: var(--gray-500);">
                                    <span>TL {{ $m['timeline'] ?? '—' }}</span>
                                    <span>Lag {{ $m['lag'] ?? '—' }} MB</span>
                                </div>
                            </div>
                        </x-filament::section>
                    @endforeach
                </div>

                <div style="margin-top: 0.75rem; display: flex; justify-content: flex-end;">
                    {{ $this->patroniSwitchoverAction }}
                </div>
            @endif
        </x-filament::section>

        {{-- =========================================================
             Valkey (Sentinel)
             ========================================================= --}}
        <x-filament::section
            icon="heroicon-o-bolt"
            :icon-color="($sentinel && $sentinel['master']) ? 'success' : 'danger'"
        >
            <x-slot name="heading">Valkey — Sentinel replica set</x-slot>
            <x-slot name="description">
                @if ($sentinel && $sentinel['master'])
                    Master <strong>{{ $sentinel['master']['ip'] ?? '?' }}:{{ $sentinel['master']['port'] ?? '?' }}</strong>
                    · {{ count($sentinel['replicas']) }} replicas
                    · {{ count($sentinel['sentinels']) }} peer sentinels
                @else
                    <span style="color: var(--danger-600);">sentinel cluster unreachable</span>
                @endif
            </x-slot>
            <x-slot name="headerEnd">
                <x-filament::badge :color="($sentinel && $sentinel['master']) ? 'success' : 'danger'">
                    {{ ($sentinel && $sentinel['master']) ? 'Healthy' : 'Unreachable' }}
                </x-filament::badge>
            </x-slot>

            @if ($sentinel)
                <div style="display: flex; flex-direction: column; gap: 0.5rem;">
                    @if ($sentinel['master'])
                        <x-filament::section compact>
                            <div style="display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap;">
                                <div style="display: flex; align-items: center; gap: 0.75rem;">
                                    <x-filament::badge color="success">MASTER</x-filament::badge>
                                    <span style="font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 0.875rem; font-weight: 600;">
                                        {{ $sentinel['master']['ip'] ?? '?' }}:{{ $sentinel['master']['port'] ?? '?' }}
                                    </span>
                                    <span style="font-size: 0.75rem; color: var(--gray-500);">
                                        flags {{ $sentinel['master']['flags'] ?? '-' }}
                                    </span>
                                </div>
                                <div style="font-size: 0.75rem; color: var(--gray-500);">
                                    last ping {{ $sentinel['master']['last-ok-ping-reply'] ?? '?' }} ms
                                </div>
                            </div>
                        </x-filament::section>
                    @endif

                    @foreach ($sentinel['replicas'] as $r)
                        @php
                            $flags = $r['flags'] ?? '';
                            $color = str_contains($flags, 'down') || str_contains($flags, 'disconnect') ? 'danger' : 'primary';
                        @endphp
                        <x-filament::section compact>
                            <div style="display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap;">
                                <div style="display: flex; align-items: center; gap: 0.75rem;">
                                    <x-filament::badge :color="$color">REPLICA</x-filament::badge>
                                    <span style="font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 0.875rem;">
                                        {{ $r['ip'] ?? '?' }}:{{ $r['port'] ?? '?' }}
                                    </span>
                                    <span style="font-size: 0.75rem; color: var(--gray-500);">
                                        flags {{ $flags ?: '-' }}
                                    </span>
                                </div>
                                <div style="font-size: 0.75rem; color: var(--gray-500);">
                                    last ping {{ $r['last-ok-ping-reply'] ?? '?' }} ms
                                </div>
                            </div>
                        </x-filament::section>
                    @endforeach
                </div>

                <div style="margin-top: 0.75rem; display: flex; justify-content: flex-end;">
                    {{ $this->sentinelFailoverAction }}
                </div>
            @endif
        </x-filament::section>

        {{-- =========================================================
             SeaweedFS
             ========================================================= --}}
        <x-filament::section
            icon="heroicon-o-archive-box"
            :icon-color="$seaweed ? 'success' : 'danger'"
        >
            <x-slot name="heading">SeaweedFS — distributed object store</x-slot>
            <x-slot name="description">
                @if ($seaweed)
                    Raft leader <strong>{{ $seaweed['leader'] ?? '?' }}</strong>
                    · {{ count($seaweed['peers']) }} peers
                    · {{ count($seaweed['volumes']) }} volume servers
                @else
                    <span style="color: var(--danger-600);">master cluster unreachable</span>
                @endif
            </x-slot>
            <x-slot name="headerEnd">
                <x-filament::badge :color="$seaweed ? 'success' : 'danger'">
                    {{ $seaweed ? 'Healthy' : 'Unreachable' }}
                </x-filament::badge>
            </x-slot>

            @if ($seaweed && count($seaweed['volumes']))
                <div style="display: flex; flex-direction: column; gap: 0.5rem;">
                    @foreach ($seaweed['volumes'] as $v)
                        @php
                            $pct = ($v['max'] ?? 0) > 0
                                ? round(($v['volumes'] / $v['max']) * 100)
                                : 0;
                        @endphp
                        <x-filament::section compact>
                            <div style="display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap;">
                                <div style="display: flex; align-items: center; gap: 0.75rem;">
                                    <x-filament::badge color="primary">VOLUME</x-filament::badge>
                                    <span style="font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 0.875rem; font-weight: 600;">
                                        {{ $v['url'] }}
                                    </span>
                                </div>
                                <div style="font-size: 0.75rem; color: var(--gray-500);">
                                    {{ $v['volumes'] }} / {{ $v['max'] }} volumes ({{ $pct }}%)
                                </div>
                            </div>
                        </x-filament::section>
                    @endforeach
                </div>
            @endif
        </x-filament::section>

        {{-- =========================================================
             HAProxy backends
             ========================================================= --}}
        <x-filament::section
            icon="heroicon-o-arrows-right-left"
            :icon-color="count($haproxyServers) ? 'success' : 'danger'"
        >
            <x-slot name="heading">HAProxy backends</x-slot>
            <x-slot name="description">
                Per-server view of every internal HAProxy backend. UP = routing · DOWN = pulled by health check · MAINT = administratively disabled. Full stats UI lives in Control Panels → HAProxy Stats.
            </x-slot>
            <x-slot name="headerEnd">
                <x-filament::badge :color="count($haproxyServers) ? 'success' : 'danger'">
                    {{ count($haproxyServers) ? count($haproxyServers).' servers' : 'Unreachable' }}
                </x-filament::badge>
            </x-slot>

            @if (count($haproxyServers))
                <div style="display: flex; flex-direction: column; gap: 1rem;">
                    @foreach ($this->haproxyByBackend() as $backend => $servers)
                        <div>
                            <div style="font-size: 0.75rem; color: var(--gray-500); font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 0.375rem;">
                                {{ $backend }}
                            </div>
                            <div style="display: flex; flex-direction: column; gap: 0.375rem;">
                                @foreach ($servers as $s)
                                    @php
                                        $status = $s['status'] ?? '';
                                        $color = match (true) {
                                            str_starts_with($status, 'UP') => 'success',
                                            str_starts_with($status, 'DOWN') => 'danger',
                                            str_contains($status, 'MAINT') => 'warning',
                                            default => 'gray',
                                        };
                                    @endphp
                                    <x-filament::section compact>
                                        <div style="display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap;">
                                            <div style="display: flex; align-items: center; gap: 0.75rem;">
                                                <x-filament::badge :color="$color">{{ $status ?: '?' }}</x-filament::badge>
                                                <span style="font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 0.875rem;">
                                                    {{ $s['svname'] }}
                                                </span>
                                            </div>
                                            <div style="font-size: 0.75rem; color: var(--gray-500); display: flex; gap: 1rem;">
                                                <span>check {{ $s['check_status'] ?: '-' }}</span>
                                                <span style="font-family: ui-monospace, SFMono-Regular, Menlo, monospace;">{{ $s['addr'] ?: '-' }}</span>
                                            </div>
                                        </div>
                                    </x-filament::section>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-filament::section>

    </div>
</x-filament-panels::page>
