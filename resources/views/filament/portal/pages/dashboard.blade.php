<x-filament-panels::page>
    {{-- .orbital-* classes are defined in filament.partials.panel-styles
         (HEAD_END render hook), so they're available on every Filament
         page without needing a custom theme / Vite recompile. --}}
    <div class="orbital-page-grid">
        <div class="orbital-page-grid-span-2">
            <x-filament::section
                icon="heroicon-o-building-storefront"
                heading="{{ $tenantName }}"
                description="Your account activity at a glance."
            >
                <div class="orbital-stat-grid">
                    <div class="orbital-stat-card">
                        <div class="orbital-stat-label">Calls today</div>
                        <div class="orbital-stat-value">{{ $callsTodayCount }}</div>
                    </div>
                    <div class="orbital-stat-card">
                        <div class="orbital-stat-label">Recent calls</div>
                        <div class="orbital-stat-value">{{ count($recentCalls) }}</div>
                    </div>
                </div>
            </x-filament::section>
        </div>

        <div>
            <x-filament::section icon="heroicon-o-clock" heading="Latest Activity">
                @if (empty($recentCalls))
                    <p style="font-size: 0.875rem; color: rgb(107 114 128);">
                        No call activity yet.
                    </p>
                @else
                    <ul class="orbital-activity-list">
                        @foreach ($recentCalls as $call)
                            <li class="orbital-activity-row">
                                <span class="label">
                                    {{ $call['from_number'] ?? '—' }} → {{ $call['to_number'] ?? '—' }}
                                </span>
                                <span class="when">{{ $call['started_at'] }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-filament::section>
        </div>
    </div>
</x-filament-panels::page>
