<x-filament-panels::page>
    <div wire:poll.60s="refreshCertInfo">

        @if (! empty($cert['isStaging']))
            <div style="background: var(--warning-50); border: 1px solid var(--warning-300); border-radius: 0.5rem; padding: 0.75rem 1rem; margin-bottom: 1.5rem; font-size: 0.875rem; color: var(--warning-700);">
                <strong>Staging certificate.</strong> This cert was issued from Let's Encrypt's staging server — browsers will show a security warning. Use the "Issue Certificate" action with "Production" enabled to get a real cert.
            </div>
        @endif

        @if (! $certExists || ! $cert)
            {{-- No cert — setup guide --}}
            <x-filament::section icon="heroicon-o-lock-open" icon-color="gray">
                <div style="text-align: center; padding: 2rem 0;">
                    <div style="font-size: 1rem; font-weight: 600; margin-bottom: 0.5rem;">No TLS certificate found</div>
                    <div style="font-size: 0.875rem; color: var(--gray-500); max-width: 32rem; margin: 0 auto;">
                        Configure <code>ACME_DOMAIN</code> and your DNS provider credentials in <code>.env</code>,
                        then click <strong>Issue Certificate</strong> above to request a wildcard cert from Let's Encrypt.
                    </div>
                </div>
            </x-filament::section>
        @else
            {{-- Certificate info + expiry --}}
            <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 1rem; margin-bottom: 1.5rem;">
                <x-filament::section heading="Certificate" icon="heroicon-o-lock-closed" icon-color="success" compact>
                    <dl style="font-size: 0.875rem; display: grid; grid-template-columns: auto 1fr; gap: 0.375rem 1rem;">
                        <dt style="color: var(--gray-500);">Domain</dt>
                        <dd style="font-weight: 500;">{{ $cert['domain'] }}</dd>
                        <dt style="color: var(--gray-500);">Issuer</dt>
                        <dd>{{ $cert['issuer'] }}</dd>
                        <dt style="color: var(--gray-500);">Serial</dt>
                        <dd style="font-family: ui-monospace, monospace; font-size: 0.75rem;">{{ $cert['serial'] }}</dd>
                        <dt style="color: var(--gray-500);">Wildcard</dt>
                        <dd>{{ $cert['isWildcard'] ? 'Yes' : 'No' }}</dd>
                        @if (! empty($cert['sanList']))
                            <dt style="color: var(--gray-500);">SANs</dt>
                            <dd style="font-size: 0.75rem;">{{ implode(', ', $cert['sanList']) }}</dd>
                        @endif
                    </dl>
                </x-filament::section>

                <x-filament::section heading="Expiry" icon="heroicon-o-clock" :icon-color="$cert['badgeColor']" compact>
                    <div style="text-align: center; padding: 1rem 0;">
                        <x-filament::badge :color="$cert['badgeColor']" size="lg">
                            @if ($cert['isExpired'])
                                Expired
                            @else
                                {{ $cert['daysRemaining'] }} days remaining
                            @endif
                        </x-filament::badge>
                        <div style="margin-top: 0.75rem; font-size: 0.75rem; color: var(--gray-500);">
                            <div>Valid from: {{ $cert['validFrom'] }}</div>
                            <div>Valid to: {{ $cert['validTo'] }}</div>
                        </div>
                    </div>
                </x-filament::section>
            </div>
        @endif

        {{-- Consuming services --}}
        <x-filament::section heading="Services using this certificate" compact>
            <div style="display: flex; flex-direction: column; gap: 0.5rem;">
                @foreach ($services as $svc)
                    <div style="display: flex; align-items: center; justify-content: space-between; padding: 0.5rem 0; border-bottom: 1px solid rgba(128, 128, 128, 0.15);">
                        <div style="display: flex; align-items: center; gap: 0.75rem;">
                            <x-filament::badge color="gray">
                                {{ $svc['name'] }}
                            </x-filament::badge>
                            <span style="font-size: 0.875rem;">{{ $svc['protocol'] }}</span>
                        </div>
                        <span style="font-family: ui-monospace, monospace; font-size: 0.75rem; color: var(--gray-500);">
                            port {{ $svc['port'] }}
                        </span>
                    </div>
                @endforeach
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
