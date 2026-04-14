<x-filament-panels::page>
    {{-- Polls every 60s — matches the server-side health cache TTL so
         a fresh set of checks runs on each tick instead of replaying
         stale cached results. `refresh()` on the Dashboard class
         clears the cache before re-running. --}}
    <div class="orbital-dashboard-grid" wire:poll.60s="refresh">
        @foreach ($this->checks as $check)
            <x-filament::section
                :heading="$check['name']"
                :icon="$check['icon']"
                :icon-color="$check['color']"
                compact
            >
                <x-slot name="afterHeader">
                    <x-filament::badge :color="$check['color']">
                        {{ $check['statusLabel'] }}
                    </x-filament::badge>
                </x-slot>

                <p class="text-sm text-gray-600 dark:text-gray-400">
                    {{ $check['message'] }}
                </p>

                @if (! empty($check['metrics']))
                    <dl class="orbital-card-metrics">
                        @foreach ($check['metrics'] as $label => $value)
                            <div>
                                <dt>{{ $label }}:</dt>
                                <dd>{{ $value }}</dd>
                            </div>
                        @endforeach
                    </dl>
                @endif
            </x-filament::section>
        @endforeach
    </div>
</x-filament-panels::page>
