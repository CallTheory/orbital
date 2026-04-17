<x-filament::section compact>
    <x-slot name="heading">
        <span style="font-size: 0.625rem; text-transform: uppercase; letter-spacing: 0.05em;">Recent Accounts</span>
    </x-slot>

    <div style="display: flex; flex-direction: column; gap: 0.375rem;">
        @foreach ($accounts as $account)
            <x-filament::button
                size="sm"
                color="gray"
                wire:click="fetchRecentAccount({{ $account['id'] }})"
                type="button"
                style="justify-content: flex-start;"
            >
                {{ $account['name'] }} (#{{ $account['account_number'] }})
            </x-filament::button>
        @endforeach
    </div>
</x-filament::section>
