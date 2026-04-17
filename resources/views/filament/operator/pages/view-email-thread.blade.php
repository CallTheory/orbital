<x-filament-panels::page>
    <style>
        .fi-thread-layout {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1.5rem;
        }
        @media (min-width: 1024px) {
            .fi-thread-layout {
                grid-template-columns: 3fr 1fr;
            }
        }
        .fi-thread-messages .fi-in-entry-label {
            color: rgb(156 163 175) !important;
            font-weight: 400 !important;
        }
    </style>

    <div class="fi-thread-layout">
        <div class="fi-thread-messages">
            {{ $this->messagesInfolist }}
        </div>
        <div>
            {{ $this->table }}
        </div>
    </div>
</x-filament-panels::page>
