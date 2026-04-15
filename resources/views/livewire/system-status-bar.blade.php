{{-- Poll is a degraded-mode fallback — the primary update path
     is the Reverb `system-health-updated` broadcast that fires on
     every health re-probe and ack/clear. Kept long (60s) because
     Reverb handles the interactive case; this just catches websocket
     drops (laptop sleep, wifi hiccup) so the bar reconverges on its
     own without a manual refresh. --}}
<div
    class="orbital-status-bar {{ $cls }}"
    wire:poll.60s="load"
    title="{{ $title }}"
    aria-label="{{ $title }}"
></div>
