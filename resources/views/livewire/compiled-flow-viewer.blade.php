@php
    $view = $compiled['operator_view'] ?? [];
    $totalSteps = count($view);
    $active = $view[$activeStep] ?? null;
    $stores = $compiled['available_stores'] ?? [];
@endphp

<div>
    @if (empty($view))
        <div class="text-sm text-gray-400">
            No intake flow bound to this call. Attach a flow to the persona or extension to see objectives here.
        </div>
    @else
        {{-- Step progress strip --}}
        <div class="mb-4 flex items-center gap-1 overflow-x-auto">
            @foreach ($view as $i => $step)
                <button
                    type="button"
                    wire:click="$set('activeStep', {{ $i }})"
                    @class([
                        'shrink-0 rounded-full px-3 py-1 text-xs font-medium transition',
                        'bg-indigo-600 text-white' => $i === $activeStep,
                        'bg-white/5 text-gray-400 hover:bg-white/10' => $i !== $activeStep,
                    ])
                >
                    {{ $i + 1 }}. {{ $step['name'] ?? 'Step' }}
                </button>
            @endforeach
        </div>

        {{-- Active step detail --}}
        @if ($active)
            <div class="rounded-lg border border-white/10 bg-black/30 p-5">
                <div class="mb-3 flex items-start justify-between gap-4">
                    <div>
                        <h3 class="text-base font-semibold text-white">{{ $active['name'] ?? '' }}</h3>
                        @if (! empty($active['description']))
                            <p class="mt-1 text-xs text-gray-400">{{ $active['description'] }}</p>
                        @endif
                    </div>
                    <span class="shrink-0 rounded-full bg-white/5 px-2 py-0.5 text-[10px] uppercase tracking-wider text-gray-400">
                        Step {{ $activeStep + 1 }} of {{ $totalSteps }}
                    </span>
                </div>

                {{-- Talking points --}}
                @if (! empty($active['talking_points']))
                    <div class="mb-4">
                        <h4 class="mb-2 text-[11px] font-semibold uppercase tracking-wider text-gray-500">Say or ask</h4>
                        <ul class="space-y-1.5 text-sm text-gray-200">
                            @foreach ($active['talking_points'] as $point)
                                @php $text = is_array($point) ? ($point['text'] ?? '') : $point; @endphp
                                @if ($text)
                                    <li class="flex gap-2">
                                        <span class="text-gray-600">→</span>
                                        <span>{{ $text }}</span>
                                    </li>
                                @endif
                            @endforeach
                        </ul>
                    </div>
                @endif

                {{-- Data fields --}}
                @if (! empty($active['data_fields']))
                    <div class="mb-4 space-y-2">
                        <h4 class="text-[11px] font-semibold uppercase tracking-wider text-gray-500">Collect</h4>
                        @foreach ($active['data_fields'] as $field)
                            @php
                                $key = $field['key'] ?? null;
                                $label = $field['label'] ?? $key;
                                $type = $field['type'] ?? 'string';
                                $required = (bool) ($field['required'] ?? false);
                                $hint = $field['hint'] ?? null;
                                $stateKey = 'fields.'.$key;
                            @endphp
                            @if ($key)
                                <div>
                                    <label class="mb-0.5 block text-xs text-gray-400">
                                        {{ $label }}
                                        @if ($required)
                                            <span class="text-red-400">*</span>
                                        @endif
                                    </label>
                                    @if ($type === 'textarea')
                                        <textarea
                                            wire:model.defer="{{ $stateKey }}"
                                            rows="2"
                                            class="w-full rounded-md border border-white/10 bg-black/40 px-3 py-1.5 text-sm text-white placeholder-gray-600 focus:border-indigo-500 focus:outline-none"
                                        ></textarea>
                                    @elseif ($type === 'boolean')
                                        <label class="inline-flex items-center gap-2 text-sm text-gray-200">
                                            <input
                                                type="checkbox"
                                                wire:model.defer="{{ $stateKey }}"
                                                class="h-4 w-4 rounded border-white/20 bg-black/40 text-indigo-500 focus:ring-indigo-500"
                                            >
                                            <span>{{ $hint ?? 'Yes' }}</span>
                                        </label>
                                    @else
                                        <input
                                            type="text"
                                            wire:model.defer="{{ $stateKey }}"
                                            class="w-full rounded-md border border-white/10 bg-black/40 px-3 py-1.5 text-sm text-white placeholder-gray-600 focus:border-indigo-500 focus:outline-none"
                                            @if ($hint) placeholder="{{ $hint }}" @endif
                                        >
                                    @endif
                                </div>
                            @endif
                        @endforeach
                    </div>
                @endif

                {{-- Nav --}}
                <div class="mt-5 flex items-center justify-between gap-2 border-t border-white/10 pt-4">
                    <button
                        type="button"
                        wire:click="previousStep"
                        @if ($activeStep === 0) disabled @endif
                        class="rounded-md border border-white/10 px-3 py-1.5 text-xs text-gray-300 hover:bg-white/5 disabled:opacity-30"
                    >
                        ← Previous
                    </button>
                    <button
                        type="button"
                        wire:click="advanceStep"
                        @if ($activeStep >= $totalSteps - 1) disabled @endif
                        class="rounded-md bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-indigo-500 disabled:opacity-30"
                    >
                        Next →
                    </button>
                </div>
            </div>
        @endif

        {{-- Knowledge stores surfaced by the compiled flow --}}
        @if (! empty($stores))
            <div class="mt-4 rounded-lg border border-white/10 bg-black/20 p-4">
                <h4 class="mb-2 text-[11px] font-semibold uppercase tracking-wider text-gray-500">
                    Knowledge Available
                </h4>
                <ul class="space-y-1 text-xs text-gray-300">
                    @foreach ($stores as $store)
                        <li class="flex items-center gap-2">
                            <span class="text-gray-500">●</span>
                            <span class="font-medium">{{ $store['name'] ?? '' }}</span>
                            @if (! empty($store['description']))
                                <span class="text-gray-500">— {{ $store['description'] }}</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    @endif
</div>
