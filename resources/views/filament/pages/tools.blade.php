<x-filament-panels::page>
    @php
        $tools = $this->getTools();
        $grouped = collect($tools)->groupBy('group');
    @endphp

    @foreach ($grouped as $group => $items)
        <h2 class="mt-6 mb-3 text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
            {{ $group }}
        </h2>
        <div class="orbital-dashboard-grid">
            @foreach ($items as $tool)
                @php $result = $lastOutput[$tool['id']] ?? null; @endphp
                <div
                    x-data="{ open: false }"
                    @tool-ran-{{ $tool['id'] }}.window="open = true"
                >
                    <x-filament::section
                        :heading="$tool['name']"
                        :icon="$tool['icon']"
                        compact
                    >
                        @if ($result)
                            <x-slot name="afterHeader">
                                <x-filament::badge :color="$result['exit_code'] === 0 ? 'success' : 'warning'">
                                    {{ $result['exit_code'] === 0 ? 'OK' : 'exit '.$result['exit_code'] }}
                                </x-filament::badge>
                            </x-slot>
                        @endif

                        <div class="orbital-card-body">
                            <p class="orbital-card-message">
                                {{ $tool['description'] }}
                            </p>
                            <p class="mt-2 font-mono text-xs text-gray-500 dark:text-gray-400">
                                {{ $tool['command'] }}
                            </p>

                            <div class="orbital-card-actions" style="gap: 0.5rem;">
                                @if ($result)
                                    <x-filament::button
                                        size="sm"
                                        color="gray"
                                        @click="open = true"
                                    >
                                        Last run
                                    </x-filament::button>
                                @endif

                                <x-filament::button
                                    size="sm"
                                    color="primary"
                                    wire:click="runTool('{{ $tool['id'] }}')"
                                    wire:loading.attr="disabled"
                                    wire:target="runTool('{{ $tool['id'] }}')"
                                >
                                    <span wire:loading.remove wire:target="runTool('{{ $tool['id'] }}')">
                                        Run
                                    </span>
                                    <span wire:loading wire:target="runTool('{{ $tool['id'] }}')">
                                        Running…
                                    </span>
                                </x-filament::button>
                            </div>
                        </div>
                    </x-filament::section>

                    {{-- Output modal. Opens on the `tool-ran-{id}`
                         browser event (fired by runTool) or when the
                         "Last run" button toggles `open`. Only rendered
                         if there's something to show. --}}
                    @if ($result)
                        <div
                            x-cloak
                            x-show="open"
                            @keydown.escape.window="open = false"
                            class="orbital-modal-backdrop"
                            @click.self="open = false"
                        >
                            <div class="orbital-modal" style="max-width: 44rem;">
                                <div class="orbital-modal-header">
                                    <div class="orbital-modal-title">
                                        {{ $tool['name'] }}
                                    </div>
                                    <button type="button"
                                            class="orbital-modal-close"
                                            @click="open = false"
                                            aria-label="Close">
                                        ✕
                                    </button>
                                </div>
                                <div class="orbital-modal-body">
                                    <div class="flex items-center justify-between gap-3 text-xs text-gray-500 dark:text-gray-400">
                                        <span class="font-mono">{{ $tool['command'] }}</span>
                                        <span>
                                            <x-filament::badge
                                                size="xs"
                                                :color="$result['exit_code'] === 0 ? 'success' : 'warning'"
                                            >
                                                {{ $result['exit_code'] === 0 ? 'exit 0' : 'exit '.$result['exit_code'] }}
                                            </x-filament::badge>
                                            <span class="ml-2">Ran at {{ $result['ran_at'] }}</span>
                                        </span>
                                    </div>
                                    <pre
                                        class="rounded bg-gray-900 p-3 font-mono text-xs leading-snug text-gray-100"
                                        style="white-space: pre-wrap; word-break: break-word; overflow-wrap: anywhere; max-height: 60vh; overflow-y: auto;"
                                    >{{ $result['output'] }}</pre>
                                </div>
                            </div>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    @endforeach
</x-filament-panels::page>
