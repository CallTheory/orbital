<x-filament::section compact>
    <x-slot name="heading">Message Details</x-slot>

    <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
        <dt class="text-gray-500 dark:text-gray-400">From</dt>
        <dd class="font-medium">{{ $message->from_name ? "{$message->from_name} <{$message->from_address}>" : $message->from_address }}</dd>

        <dt class="text-gray-500 dark:text-gray-400">To</dt>
        <dd>{{ collect($message->to_addresses)->map(fn ($a) => is_array($a) ? ($a['address'] ?? '') : $a)->filter()->join(', ') }}</dd>

        <dt class="text-gray-500 dark:text-gray-400">Delivered to</dt>
        <dd>{{ implode(', ', $message->metadata['envelope_to'] ?? []) }}</dd>

        <dt class="text-gray-500 dark:text-gray-400">Subject</dt>
        <dd class="font-medium">{{ $message->subject ?: '(none)' }}</dd>

        <dt class="text-gray-500 dark:text-gray-400">Received</dt>
        <dd>{{ $message->received_at?->timezone($tz)->format('M j, Y g:i:s a T') }}</dd>

        <dt class="text-gray-500 dark:text-gray-400">Status</dt>
        <dd>
            <x-filament::badge :color="$message->routing_status === 'failed' ? 'danger' : 'warning'">
                {{ $message->routing_status }}
            </x-filament::badge>
        </dd>

        @if ($message->metadata['last_error'] ?? null)
            <dt class="text-gray-500 dark:text-gray-400">Error</dt>
            <dd class="text-danger-600 dark:text-danger-400">{{ $message->metadata['last_error'] }}</dd>
        @endif
    </dl>
</x-filament::section>

<x-filament::section compact>
    <x-slot name="heading">Body</x-slot>

    @if ($message->body_text)
        <div class="text-sm text-gray-800 dark:text-gray-200 whitespace-pre-wrap break-words">{{ $message->body_text }}</div>
    @else
        <div class="text-sm text-gray-400 italic">(no body)</div>
    @endif
</x-filament::section>
