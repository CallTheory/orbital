<x-filament-panels::page>
    {{-- Grid classes come from filament.partials.panel-styles (see
         panel-styles.blade.php). Tailwind utility classes don't work
         inside Filament panels without a custom theme. --}}
    <div class="orbital-page-grid">
        <div class="orbital-page-grid-span-2">
            <x-filament::section
                heading="Intake Flow"
                icon="heroicon-o-list-bullet"
            >
                @livewire('compiled-flow-viewer')
            </x-filament::section>
        </div>

        <div>
            <x-filament::section
                heading="Queue Status"
                icon="heroicon-o-queue-list"
            >
                <p style="font-size: 0.875rem; color: rgb(107 114 128);">
                    Waiting for calls...
                </p>
            </x-filament::section>
        </div>
    </div>
</x-filament-panels::page>
