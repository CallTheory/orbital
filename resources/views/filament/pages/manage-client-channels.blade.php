<x-filament-panels::page>
    @push('styles')
        <style>
            /* Keep Filament's default card chrome on `.fi-tabs:not(.fi-contained)`
               (rounded background, shadow, ring) but override the `mx-auto`
               that was constraining the card to its content width, and
               stretch the four pills evenly across the row. */
            .channels-tabs {
                margin-inline: 0 !important;
                width: 100%;
            }
            .channels-tabs > .fi-tabs-item {
                flex: 1 1 0%;
                justify-content: center;
            }
        </style>
    @endpush

    <x-filament::tabs label="Channels" class="channels-tabs">
        @foreach ($this->getChannelTabs() as $tab)
            <x-filament::tabs.item
                :active="$this->activeTab === $tab['key']"
                :icon="$tab['icon']"
                wire:click="setTab('{{ $tab['key'] }}')"
            >
                {{ $tab['label'] }}
            </x-filament::tabs.item>
        @endforeach
    </x-filament::tabs>

    {{ $this->table }}
</x-filament-panels::page>
