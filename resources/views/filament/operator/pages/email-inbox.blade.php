<x-filament-panels::page>
    @php
        $isUnavailable = ! auth()->user()?->isAvailableForNonVoice();
    @endphp

    <div @class(['fi-inbox-shimmer' => $isUnavailable])>
        {{ $this->table }}
    </div>

    @if ($isUnavailable)
        <style>
            .fi-inbox-shimmer .fi-ta-cell > * {
                visibility: hidden;
                position: relative;
            }
            .fi-inbox-shimmer .fi-ta-cell {
                position: relative;
            }
            .fi-inbox-shimmer .fi-ta-cell::after {
                content: '';
                position: absolute;
                inset: 50% 0.75rem auto 0.75rem;
                height: 0.75rem;
                transform: translateY(-50%);
                border-radius: 0.25rem;
                background: linear-gradient(
                    90deg,
                    rgb(229 231 235 / 0.6) 25%,
                    rgb(229 231 235 / 0.3) 50%,
                    rgb(229 231 235 / 0.6) 75%
                );
                background-size: 200% 100%;
                animation: fi-shimmer 8s ease-in-out infinite;
            }
            .dark .fi-inbox-shimmer .fi-ta-cell::after {
                background: linear-gradient(
                    90deg,
                    rgb(255 255 255 / 0.08) 25%,
                    rgb(255 255 255 / 0.04) 50%,
                    rgb(255 255 255 / 0.08) 75%
                );
                background-size: 200% 100%;
            }
            .fi-inbox-shimmer .fi-ta-cell:nth-child(odd)::after { width: 60%; }
            .fi-inbox-shimmer .fi-ta-cell:nth-child(even)::after { width: 45%; }
            .fi-inbox-shimmer .fi-ta-row { pointer-events: none; }
            .fi-inbox-shimmer .fi-ta-actions-cell { visibility: hidden; }
            @@keyframes fi-shimmer {
                0% { background-position: 200% 0; }
                100% { background-position: -200% 0; }
            }
            @@media (prefers-reduced-motion: reduce) {
                .fi-inbox-shimmer .fi-ta-cell::after {
                    animation: none;
                }
            }
        </style>
    @endif
</x-filament-panels::page>
