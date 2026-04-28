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

    // Both per-client and shared orchestrations get the Assignments
    // section so authors can see "is this in use, and where" without
    // leaving the modal. For per-client, the client column is dropped
    // (it's always the owning client and obvious from context).
    $hubUrl = fn ($team) => $team
        ? \App\Filament\Resources\ClientResource::getUrl('channels', ['record' => $team])
        : null;

    $assignments = collect()
        ->merge($record->callQueues->map(fn ($q) => [
            'channel' => 'Call',
            'queue' => $q,
            'team' => $q->team,
            'queue_url' => $hubUrl($q->team),
        ]))
        ->merge($record->emailQueues->map(fn ($q) => [
            'channel' => 'Email',
            'queue' => $q,
            'team' => $q->team,
            'queue_url' => $hubUrl($q->team),
        ]))
        ->merge($record->messageQueues->map(fn ($q) => [
            'channel' => 'Message',
            'queue' => $q,
            'team' => $q->team,
            'queue_url' => $hubUrl($q->team),
        ]))
        ->merge($record->chatQueues->map(fn ($q) => [
            'channel' => 'Chat',
            'queue' => $q,
            'team' => $q->team,
            'queue_url' => $hubUrl($q->team),
        ]))
        ->sortBy([
            ['team.name', 'asc'],
            ['channel', 'asc'],
            ['queue.name', 'asc'],
        ])
        ->values();
@endphp

<div style="display: flex; flex-direction: column; gap: 1rem;">
    <x-filament::section heading="Channels" compact>
        @include('filament.columns.orchestration-channels', ['record' => $record])
    </x-filament::section>

    <x-filament::section
        heading="Assignments ({{ $assignments->count() }})"
        collapsible
        collapsed
        compact
    >
        @if ($assignments->isEmpty())
            <p style="margin: 0; font-style: italic;">
                {{ $isShared ? 'Not assigned to any client yet.' : 'Not assigned to any queue yet.' }}
            </p>
        @else
            <div style="max-height: 18rem; overflow-y: auto;">
                <table style="width: 100%; border-collapse: collapse; font-size: 0.875rem;">
                    <thead>
                        <tr style="text-align: left;">
                            @if ($isShared)
                                <th style="padding: 0.375rem 0.75rem 0.375rem 0; font-weight: 600;">Client</th>
                            @endif
                            <th style="padding: 0.375rem 0.75rem; font-weight: 600;">Channel</th>
                            <th style="padding: 0.375rem 0; font-weight: 600;">Queue</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($assignments as $row)
                            <tr style="border-top: 1px solid color-mix(in srgb, currentColor 15%, transparent);">
                                @if ($isShared)
                                    <td style="padding: 0.5rem 0.75rem 0.5rem 0;">
                                        @if ($row['queue_url'])
                                            <x-filament::link
                                                :href="$row['queue_url']"
                                                :tooltip="'Open channels for ' . ($row['team']?->name ?? '')"
                                            >
                                                {{ $row['team']?->name ?? '(unknown client)' }}
                                            </x-filament::link>
                                        @else
                                            {{ $row['team']?->name ?? '(unknown client)' }}
                                        @endif
                                    </td>
                                @endif
                                <td style="padding: 0.5rem 0.75rem;">
                                    <x-filament::badge size="xs" color="gray">
                                        {{ $row['channel'] }}
                                    </x-filament::badge>
                                </td>
                                <td style="padding: 0.5rem 0;">
                                    @if (! $isShared && $row['queue_url'])
                                        <x-filament::link :href="$row['queue_url']">
                                            {{ $row['queue']->name }}
                                        </x-filament::link>
                                    @else
                                        {{ $row['queue']->name }}
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</div>
