<div style="display: flex; flex-direction: column; gap: 0; max-height: 70vh; overflow-y: auto;">

    @php
        $operatorTz = auth()->user()?->displayTimezone() ?? config('app.timezone');
        $tenantTz = $thread->team?->displayTimezone() ?? config('app.timezone');
        $showBothTz = $operatorTz !== $tenantTz;
    @endphp

    {{-- Thread meta --}}
    <div class="text-xs text-gray-500 dark:text-gray-400" style="display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap; padding-bottom: 0.75rem;">
        <span>Tenant: <strong class="text-gray-700 dark:text-gray-200">{{ $thread->team->name ?? 'Unknown' }}</strong></span>
        <span>&middot;</span>
        <span>{{ $thread->messages->count() }} message(s)</span>
        @if ($thread->emailQueue)
            <span>&middot;</span>
            <span>Queue: <strong class="text-gray-700 dark:text-gray-200">{{ $thread->emailQueue->name }}</strong></span>
        @endif
        @if ($showBothTz)
            <span>&middot;</span>
            <span>Tenant tz: <strong class="text-gray-700 dark:text-gray-200">{{ $tenantTz }}</strong></span>
        @endif
    </div>

    @foreach ($thread->messages as $msg)
        @php
            $isOutbound = $msg->direction === 'outbound';
        @endphp
        <div style="padding: 0.875rem 0; {{ ! $loop->first ? 'border-top: 1px solid rgba(128,128,128,0.15);' : '' }}">

            {{-- Header: sender + timestamp --}}
            <div style="display: flex; justify-content: space-between; align-items: baseline; gap: 0.75rem; margin-bottom: 0.5rem;">
                <div style="display: flex; align-items: baseline; gap: 0.5rem;">
                    <strong class="text-sm text-gray-900 dark:text-gray-100">{{ $msg->from_name ?: $msg->from_address }}</strong>
                    @if ($msg->from_name)
                        <span class="text-sm text-gray-500 dark:text-gray-400">&lt;{{ $msg->from_address }}&gt;</span>
                    @endif
                    @if ($isOutbound)
                        <x-filament::badge color="info" size="sm">sent</x-filament::badge>
                    @endif
                </div>
                <div class="text-xs text-gray-500 dark:text-gray-400" style="white-space: nowrap; display: flex; align-items: center; gap: 0.5rem;">
                    <span title="{{ $operatorTz }}">{{ $msg->received_at?->timezone($operatorTz)->format('M j, g:i a T') }}</span>
                    @if ($showBothTz)
                        <span title="Tenant timezone ({{ $tenantTz }})">/ {{ $msg->received_at?->timezone($tenantTz)->format('g:i a T') }}</span>
                    @endif
                    @if (auth()->user()?->isSuperAdmin() && $msg->direction === 'inbound')
                        <a
                            href="{{ route('mail.messages.raw', $msg) }}"
                            target="_blank"
                            rel="noopener"
                            title="View raw RFC822 (super admin)"
                            class="text-gray-500 dark:text-gray-400"
                            style="text-decoration: underline; font-size: 0.6875rem;"
                        >raw</a>
                    @endif
                </div>
            </div>

            {{-- Subject --}}
            <div class="text-gray-700 dark:text-gray-300" style="font-size: 0.8125rem; margin-bottom: 0.375rem;">
                <strong style="font-weight: 500;">Subject:</strong> {{ $msg->subject ?: '(none)' }}
            </div>

            {{-- Recipients --}}
            @if (! empty($msg->to_addresses))
                <div class="text-xs text-gray-500 dark:text-gray-400" style="margin-bottom: 0.375rem;">
                    To: {{ collect($msg->to_addresses)->map(fn ($a) => is_array($a) ? ($a['address'] ?? '') : $a)->filter()->join(', ') }}
                </div>
            @endif

            {{-- Body --}}
            @if ($msg->body_html)
                <div
                    x-data="{ mode: 'html' }"
                    style="margin-top: 0.5rem;"
                >
                    <div style="display: flex; gap: 0.375rem; margin-bottom: 0.375rem;">
                        <button
                            type="button"
                            @click="mode = 'html'"
                            :class="mode === 'html' ? 'text-primary-600 dark:text-primary-400 font-semibold' : 'text-gray-500 dark:text-gray-400'"
                            style="background: none; border: 0; padding: 0 0.25rem; cursor: pointer; font-size: 0.75rem;"
                        >HTML</button>
                        <button
                            type="button"
                            @click="mode = 'text'"
                            :class="mode === 'text' ? 'text-primary-600 dark:text-primary-400 font-semibold' : 'text-gray-500 dark:text-gray-400'"
                            style="background: none; border: 0; padding: 0 0.25rem; cursor: pointer; font-size: 0.75rem;"
                        >Plain</button>
                    </div>

                    <iframe
                        x-show="mode === 'html'"
                        srcdoc="{{ e($msg->body_html) }}"
                        sandbox="allow-popups"
                        referrerpolicy="no-referrer"
                        style="width: 100%; min-height: 200px; max-height: 50vh; border: 1px solid rgba(128,128,128,0.15); border-radius: 0.375rem; background: white;"
                    ></iframe>

                    <pre
                        x-show="mode === 'text'"
                        x-cloak
                        class="text-gray-800 dark:text-gray-200"
                        style="margin: 0; padding: 0.75rem; border: 1px solid rgba(128,128,128,0.15); border-radius: 0.375rem; font-family: inherit; font-size: 0.875rem; line-height: 1.4; white-space: pre-wrap; word-break: break-word; overflow-wrap: anywhere; max-height: 50vh; overflow-y: auto;"
                    >{{ $msg->body_text ?: '(no plain-text body)' }}</pre>
                </div>
            @else
                {{-- Plain text only (no HTML body) --}}
                <pre
                    class="text-gray-800 dark:text-gray-200"
                    style="margin: 0.5rem 0 0 0; padding: 0.75rem; border: 1px solid rgba(128,128,128,0.15); border-radius: 0.375rem; font-family: inherit; font-size: 0.875rem; line-height: 1.4; white-space: pre-wrap; word-break: break-word; overflow-wrap: anywhere;"
                >{{ $msg->body_text ?: '(no body)' }}</pre>
            @endif

            {{-- Attachments --}}
            @if ($msg->attachments->isNotEmpty())
                <div class="text-xs text-gray-500 dark:text-gray-400" style="margin-top: 0.75rem;">
                    Attachments:
                    <ul style="margin: 0.25rem 0 0 0; padding: 0; list-style: none; display: flex; flex-direction: column; gap: 0.25rem;">
                        @foreach ($msg->attachments as $att)
                            <li style="display: flex; align-items: center; gap: 0.5rem;">
                                <a
                                    href="{{ route('mail.attachments.download', $att) }}"
                                    class="text-primary-600 dark:text-primary-400"
                                    style="font-weight: 500; text-decoration: underline;"
                                    download="{{ $att->filename }}"
                                >
                                    {{ $att->filename }}
                                </a>
                                <span>
                                    {{ number_format($att->size_bytes / 1024, 1) }} KB
                                    @if ($att->content_type)
                                        &middot; {{ $att->content_type }}
                                    @endif
                                </span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>
    @endforeach

    {{-- Activity history --}}
    @if ($thread->activities->isNotEmpty())
        <x-filament::section compact>
            <x-slot name="heading">
                <span style="font-size: 0.6875rem; text-transform: uppercase; letter-spacing: 0.05em;">Activity</span>
            </x-slot>
            <div class="fi-ta" style="overflow-x: auto;">
                <table class="fi-ta-table w-full table-auto divide-y divide-gray-200 dark:divide-white/5 text-start" style="font-size: 0.6875rem;">
                    <thead>
                        <tr>
                            <th class="fi-ta-header-cell px-3 py-1.5 text-start text-xs font-medium text-gray-500 dark:text-gray-400">Action</th>
                            <th class="fi-ta-header-cell px-3 py-1.5 text-start text-xs font-medium text-gray-500 dark:text-gray-400">Time</th>
                            <th class="fi-ta-header-cell px-3 py-1.5 text-start text-xs font-medium text-gray-500 dark:text-gray-400">By</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-white/5">
                        @foreach ($thread->activities as $activity)
                            <tr>
                                <td class="fi-ta-cell px-3 py-1.5 text-gray-500 dark:text-gray-400" style="white-space: nowrap;">
                                    @switch($activity->action)
                                        @case('claimed')
                                            Claimed
                                            @break
                                        @case('replied')
                                            Replied to {{ collect($activity->metadata['to'] ?? [])->map(fn ($a) => is_array($a) ? ($a['address'] ?? '') : $a)->filter()->join(', ') }}
                                            @break
                                        @case('forwarded')
                                            Forwarded to {{ $activity->metadata['to'] ?? 'unknown' }}
                                            @break
                                        @case('closed')
                                            Closed
                                            @break
                                        @case('reopened')
                                            Reopened
                                            @break
                                        @default
                                            {{ ucfirst($activity->action) }}
                                    @endswitch
                                </td>
                                <td class="fi-ta-cell px-3 py-1.5 text-gray-500 dark:text-gray-400" style="white-space: nowrap;" title="{{ $activity->created_at->timezone($operatorTz)->format('M j, Y g:i:s a T') }}">
                                    {{ $activity->created_at->timezone($operatorTz)->format('g:i a') }}
                                </td>
                                <td class="fi-ta-cell px-3 py-1.5 text-gray-700 dark:text-gray-300" style="font-weight: 500;">
                                    {{ $activity->user?->name ?? 'System' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    @endif
</div>
