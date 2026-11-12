@php
    /** @var \App\Models\CallLog $record */
    // One "Speaker: text" line per turn, as the agent worker sends it.
    $lines = collect(preg_split('/\R/', (string) $record->transcript))
        ->map(fn ($line) => trim($line))
        ->filter()
        ->map(function ($line) {
            [$speaker, $text] = str_contains($line, ': ') ? explode(': ', $line, 2) : ['', $line];

            return ['speaker' => $speaker, 'text' => $text];
        });
@endphp
<div class="space-y-3 text-sm">
    @forelse ($lines as $line)
        <div>
            @if ($line['speaker'] !== '')
                <span @class([
                    'font-semibold',
                    'text-primary-600 dark:text-primary-400' => $line['speaker'] === 'Agent',
                ])>{{ $line['speaker'] }}:</span>
            @endif
            <span class="text-gray-700 dark:text-gray-200">{{ $line['text'] }}</span>
        </div>
    @empty
        <p class="text-gray-500">No transcript.</p>
    @endforelse
</div>
