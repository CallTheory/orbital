<x-filament-panels::page>
    {{-- Health-wrapper tints the border of each tier section using the
         health state helpers on the page class. Green/yellow/red maps
         to Filament's success/warning/danger palette so the page
         matches the rest of the admin UI in dark + light mode. --}}
    @php
        $healthBorder = fn (string $state): string => match ($state) {
            'ok' => 'border-color: var(--success-500); box-shadow: inset 4px 0 0 var(--success-500);',
            'warn' => 'border-color: var(--warning-500); box-shadow: inset 4px 0 0 var(--warning-500);',
            'down' => 'border-color: var(--danger-500); box-shadow: inset 4px 0 0 var(--danger-500);',
            default => '',
        };
    @endphp
    <div wire:poll.5s="refreshState" style="display: flex; flex-direction: column; gap: 1.5rem;">

        {{-- =========================================================
             Postgres (Patroni)
             ========================================================= --}}
        <div style="border: 1px solid var(--gray-200); border-radius: 0.75rem; {{ $healthBorder($this->patroniHealth()) }}">
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
                    @foreach ($this->patroniMembersSorted() as $m)
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
        </div>

        {{-- =========================================================
             Valkey (Sentinel)
             ========================================================= --}}
        <div style="border: 1px solid var(--gray-200); border-radius: 0.75rem; {{ $healthBorder($this->sentinelHealth()) }}">
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
        </div>

        {{-- =========================================================
             SeaweedFS
             ========================================================= --}}
        <div style="border: 1px solid var(--gray-200); border-radius: 0.75rem; {{ $healthBorder($this->seaweedHealth()) }}">
        <x-filament::section
            icon="heroicon-o-archive-box"
            :icon-color="$seaweed ? 'success' : 'danger'"
        >
            <x-slot name="heading">SeaweedFS — distributed object store</x-slot>
            <x-slot name="description">
                @if ($seaweed)
                    Raft leader <strong>{{ $seaweed['leader'] ?? '?' }}</strong>
                    · {{ count($seaweed['masters'] ?? []) }} masters
                    · {{ count($seaweed['volumes'] ?? []) }} volume servers
                    · {{ count($seaweed['filers'] ?? []) }} filers
                @else
                    <span style="color: var(--danger-600);">master cluster unreachable</span>
                @endif
            </x-slot>
            <x-slot name="headerEnd">
                <x-filament::badge :color="$seaweed ? 'success' : 'danger'">
                    {{ $seaweed ? 'Healthy' : 'Unreachable' }}
                </x-filament::badge>
            </x-slot>

            @if ($seaweed)
                {{-- Masters (Raft quorum) --}}
                @if (count($seaweed['masters'] ?? []))
                    <div style="font-size: 0.75rem; color: var(--gray-500); font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 0.375rem;">
                        Masters · Raft consensus for volume placement
                    </div>
                    <div style="display: flex; flex-direction: column; gap: 0.5rem; margin-bottom: 1rem;">
                        @foreach ($seaweed['masters'] as $m)
                            @php
                                $role = $m['role'];
                                $color = match ($role) {
                                    'leader' => 'success',
                                    'follower' => 'primary',
                                    default => 'danger',
                                };
                            @endphp
                            <x-filament::section compact>
                                <div style="display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap;">
                                    <div style="display: flex; align-items: center; gap: 0.75rem;">
                                        <x-filament::badge :color="$color">{{ strtoupper($role) }}</x-filament::badge>
                                        <span style="font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 0.875rem; font-weight: 600;">
                                            {{ $m['host'] }}
                                        </span>
                                    </div>
                                    <div style="font-size: 0.75rem; color: var(--gray-500);">
                                        {{ $m['reachable'] ? 'reachable' : 'unreachable' }}
                                    </div>
                                </div>
                            </x-filament::section>
                        @endforeach
                    </div>
                @endif

                {{-- Volume servers (store the actual blobs) --}}
                @if (count($seaweed['volumes'] ?? []))
                    <div style="font-size: 0.75rem; color: var(--gray-500); font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 0.375rem;">
                        Volume servers · blob storage
                    </div>
                    <div style="display: flex; flex-direction: column; gap: 0.5rem; margin-bottom: 1rem;">
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

                {{-- Filers (S3 gateway + metadata) --}}
                @if (count($seaweed['filers'] ?? []))
                    <div style="font-size: 0.75rem; color: var(--gray-500); font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 0.375rem;">
                        Filers · S3 gateway &amp; metadata (shared Valkey store)
                    </div>
                    <div style="display: flex; flex-direction: column; gap: 0.5rem;">
                        @foreach ($seaweed['filers'] as $f)
                            <x-filament::section compact>
                                <div style="display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap;">
                                    <div style="display: flex; align-items: center; gap: 0.75rem;">
                                        <x-filament::badge :color="$f['reachable'] ? 'success' : 'danger'">FILER</x-filament::badge>
                                        <span style="font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 0.875rem; font-weight: 600;">
                                            {{ $f['host'] }}
                                        </span>
                                    </div>
                                    <div style="font-size: 0.75rem; color: var(--gray-500);">
                                        {{ $f['reachable'] ? 'reachable' : 'unreachable' }}
                                    </div>
                                </div>
                            </x-filament::section>
                        @endforeach
                    </div>
                @endif
            @endif
        </x-filament::section>
        </div>

        {{-- =========================================================
             HAProxy backends — one bordered section per backend so
             each pool's health stands on its own. pgsql_rw and
             pgsql_ro both show one-UP-two-DOWN in opposite roles
             by design; grouping them under a single card made the
             "is this ok?" answer impossible to read at a glance.
             ========================================================= --}}
        @if (count($haproxyServers))
            @foreach ($this->haproxyByBackend() as $backend => $servers)
                @php
                    $note = $this->haproxyBackendNote($backend);
                    $backendHealth = $this->haproxyBackendHealth($backend, $servers);
                    $upCount = count(array_filter($servers, fn ($s) => str_starts_with((string) ($s['status'] ?? ''), 'UP')));
                    $downCount = count(array_filter($servers, fn ($s) => str_starts_with((string) ($s['status'] ?? ''), 'DOWN')));
                    $maintCount = count(array_filter($servers, fn ($s) => str_contains((string) ($s['status'] ?? ''), 'MAINT')));
                @endphp
                <div style="border: 1px solid var(--gray-200); border-radius: 0.75rem; {{ $healthBorder($backendHealth) }}">
                    <x-filament::section icon="heroicon-o-arrows-right-left">
                        <x-slot name="heading">
                            <span style="font-family: ui-monospace, SFMono-Regular, Menlo, monospace;">{{ $backend }}</span>
                        </x-slot>
                        <x-slot name="description">
                            {{ $upCount }} UP · {{ $downCount }} DOWN{{ $maintCount ? ' · '.$maintCount.' MAINT' : '' }}
                        </x-slot>
                        <x-slot name="headerEnd">
                            <x-filament::badge :color="match ($backendHealth) { 'ok' => 'success', 'warn' => 'warning', 'down' => 'danger', default => 'gray' }">
                                {{ match ($backendHealth) { 'ok' => 'Healthy', 'warn' => 'Degraded', 'down' => 'Down', default => 'Unknown' } }}
                            </x-filament::badge>
                        </x-slot>

                        @if ($note)
                            <div style="font-size: 0.75rem; color: var(--gray-500); margin-bottom: 0.75rem; line-height: 1.4;">
                                {{ $note }}
                            </div>
                        @endif

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
                                    $roleTag = $this->serverRoleTag($backend, (string) $s['svname']);
                                @endphp
                                <x-filament::section compact>
                                    <div style="display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap;">
                                        <div style="display: flex; align-items: center; gap: 0.75rem;">
                                            <x-filament::badge :color="$color">{{ $status ?: '?' }}</x-filament::badge>
                                            <span style="font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 0.875rem;">
                                                {{ $s['svname'] }}
                                            </span>
                                            @if ($roleTag)
                                                <span style="font-size: 0.75rem; color: var(--gray-500); text-transform: lowercase; font-variant: small-caps; letter-spacing: 0.02em;">
                                                    {{ $roleTag }}
                                                </span>
                                            @endif
                                        </div>
                                        <div style="font-size: 0.75rem; color: var(--gray-500); display: flex; gap: 1rem;">
                                            <span>check {{ $s['check_status'] ?: '-' }}</span>
                                            <span style="font-family: ui-monospace, SFMono-Regular, Menlo, monospace;">{{ $s['addr'] ?: '-' }}</span>
                                        </div>
                                    </div>
                                </x-filament::section>
                            @endforeach
                        </div>
                    </x-filament::section>
                </div>
            @endforeach
        @else
            <div style="border: 1px solid var(--gray-200); border-radius: 0.75rem; {{ $healthBorder('down') }}">
                <x-filament::section icon="heroicon-o-arrows-right-left">
                    <x-slot name="heading">HAProxy backends</x-slot>
                    <x-slot name="description">
                        <span style="color: var(--danger-600);">HAProxy unreachable — no backend data available.</span>
                    </x-slot>
                </x-filament::section>
            </div>
        @endif

    </div>
</x-filament-panels::page>
