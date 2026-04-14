<div class="flex min-h-[60vh] flex-col">
    {{-- Message history --}}
    <div class="flex-1 space-y-4 pb-6">
        @if (empty($messages))
            <div class="rounded-lg border border-white/10 bg-white/5 p-4 text-sm text-gray-400">
                Say hello to start the conversation.
            </div>
        @endif

        @foreach ($messages as $message)
            @php
                $isUser = ($message['role'] ?? '') === 'user';
                $alignment = $isUser ? 'justify-end' : 'justify-start';
                $bubble = $isUser
                    ? 'bg-indigo-600 text-white'
                    : 'bg-white/5 text-gray-100 border border-white/10';
            @endphp
            <div class="flex {{ $alignment }}">
                <div class="max-w-[80%] rounded-2xl px-4 py-2.5 text-sm {{ $bubble }}">
                    <div class="whitespace-pre-wrap">{{ $message['content'] ?? '' }}</div>
                </div>
            </div>
        @endforeach

        @if ($pending)
            <div class="flex justify-start">
                <div class="max-w-[80%] rounded-2xl border border-white/10 bg-white/5 px-4 py-2.5 text-sm text-gray-400">
                    <span class="inline-flex items-center gap-1">
                        <span class="h-1.5 w-1.5 animate-pulse rounded-full bg-gray-400"></span>
                        <span class="h-1.5 w-1.5 animate-pulse rounded-full bg-gray-400" style="animation-delay: 150ms"></span>
                        <span class="h-1.5 w-1.5 animate-pulse rounded-full bg-gray-400" style="animation-delay: 300ms"></span>
                    </span>
                </div>
            </div>
        @endif

        @if ($errorMessage)
            <div class="rounded-lg border border-red-500/40 bg-red-500/10 p-3 text-xs text-red-300">
                {{ $errorMessage }}
            </div>
        @endif
    </div>

    {{-- Composer --}}
    <form wire:submit="send" class="sticky bottom-0 border-t border-white/10 bg-gray-950 pt-4">
        <div class="flex items-end gap-2">
            <textarea
                wire:model="draft"
                rows="2"
                placeholder="Type your message…"
                class="flex-1 resize-none rounded-xl border border-white/10 bg-black/30 px-4 py-2.5 text-sm text-white placeholder-gray-500 focus:border-indigo-500 focus:outline-none"
                wire:keydown.enter.prevent="send"
                @disabled($pending)
            ></textarea>
            <button
                type="submit"
                @disabled($pending || trim($draft) === '')
                class="rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-indigo-500 disabled:opacity-40"
            >
                Send
            </button>
        </div>
    </form>
</div>
