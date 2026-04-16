<x-filament-panels::page>
    {{ $this->table }}

    @livewire(\App\Livewire\ThreadActivityTable::class, ['threadId' => $this->thread->id])
</x-filament-panels::page>
