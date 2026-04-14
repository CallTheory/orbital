<x-filament-panels::page>
    <div class="orbital-dashboard-grid">
        @foreach ($reports as $key => $r)
            <x-filament::section
                :heading="$r['name']"
                :icon="$r['icon']"
                :icon-color="$r['status_color']"
            >
                <x-slot name="afterHeader">
                    <x-filament::badge :color="$r['status_color']">
                        {{ $r['status_label'] }}
                    </x-filament::badge>
                </x-slot>

                <p class="text-sm text-gray-600 dark:text-gray-400">
                    {{ $r['description'] }}
                </p>

                <p class="mt-2 text-xs text-gray-500 dark:text-gray-500">
                    {{ $r['message'] }}
                </p>

                @if (! empty($r['steps']))
                    <dl class="orbital-card-metrics">
                        @foreach ($r['steps'] as $step)
                            <div>
                                <dt>{{ $step['ok'] ? '✓' : '✗' }} {{ $step['label'] }}:</dt>
                                <dd>{{ $step['detail'] ?? ($step['ok'] ? 'ok' : 'missing') }}</dd>
                            </div>
                        @endforeach
                    </dl>
                @endif

                <div class="orbital-card-actions">
                    <x-filament::button
                        size="sm"
                        color="primary"
                        wire:click="installOne('{{ $key }}')"
                    >
                        Install / Re-run
                    </x-filament::button>
                </div>
            </x-filament::section>
        @endforeach
    </div>
</x-filament-panels::page>
