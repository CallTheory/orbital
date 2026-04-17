@php
    $view = $compiled['operator_view'] ?? [];
    $totalSteps = count($view);
    $active = $view[$activeStep] ?? null;
@endphp

<div>
    @if (empty($view))
        <div style="font-size: 0.875rem; color: var(--gray-400); padding: 0.5rem 0;">
            No intake flow configured for this account.
        </div>
    @else
        {{-- Step tabs --}}
        @if ($totalSteps > 1)
            <div style="display: flex; gap: 0.375rem; margin-bottom: 0.75rem; overflow-x: auto;">
                @foreach ($view as $i => $step)
                    <button
                        type="button"
                        wire:click="$set('activeStep', {{ $i }})"
                        style="
                            flex-shrink: 0;
                            padding: 0.25rem 0.625rem;
                            border-radius: 0.375rem;
                            font-size: 0.6875rem;
                            font-weight: 500;
                            cursor: pointer;
                            border: 1px solid {{ $i === $activeStep ? 'var(--primary-500)' : 'rgba(128,128,128,0.15)' }};
                            background: {{ $i === $activeStep ? 'var(--primary-600)' : 'transparent' }};
                            color: {{ $i === $activeStep ? 'white' : 'var(--gray-400)' }};
                        "
                    >
                        {{ $step['name'] ?? 'Step '.($i + 1) }}
                    </button>
                @endforeach
            </div>
        @endif

        {{-- Active step --}}
        @if ($active)
            @if (! empty($active['description']))
                <div style="font-size: 0.75rem; color: var(--gray-500); margin-bottom: 0.75rem;">
                    {{ $active['description'] }}
                </div>
            @endif

            {{-- Talking points --}}
            @if (! empty($active['talking_points']))
                <ul style="list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 0.375rem;">
                    @foreach ($active['talking_points'] as $point)
                        @php $text = is_array($point) ? ($point['text'] ?? '') : $point; @endphp
                        @if ($text)
                            <li style="display: flex; gap: 0.5rem; font-size: 0.8125rem; color: var(--gray-300);">
                                <span style="color: var(--gray-500); flex-shrink: 0;">→</span>
                                <span>{{ $text }}</span>
                            </li>
                        @endif
                    @endforeach
                </ul>
            @endif

            {{-- Fields to collect (read-only checklist) --}}
            @if (! empty($active['data_fields']))
                <div style="margin-top: 0.75rem; padding-top: 0.75rem; border-top: 1px solid rgba(128,128,128,0.15);">
                    <div style="font-size: 0.625rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: var(--gray-500); margin-bottom: 0.375rem;">
                        Collect
                    </div>
                    <ul style="list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 0.25rem;">
                        @foreach ($active['data_fields'] as $field)
                            <li style="display: flex; align-items: center; gap: 0.375rem; font-size: 0.75rem; color: var(--gray-400);">
                                <span style="color: var(--gray-600);">○</span>
                                {{ $field['label'] ?? $field['key'] ?? '' }}
                                @if (! empty($field['required']))
                                    <span style="color: var(--danger-500); font-size: 0.625rem;">required</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        @endif
    @endif
</div>
