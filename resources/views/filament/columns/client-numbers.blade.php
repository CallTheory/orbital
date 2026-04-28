@php
    /**
     * Multi-DID indicator for the cross-client admin list. Shows the
     * first two DIDs (regardless of active status) as badges, then a
     * "+N more" gray chip when the client has more. Each badge is
     * color-coded by the DID's own `is_active` flag — green for
     * enabled numbers, gray for disabled. Search across the list
     * still walks every DID via the column's `searchable(query: …)`
     * callback even when the matching number is hidden behind the
     * overflow chip.
     */
    $dids = $record->dids->values();
    $shown = $dids->take(2);
    $remaining = max(0, $dids->count() - 2);
@endphp

@if ($dids->isEmpty())
    <span style="color: var(--gray-500);">—</span>
@else
    <div style="display: inline-flex; flex-wrap: wrap; gap: 0.375rem; align-items: center;">
        @foreach ($shown as $did)
            <x-filament::badge :color="$did->is_active ? 'success' : 'gray'" size="sm">
                {{ $did->number }}
            </x-filament::badge>
        @endforeach
        @if ($remaining > 0)
            <x-filament::badge color="gray" size="sm">+{{ $remaining }} more</x-filament::badge>
        @endif
    </div>
@endif
