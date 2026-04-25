@php
    $view = $compiled['operator_view'] ?? [];
    $flows = $view['flows'] ?? [];
    $slots = $view['slots'] ?? [];

    $activeFlow = collect($flows)->firstWhere('id', $activeFlowId) ?? ($flows[0] ?? null);
    $steps = $activeFlow['steps'] ?? [];
    $activeStepData = $steps[$activeStep] ?? null;
    $transitions = $activeFlow['transitions'] ?? [];
@endphp

<div>
    @if (empty($flows))
        <div style="font-size: 0.875rem; color: var(--gray-400); padding: 0.5rem 0;">
            No intake flow configured for this account.
        </div>
    @else
        {{-- Flow selector (when the graph has more than one flow) --}}
        @if (count($flows) > 1)
            <div style="display: flex; gap: 0.375rem; margin-bottom: 0.75rem; overflow-x: auto; padding-bottom: 0.25rem;">
                @foreach ($flows as $flow)
                    @php $isActive = $flow['id'] === ($activeFlow['id'] ?? null); @endphp
                    <button
                        type="button"
                        wire:click="switchFlow({{ $flow['id'] }})"
                        style="
                            flex-shrink: 0;
                            padding: 0.3125rem 0.75rem;
                            border-radius: 0.375rem;
                            font-size: 0.75rem;
                            font-weight: 600;
                            cursor: pointer;
                            border: 1px solid {{ $isActive ? 'var(--primary-500)' : 'rgba(128,128,128,0.2)' }};
                            background: {{ $isActive ? 'var(--primary-600)' : 'transparent' }};
                            color: {{ $isActive ? 'white' : 'var(--gray-400)' }};
                        "
                    >
                        {{ $flow['name'] }}
                        @if (! empty($flow['is_entry']) && ! $isActive)
                            <span style="margin-left: 0.25rem; font-size: 0.625rem; font-weight: 500; opacity: 0.7;">entry</span>
                        @endif
                    </button>
                @endforeach
            </div>
        @endif

        {{-- Step tabs within the active flow --}}
        @if (count($steps) > 1)
            <div style="display: flex; gap: 0.375rem; margin-bottom: 0.75rem; overflow-x: auto;">
                @foreach ($steps as $i => $step)
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
        @if ($activeStepData)
            @if (! empty($activeStepData['description']))
                <div style="font-size: 0.75rem; color: var(--gray-500); margin-bottom: 0.75rem;">
                    {{ $activeStepData['description'] }}
                </div>
            @endif

            @if (! empty($activeStepData['talking_points']))
                <ul style="list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 0.375rem;">
                    @foreach ($activeStepData['talking_points'] as $point)
                        @php $text = is_array($point) ? ($point['text'] ?? '') : $point; @endphp
                        @if ($text)
                            <li style="display: flex; gap: 0.5rem; font-size: 0.8125rem; color: var(--gray-300);">
                                <span style="color: var(--gray-500); flex-shrink: 0;">&rarr;</span>
                                <span>{{ $text }}</span>
                            </li>
                        @endif
                    @endforeach
                </ul>
            @endif

            {{-- Per-step params that are user-visible (slot, label, etc.) --}}
            @php $params = $activeStepData['step_params'] ?? []; @endphp
            @if (! empty($params['slot']) || ! empty($params['include_slots']))
                <div style="margin-top: 0.75rem; padding-top: 0.75rem; border-top: 1px solid rgba(128,128,128,0.15);">
                    <div style="font-size: 0.625rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: var(--gray-500); margin-bottom: 0.375rem;">
                        Slots
                    </div>
                    <ul style="list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 0.25rem;">
                        @if (! empty($params['slot']))
                            <li style="font-size: 0.75rem; color: var(--gray-400);">
                                <span style="color: var(--gray-600);">writes</span>
                                <span style="font-family: ui-monospace, monospace;">{{ $params['slot'] }}</span>
                            </li>
                        @endif
                        @if (! empty($params['include_slots']) && is_array($params['include_slots']))
                            @foreach ($params['include_slots'] as $slotName)
                                <li style="font-size: 0.75rem; color: var(--gray-400);">
                                    <span style="color: var(--gray-600);">reads</span>
                                    <span style="font-family: ui-monospace, monospace;">{{ $slotName }}</span>
                                </li>
                            @endforeach
                        @endif
                    </ul>
                </div>
            @endif
        @endif

        {{-- Transitions out of the current flow --}}
        @if (! empty($transitions))
            <div style="margin-top: 0.75rem; padding-top: 0.75rem; border-top: 1px solid rgba(128,128,128,0.15);">
                <div style="font-size: 0.625rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: var(--gray-500); margin-bottom: 0.375rem;">
                    Next step
                </div>
                <ul style="list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 0.25rem;">
                    @foreach ($transitions as $t)
                        @php
                            $targetLabel = $t['ends_call'] ? 'end the call' : ($t['to_flow_name'] ?? '?');
                            $gate = $t['is_fallback'] ? 'otherwise' : ($t['condition_human'] ?? '');
                        @endphp
                        <li style="font-size: 0.75rem; color: var(--gray-400);">
                            <span style="color: var(--gray-600);">{{ $gate }}</span>
                            <span style="color: var(--gray-500);">&rarr;</span>
                            <span>{{ $targetLabel }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    @endif
</div>
