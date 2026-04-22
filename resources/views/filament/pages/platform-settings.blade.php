<x-filament-panels::page>
    <form wire:submit="save" id="form">
        {{ $this->form }}

        <div style="margin-top: 1.5rem;">
            <x-filament::actions
                :actions="$this->getCachedFormActions()"
                :alignment="\Filament\Support\Enums\Alignment::End"
            />
        </div>
    </form>
</x-filament-panels::page>
