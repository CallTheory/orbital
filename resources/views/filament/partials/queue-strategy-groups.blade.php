@php
    /**
     * Side-panel listing on the QueueStrategyTemplate edit page —
     * shows every agent group currently using this strategy. Clicking
     * a row jumps to that group's edit page so the operator can
     * reassign or audit. Renders an empty-state line when nothing
     * uses the template (typical for a freshly created or stale row).
     *
     * `$record` is null on the create page; treat that as empty.
     */
    $groups = $record?->agentGroups()->orderBy('label')->get() ?? collect();
@endphp

@if ($groups->isEmpty())
    <p style="margin: 0; font-style: italic; color: var(--gray-500);">
        Not assigned to any platform groups.
    </p>
@else
    <ul style="margin: 0; padding: 0; list-style: none; display: flex; flex-direction: column; gap: 0.375rem;">
        @foreach ($groups as $group)
            <li>
                <x-filament::link
                    :href="\App\Filament\Resources\AgentGroupResource::getUrl('edit', ['record' => $group])"
                >
                    {{ $group->label }}
                </x-filament::link>
            </li>
        @endforeach
    </ul>
@endif
