@php
    $thread = $this->thread;
    $tz = auth()->user()?->displayTimezone() ?? config('app.timezone');
    $canReply = $this->canReply();
    $suppressed = $this->isSuppressed();
@endphp

<x-filament-panels::page>
    {{--
        The opt-out banner sits above everything, including the
        transcript. An operator scanning for the reply box needs to
        learn there isn't one before they start composing, not after.
    --}}
    @if ($suppressed)
        <div style="
            display: flex;
            gap: 0.625rem;
            align-items: flex-start;
            border: 1px solid var(--danger-400);
            background: var(--danger-50, rgba(239,68,68,0.08));
            border-radius: 0.5rem;
            padding: 0.75rem 0.875rem;
        ">
            <x-filament::icon
                icon="heroicon-o-no-symbol"
                style="width: 1.25rem; height: 1.25rem; flex-shrink: 0; color: var(--danger-600);"
            />
            <div style="font-size: 0.875rem; line-height: 1.5;">
                <div style="font-weight: 600;">This person has opted out of text messages</div>
                <div style="color: var(--gray-600);">
                    They asked this client to stop texting them, so replies are blocked on
                    this conversation. Only they can undo it, by texting START.
                    Reach them another way if this needs a response.
                </div>
            </div>
        </div>
    @endif
    {{--
        Transcript. Inbound sits left, outbound right — the layout every
        messaging client uses, so an operator can tell at a glance who
        said what without reading labels.
    --}}
    <x-filament::section
        heading="Conversation"
        icon="heroicon-o-chat-bubble-left-right"
        wire:poll.15s="refreshThread"
    >
        <div style="display: flex; flex-direction: column; gap: 0.75rem; max-height: 32rem; overflow-y: auto; padding: 0.25rem;">
            @forelse ($thread->entries as $entry)
                @php
                    $inbound = $entry->isInbound();
                    $failed = $entry->failedToDeliver();
                @endphp

                <div style="display: flex; justify-content: {{ $inbound ? 'flex-start' : 'flex-end' }};">
                    <div style="max-width: 34rem; min-width: 8rem;">
                        <div style="
                            border-radius: 0.75rem;
                            padding: 0.625rem 0.875rem;
                            font-size: 0.875rem;
                            line-height: 1.5;
                            white-space: pre-wrap;
                            word-break: break-word;
                            background: {{ $inbound ? 'rgba(128,128,128,0.12)' : 'var(--primary-50, rgba(59,130,246,0.12))' }};
                            border: 1px solid {{ $failed ? 'var(--danger-400)' : 'transparent' }};
                        ">
                            {{ $entry->body ?: '(no text)' }}

                            @if ($entry->media)
                                {{--
                                    mediaItems() resolves to our own
                                    stored copy where FetchMessageMediaJob
                                    has run, and falls back to the
                                    carrier's URL where it hasn't. The
                                    carrier's URL expires, so a link that
                                    isn't `stored` is one that will stop
                                    working — say so rather than letting
                                    an operator find out by clicking it
                                    next month.
                                --}}
                                <div style="margin-top: 0.5rem; display: flex; flex-direction: column; gap: 0.375rem;">
                                    @foreach ($entry->mediaItems() as $item)
                                        @php
                                            $isImage = str_starts_with((string) $item['content_type'], 'image/')
                                                && $item['content_type'] !== 'image/svg+xml';
                                        @endphp

                                        @if ($item['url'] === null)
                                            <span style="font-size: 0.75rem; color: var(--gray-500);">
                                                Attachment unavailable
                                            </span>
                                        @elseif ($isImage)
                                            <a href="{{ $item['url'] }}" target="_blank" rel="noopener">
                                                <img
                                                    src="{{ $item['url'] }}"
                                                    alt="{{ $item['filename'] ?: 'Attachment' }}"
                                                    loading="lazy"
                                                    style="max-width: 100%; max-height: 16rem; border-radius: 0.5rem; display: block;"
                                                />
                                            </a>
                                        @else
                                            <a href="{{ $item['url'] }}" target="_blank" rel="noopener"
                                               style="font-size: 0.75rem; text-decoration: underline;">
                                                {{ $item['filename'] ?: 'Attachment' }}{{ $item['content_type'] ? ' ('.$item['content_type'].')' : '' }}
                                            </a>
                                        @endif

                                        @if ($item['url'] !== null && ! $item['stored'])
                                            <span style="font-size: 0.6875rem; color: var(--gray-500);">
                                                Still on the carrier — this link will expire.
                                            </span>
                                        @endif
                                    @endforeach
                                </div>
                            @endif
                        </div>

                        <div style="
                            margin-top: 0.25rem;
                            font-size: 0.6875rem;
                            color: var(--gray-500);
                            text-align: {{ $inbound ? 'left' : 'right' }};
                            display: flex;
                            gap: 0.375rem;
                            justify-content: {{ $inbound ? 'flex-start' : 'flex-end' }};
                            align-items: center;
                        ">
                            <span>{{ $entry->authorLabel() }}</span>
                            <span aria-hidden="true">&middot;</span>
                            <span>{{ $entry->occurred_at?->timezone($tz)->format('M j, g:i a') }}</span>

                            @unless ($inbound)
                                <span aria-hidden="true">&middot;</span>
                                {{--
                                    Delivery state is shown on every
                                    outbound message, not just failures.
                                    "Sent" and "delivered" are different
                                    facts, and an operator chasing a
                                    silent customer needs to know which
                                    one they're looking at.
                                --}}
                                <x-filament::badge
                                    size="xs"
                                    :color="match ($entry->delivery_status) {
                                        'delivered' => 'success',
                                        'undelivered', 'failed' => 'danger',
                                        'sent' => 'gray',
                                        default => 'warning',
                                    }"
                                >
                                    {{ $entry->delivery_status }}
                                </x-filament::badge>
                            @endunless
                        </div>

                        @if ($failed && $entry->delivery_error)
                            <div style="margin-top: 0.25rem; font-size: 0.6875rem; color: var(--danger-500); text-align: right;">
                                {{ $entry->delivery_error }}
                            </div>
                        @endif
                    </div>
                </div>
            @empty
                <div style="text-align: center; padding: 2rem 0; font-size: 0.875rem; color: var(--gray-500);">
                    No messages in this conversation yet.
                </div>
            @endforelse
        </div>
    </x-filament::section>

    {{-- Reply box. Inline, not a modal — texting is a fast back-and-forth. --}}
    <x-filament::section heading="Reply" icon="heroicon-o-paper-airplane">
        @if (! $canReply)
            <div style="font-size: 0.875rem; color: var(--gray-500);">
                @if ($suppressed)
                    Replies are blocked — this person opted out of text messages from this
                    client. Only they can undo it, by texting START.
                @elseif ($thread->assigned_operator_id)
                    Claimed by {{ $thread->assignedOperator?->name }}. Only the operator handling
                    a conversation can reply — two people answering the same customer independently
                    is worse than a slow reply.
                @else
                    Claim this conversation to reply.
                @endif
            </div>
        @else
            <form wire:submit="sendReply">
                <div style="display: flex; flex-direction: column; gap: 0.75rem;">
                    <x-filament::input.wrapper>
                        <textarea
                            wire:model="replyBody"
                            rows="3"
                            maxlength="480"
                            placeholder="Type a reply. Keep it short — this is billed and read in segments."
                            style="width: 100%; box-sizing: border-box; resize: vertical; border: none; background: transparent; padding: 0.5rem 0.75rem; font-size: 0.875rem; font-family: inherit; color: inherit; outline: none;"
                        ></textarea>
                    </x-filament::input.wrapper>

                    <div style="display: flex; justify-content: space-between; align-items: center; gap: 0.5rem;">
                        {{--
                            Segment count, not just a character count.
                            160 characters is one segment; 161 is two,
                            and the client pays for both.
                        --}}
                        <span style="font-size: 0.75rem; color: var(--gray-500);">
                            {{ strlen($this->replyBody) }} characters ·
                            {{ max(1, (int) ceil(max(1, strlen($this->replyBody)) / 160)) }} segment(s)
                        </span>

                        <x-filament::button type="submit" icon="heroicon-o-paper-airplane">
                            Send
                        </x-filament::button>
                    </div>
                </div>
            </form>
        @endif
    </x-filament::section>
</x-filament-panels::page>
