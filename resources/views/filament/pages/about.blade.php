@php
    $release = $this->getRelease();
    $support = $this->getSupport();
    $environment = $this->getEnvironment();
@endphp

<x-filament-panels::page>
    <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 1rem;">
        <x-filament::section heading="This installation" icon="heroicon-o-cube" icon-color="primary" compact>
            <dl style="font-size: 0.875rem; display: grid; grid-template-columns: auto 1fr; gap: 0.375rem 1rem;">
                <dt style="color: var(--gray-500);">Version</dt>
                <dd style="font-weight: 500;">{{ $release['version'] }}</dd>

                <dt style="color: var(--gray-500);">Commit</dt>
                <dd style="font-family: ui-monospace, monospace; font-size: 0.75rem;">
                    {{ $release['commit'] ?? 'not recorded (source checkout)' }}
                </dd>

                <dt style="color: var(--gray-500);">Channel</dt>
                <dd>{{ $release['channel'] }}</dd>

                @foreach ($environment as $label => $value)
                    <dt style="color: var(--gray-500);">{{ $label }}</dt>
                    <dd>{{ $value }}</dd>
                @endforeach
            </dl>
        </x-filament::section>

        <x-filament::section heading="License" icon="heroicon-o-scale" icon-color="success" compact>
            <dl style="font-size: 0.875rem; display: grid; grid-template-columns: auto 1fr; gap: 0.375rem 1rem;">
                <dt style="color: var(--gray-500);">License</dt>
                <dd style="font-weight: 500;">
                    <a href="{{ $release['license_url'] }}" target="_blank" rel="noopener" style="text-decoration: underline;">
                        {{ $release['license_spdx'] }}
                    </a>
                </dd>

                <dt style="color: var(--gray-500);">Full name</dt>
                <dd>{{ $release['license_name'] }}</dd>

                <dt style="color: var(--gray-500);">Source</dt>
                <dd>
                    <a href="{{ $release['source_url_commit'] }}" target="_blank" rel="noopener" style="text-decoration: underline; word-break: break-all;">
                        {{ $release['source_url'] }}
                    </a>
                </dd>
            </dl>

            <div style="margin-top: 1rem; font-size: 0.8125rem; color: var(--gray-500); line-height: 1.5;">
                Orbital is free software. You may run it, study it, modify it, and
                redistribute it under the terms of the AGPL. Self-hosting is free
                with no feature gates and no seat limits.
                @if ($release['commit'])
                    The link above resolves to the exact commit this installation is
                    running.
                @endif
            </div>

            <div style="margin-top: 0.75rem; font-size: 0.8125rem; color: var(--gray-500); line-height: 1.5;">
                <strong>Modified this code?</strong> Section 13 of the AGPL asks you to
                offer your source to the people using it over the network. Point
                <code>ORBITAL_SOURCE_URL</code> at your own repository and this page,
                the panel footers, and <code>/source</code> will all direct your users
                there.
            </div>
        </x-filament::section>
    </div>

    <x-filament::section heading="Support" icon="heroicon-o-lifebuoy" :icon-color="$support['active'] ? 'success' : 'gray'" compact>
        <div style="display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap;">
            <x-filament::badge :color="$support['active'] ? 'success' : ($support['expired'] ? 'warning' : 'gray')">
                {{ $support['status'] }}
            </x-filament::badge>

            @if ($support['tier'])
                <span style="font-size: 0.875rem; color: var(--gray-500);">Tier: {{ $support['tier'] }}</span>
            @endif

            @if ($support['licensee'])
                <span style="font-size: 0.875rem; color: var(--gray-500);">Licensed to: {{ $support['licensee'] }}</span>
            @endif
        </div>

        <div style="margin-top: 0.75rem; font-size: 0.8125rem; color: var(--gray-500); line-height: 1.5;">
            A support subscription enables in-app ticket submission and the signed
            update channel. It does not unlock features — there are none to unlock.
            Every capability in Orbital is available to every installation, subscribed
            or not, forever.
            @if (! $support['active'] && $support['portal_url'])
                <a href="{{ $support['portal_url'] }}" target="_blank" rel="noopener" style="text-decoration: underline;">Support plans</a>.
            @endif
        </div>
    </x-filament::section>

    <x-filament::section heading="Third-party components" icon="heroicon-o-squares-2x2" icon-color="gray" compact collapsible collapsed>
        <div style="font-size: 0.8125rem; color: var(--gray-500); line-height: 1.6;">
            An Orbital deployment runs a number of independently licensed systems —
            Asterisk, Kamailio, rtpengine, LiveKit, PostgreSQL, Valkey, SeaweedFS,
            Prometheus, Grafana, Loki, and others. Each runs as a separate program in
            its own container, reached over a network or IPC interface; none is linked
            into Orbital.

            <div style="margin-top: 0.75rem;">
                The complete inventory with licenses ships in the source tree at
                <code>docs/third-party-licenses.md</code>, and the GPL-compatibility
                reasoning is in <code>NOTICE</code>.
            </div>
        </div>
    </x-filament::section>
</x-filament-panels::page>
