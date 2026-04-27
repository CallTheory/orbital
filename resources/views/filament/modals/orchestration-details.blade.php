@php
    /**
     * Orchestration "details card" — body of the Action modal that
     * opens when an author clicks an orchestration row. Two Filament
     * sections sit inline (Channels + collapsible Assignments) so the
     * operator can scan who's wired up at a glance even when the
     * orchestration fans out to 15-20 clients.
     *
     * Per-client orchestrations omit the Assignments section entirely
     * because every queue is in the same client and the channel
     * tooltip already covers it.
     */
    $isShared = $record->isShared();
    $assignments = collect();
    if ($isShared) {
        $assignments = collect()
            ->merge($record->callQueues->map(fn ($q) => [
                'channel' => 'Phone',
                'queue' => $q,
                'team' => $q->team,
            ]))
            ->merge($record->emailQueues->map(fn ($q) => [
                'channel' => 'Email',
                'queue' => $q,
                'team' => $q->team,
            ]))
            ->groupBy(fn ($a) => $a['team']?->name ?? '(unknown client)')
            ->sortKeys();
    }
@endphp

<div @class([
    'fi-grid grid gap-4',
    'grid-cols-1 md:grid-cols-2' => $isShared,
])>
    <x-filament::section
        heading="Channels"
        compact
    >
        @include('filament.columns.orchestration-channels', ['record' => $record])
    </x-filament::section>

    @if ($isShared)
        <x-filament::section
            heading="Assignments ({{ $assignments->flatten(1)->count() }})"
            collapsible
            collapsed
            compact
        >
            @if ($assignments->isEmpty())
                <p class="fi-fo-section-content text-sm italic text-gray-500 dark:text-gray-400">
                    Not assigned to any client yet.
                </p>
            @else
                <ul class="divide-y divide-gray-200 dark:divide-white/10 max-h-72 overflow-y-auto">
                    @foreach ($assignments as $clientName => $rows)
                        <li class="py-2 first:pt-0 last:pb-0">
                            <div class="text-sm font-semibold text-gray-950 dark:text-white">
                                {{ $clientName }}
                            </div>
                            <ul class="mt-1 flex flex-col gap-1">
                                @foreach ($rows as $row)
                                    <li class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                                        <x-filament::badge size="xs" color="gray">
                                            {{ $row['channel'] }}
                                        </x-filament::badge>
                                        <span>{{ $row['queue']->name }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-filament::section>
    @endif
</div>
