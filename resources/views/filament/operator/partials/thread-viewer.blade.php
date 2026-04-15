<div style="display: flex; flex-direction: column; gap: 1rem; max-height: 70vh; overflow-y: auto;">
    <div style="font-size: 0.75rem; color: rgb(107 114 128); display: flex; gap: 0.5rem; align-items: center;">
        <span>Tenant: <strong>{{ $thread->team->name ?? 'Unknown' }}</strong></span>
        <span>·</span>
        <span>{{ $thread->messages->count() }} message(s)</span>
        @if ($thread->emailQueue)
            <span>·</span>
            <span>Queue: <strong>{{ $thread->emailQueue->name }}</strong></span>
        @endif
    </div>

    @foreach ($thread->messages as $msg)
        <div
            style="
                border: 1px solid {{ $msg->direction === 'outbound' ? 'rgb(191 219 254)' : 'rgb(229 231 235)' }};
                background: {{ $msg->direction === 'outbound' ? 'rgb(239 246 255)' : 'rgb(249 250 251)' }};
                border-radius: 0.5rem;
                padding: 0.875rem 1rem;
            "
        >
            <div style="display: flex; justify-content: space-between; align-items: baseline; gap: 0.75rem; margin-bottom: 0.5rem;">
                <div style="font-size: 0.875rem;">
                    <strong>{{ $msg->from_name ?: $msg->from_address }}</strong>
                    @if ($msg->from_name)
                        <span style="color: rgb(107 114 128); font-weight: normal;">&lt;{{ $msg->from_address }}&gt;</span>
                    @endif
                </div>
                <div style="font-size: 0.75rem; color: rgb(107 114 128); white-space: nowrap; display: flex; align-items: center; gap: 0.5rem;">
                    @php
                        $tz = auth()->user()?->displayTimezone() ?? config('app.timezone');
                    @endphp
                    <span>
                        {{ $msg->received_at?->timezone($tz)->format('M j, g:i a') }}
                        @if ($msg->direction === 'outbound')
                            · sent
                        @endif
                    </span>
                    @if (auth()->user()?->isSuperAdmin() && $msg->direction === 'inbound')
                        <a
                            href="{{ route('mail.messages.raw', $msg) }}"
                            target="_blank"
                            rel="noopener"
                            title="View raw RFC822 (super admin)"
                            style="color: rgb(107 114 128); text-decoration: underline; font-size: 0.6875rem;"
                        >raw</a>
                    @endif
                </div>
            </div>

            <div style="font-size: 0.8125rem; color: rgb(75 85 99); margin-bottom: 0.375rem;">
                <strong style="font-weight: 500;">Subject:</strong> {{ $msg->subject ?: '(none)' }}
            </div>

            @if (! empty($msg->to_addresses))
                <div style="font-size: 0.75rem; color: rgb(107 114 128); margin-bottom: 0.375rem;">
                    To: {{ collect($msg->to_addresses)->map(fn ($a) => is_array($a) ? ($a['address'] ?? '') : $a)->filter()->join(', ') }}
                </div>
            @endif

            @if ($msg->body_html)
                {{-- HTML bodies render inside a sandboxed iframe
                     with srcdoc. `sandbox` without `allow-scripts`
                     kills JavaScript inside the message; no `allow-
                     same-origin` means injected fetches to external
                     trackers can't run either. `allow-popups` gates
                     links to open in a new tab but only on click —
                     no spontaneous nav. Result: messages render
                     with their intended styling but can't read the
                     operator's session or make outbound requests. --}}
                <div
                    x-data="{ mode: 'html' }"
                    style="margin-top: 0.5rem;"
                >
                    <div style="display: flex; gap: 0.375rem; margin-bottom: 0.375rem; font-size: 0.75rem;">
                        <button
                            type="button"
                            @click="mode = 'html'"
                            :style="mode === 'html' ? 'font-weight: 600; color: var(--primary-600);' : 'color: rgb(107 114 128);'"
                            style="background: none; border: 0; padding: 0 0.25rem; cursor: pointer;"
                        >HTML</button>
                        <button
                            type="button"
                            @click="mode = 'text'"
                            :style="mode === 'text' ? 'font-weight: 600; color: var(--primary-600);' : 'color: rgb(107 114 128);'"
                            style="background: none; border: 0; padding: 0 0.25rem; cursor: pointer;"
                        >Plain</button>
                    </div>

                    <iframe
                        x-show="mode === 'html'"
                        srcdoc="{{ $msg->body_html }}"
                        sandbox="allow-popups"
                        referrerpolicy="no-referrer"
                        style="
                            width: 100%;
                            min-height: 200px;
                            max-height: 50vh;
                            border: 1px solid rgb(229 231 235);
                            border-radius: 0.375rem;
                            background: white;
                        "
                    ></iframe>

                    <pre
                        x-show="mode === 'text'"
                        x-cloak
                        style="
                            margin: 0;
                            padding: 0.75rem;
                            background: white;
                            border: 1px solid rgb(229 231 235);
                            border-radius: 0.375rem;
                            font-family: inherit;
                            font-size: 0.875rem;
                            line-height: 1.4;
                            white-space: pre-wrap;
                            word-break: break-word;
                            overflow-wrap: anywhere;
                            max-height: 50vh;
                            overflow-y: auto;
                        "
                    >{{ $msg->body_text ?: '(no plain-text body)' }}</pre>
                </div>
            @else
                <pre
                    style="
                        margin: 0.5rem 0 0 0;
                        padding: 0.75rem;
                        background: white;
                        border: 1px solid rgb(229 231 235);
                        border-radius: 0.375rem;
                        font-family: inherit;
                        font-size: 0.875rem;
                        line-height: 1.4;
                        white-space: pre-wrap;
                        word-break: break-word;
                        overflow-wrap: anywhere;
                    "
                >{{ $msg->body_text ?: '(no body)' }}</pre>
            @endif

            @if ($msg->attachments->isNotEmpty())
                <div style="margin-top: 0.75rem; font-size: 0.75rem; color: rgb(107 114 128);">
                    Attachments:
                    <ul style="margin: 0.25rem 0 0 0; padding: 0; list-style: none; display: flex; flex-direction: column; gap: 0.25rem;">
                        @foreach ($msg->attachments as $att)
                            <li style="display: flex; align-items: center; gap: 0.5rem;">
                                <a
                                    href="{{ route('mail.attachments.download', $att) }}"
                                    style="color: var(--primary-600); font-weight: 500; text-decoration: underline;"
                                    download="{{ $att->filename }}"
                                >
                                    {{ $att->filename }}
                                </a>
                                <span style="color: rgb(107 114 128);">
                                    {{ number_format($att->size_bytes / 1024, 1) }} KB
                                    @if ($att->content_type)
                                        · {{ $att->content_type }}
                                    @endif
                                </span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>
    @endforeach
</div>
