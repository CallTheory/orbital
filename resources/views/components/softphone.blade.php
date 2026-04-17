{{--
    Persistent softphone widget injected via PanelsRenderHook::BODY_END
    on the operator panel. Uses scoped `.osp-*` CSS that works without
    a custom Filament panel theme.
--}}
<style>
    [x-cloak] { display: none !important; }

    .osp-root {
        position: fixed;
        bottom: 1rem;
        right: 1rem;
        z-index: 50;
        font-family: inherit;
        font-size: 14px;
    }

    .osp-fade-in {
        animation: osp-fade-in 150ms ease-out;
    }
    .osp-fade-out {
        animation: osp-fade-out 100ms ease-in;
    }
    @keyframes osp-fade-in {
        from { opacity: 0; transform: scale(0.95) translateY(4px); }
        to   { opacity: 1; transform: scale(1) translateY(0); }
    }
    @keyframes osp-fade-out {
        from { opacity: 1; transform: scale(1) translateY(0); }
        to   { opacity: 0; transform: scale(0.95) translateY(4px); }
    }

    /* ── Collapsed pill ──────────────────────────────────────────── */
    .osp-pill {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.5rem 1rem;
        background: white;
        color: var(--gray-900);
        border: 1px solid var(--gray-200);
        border-radius: 9999px;
        box-shadow: 0 10px 15px -3px rgb(0 0 0 / 0.08), 0 4px 6px -4px rgb(0 0 0 / 0.08);
        cursor: pointer;
        transition: border-color 120ms, background-color 120ms;
        font-family: inherit;
    }
    .osp-pill:hover { border-color: var(--gray-300); }
    .dark .osp-pill {
        background: var(--gray-900);
        color: var(--gray-100);
        border-color: var(--gray-800);
    }
    .dark .osp-pill:hover { border-color: var(--gray-700); }

    .osp-pill-status {
        width: 0.625rem;
        height: 0.625rem;
        border-radius: 9999px;
        background: var(--gray-400);
    }
    .osp-pill-status.is-idle       { background: var(--success-500); }
    .osp-pill-status.is-connecting,
    .osp-pill-status.is-ringing    { background: var(--warning-500); animation: osp-pulse 1.5s infinite; }
    .osp-pill-status.is-in-call    { background: var(--primary-500); animation: osp-pulse 1.5s infinite; }
    .osp-pill-status.is-incoming   { background: var(--danger-500);  animation: osp-pulse 1s infinite; }
    .osp-pill-status.is-error      { background: var(--gray-400); }
    @keyframes osp-pulse {
        0%, 100% { opacity: 1; }
        50%      { opacity: 0.45; }
    }
    .osp-pill-label { font-size: 0.875rem; font-weight: 500; }
    .osp-pill-label.danger { color: var(--danger-600); }
    .dark .osp-pill-label.danger { color: var(--danger-400); }

    /* ── Expanded card ───────────────────────────────────────────── */
    .osp-card {
        width: 18rem;
        background: white;
        color: var(--gray-900);
        border: 1px solid var(--gray-200);
        border-radius: 0.75rem;
        box-shadow: 0 25px 50px -12px rgb(0 0 0 / 0.15);
        overflow: hidden;
    }
    .dark .osp-card {
        background: var(--gray-900);
        color: var(--gray-100);
        border-color: var(--gray-800);
    }

    .osp-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 0.75rem 1rem;
        border-bottom: 1px solid var(--gray-200);
    }
    .dark .osp-header { border-bottom-color: var(--gray-800); }

    .osp-header-title {
        font-size: 0.875rem;
        font-weight: 600;
        color: var(--gray-900);
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    .dark .osp-header-title { color: var(--gray-100); }

    .osp-header-close {
        background: none;
        border: 0;
        color: var(--gray-500);
        cursor: pointer;
        padding: 0;
        display: inline-flex;
        align-items: center;
        justify-content: center;
    }
    .osp-header-close:hover { color: var(--gray-900); }
    .dark .osp-header-close:hover { color: var(--gray-100); }

    .osp-body {
        padding: 1rem;
        display: flex;
        flex-direction: column;
        gap: 0.75rem;
    }
    .osp-stack    { display: flex; flex-direction: column; gap: 0.5rem; }
    .osp-stack-lg { display: flex; flex-direction: column; gap: 0.75rem; }
    .osp-center   { text-align: center; }
    .osp-caller-num {
        font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
        font-size: 1.125rem;
        color: var(--gray-900);
    }
    .dark .osp-caller-num { color: var(--gray-100); }

    .osp-muted { font-size: 0.875rem; color: var(--gray-500); }
    .osp-error { font-size: 0.75rem;  color: var(--danger-600); }
    .dark .osp-error { color: var(--danger-400); }

    /* ── Dial input wrapper ──────────────────────────────────────── */
    .osp-dial-wrap {
        display: flex;
        gap: 0.25rem;
        align-items: center;
    }
    .osp-dial-input {
        flex: 1;
        box-sizing: border-box;
        padding: 0.625rem 0.75rem;
        background: var(--gray-50);
        color: var(--gray-900);
        font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
        font-size: 1.125rem;
        text-align: center;
        border: 1px solid var(--gray-200);
        border-radius: 0.5rem;
        outline: none;
        transition: border-color 120ms, box-shadow 120ms;
    }
    .osp-dial-input::placeholder { color: var(--gray-400); }
    .osp-dial-input:focus {
        border-color: var(--primary-500);
        box-shadow: 0 0 0 2px color-mix(in oklch, var(--primary-500), transparent 70%);
    }
    .dark .osp-dial-input {
        background: var(--gray-950);
        color: var(--gray-100);
        border-color: var(--gray-800);
    }
    .dark .osp-dial-input::placeholder { color: var(--gray-600); }

    .osp-clear-btn {
        background: none;
        border: 0;
        color: var(--gray-400);
        cursor: pointer;
        padding: 0.25rem;
        display: inline-flex;
        align-items: center;
        border-radius: 0.25rem;
    }
    .osp-clear-btn:hover { color: var(--gray-600); }
    .dark .osp-clear-btn:hover { color: var(--gray-300); }

    /* ── Dial pad ────────────────────────────────────────────────── */
    .osp-pad {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 0.375rem;
    }
    .osp-pad-key {
        padding: 0.75rem 0;
        background: var(--gray-50);
        color: var(--gray-900);
        font-size: 1rem;
        font-weight: 500;
        border: 1px solid var(--gray-200);
        border-radius: 0.5rem;
        cursor: pointer;
        transition: background-color 120ms, border-color 120ms;
        font-family: inherit;
    }
    .osp-pad-key:hover  { background: var(--gray-100); border-color: var(--gray-300); }
    .osp-pad-key:active { background: var(--gray-200); }
    .dark .osp-pad-key {
        background: var(--gray-950);
        color: var(--gray-100);
        border-color: var(--gray-800);
    }
    .dark .osp-pad-key:hover  { background: var(--gray-800); border-color: var(--gray-700); }
    .dark .osp-pad-key:active { background: var(--gray-700); }

    /* ── In-call controls ────────────────────────────────────────── */
    .osp-controls {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 0.5rem;
    }
    .osp-control-btn {
        padding: 0.5rem 0;
        background: var(--gray-50);
        color: var(--gray-600);
        font-size: 0.6875rem;
        font-weight: 500;
        border: 1px solid var(--gray-200);
        border-radius: 0.5rem;
        cursor: pointer;
        transition: color 120ms, background-color 120ms, border-color 120ms;
        font-family: inherit;
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 0.25rem;
    }
    .osp-control-btn:hover { color: var(--gray-900); border-color: var(--gray-300); }
    .dark .osp-control-btn {
        background: var(--gray-950);
        color: var(--gray-400);
        border-color: var(--gray-800);
    }
    .dark .osp-control-btn:hover { color: var(--gray-100); border-color: var(--gray-700); }

    .osp-control-btn.is-on.muted {
        background: color-mix(in oklch, var(--warning-500), transparent 85%);
        color: var(--warning-700);
        border-color: color-mix(in oklch, var(--warning-500), transparent 60%);
    }
    .dark .osp-control-btn.is-on.muted { color: var(--warning-400); }
    .osp-control-btn.is-on.held {
        background: color-mix(in oklch, var(--primary-500), transparent 85%);
        color: var(--primary-700);
        border-color: color-mix(in oklch, var(--primary-500), transparent 60%);
    }
    .dark .osp-control-btn.is-on.held { color: var(--primary-400); }

    .osp-control-icon { width: 1.25rem; height: 1.25rem; }

    /* ── Primary/danger action buttons ───────────────────────────── */
    .osp-btn {
        width: 100%;
        box-sizing: border-box;
        padding: 0.625rem 0.75rem;
        color: white;
        font-size: 0.875rem;
        font-weight: 600;
        border: 0;
        border-radius: 0.5rem;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.5rem;
        transition: background-color 120ms, opacity 120ms;
        font-family: inherit;
    }
    .osp-btn:disabled { opacity: 0.5; cursor: not-allowed; }
    .osp-btn-primary { background: var(--primary-600); }
    .osp-btn-primary:hover:not(:disabled) { background: var(--primary-700); }
    .osp-btn-success { background: var(--success-600); }
    .osp-btn-success:hover:not(:disabled) { background: var(--success-700); }
    .osp-btn-danger  { background: var(--danger-600); }
    .osp-btn-danger:hover:not(:disabled)  { background: var(--danger-700); }
    .osp-btn-gray    { background: var(--gray-500); }
    .osp-btn-gray:hover:not(:disabled)    { background: var(--gray-600); }

    .osp-row-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem; }

    /* ── In-call chip ────────────────────────────────────────────── */
    .osp-incall-chip {
        display: flex;
        align-items: center;
        justify-content: space-between;
        font-size: 0.875rem;
    }
    .osp-incall-left {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        color: var(--success-600);
    }
    .dark .osp-incall-left { color: var(--success-400); }
    .osp-incall-dot {
        width: 0.5rem;
        height: 0.5rem;
        background: var(--success-500);
        border-radius: 9999px;
        animation: osp-pulse 1.5s infinite;
    }
    .osp-incall-timer {
        font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
        font-size: 0.75rem;
        color: var(--gray-500);
    }

    /* ── Transfer mode ───────────────────────────────────────────── */
    .osp-transfer-label {
        font-size: 0.75rem;
        font-weight: 600;
        color: var(--primary-600);
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }
    .dark .osp-transfer-label { color: var(--primary-400); }
</style>

<div
    x-cloak
    class="osp-root"
    x-data="softphone"
    data-sip-config="{{ json_encode($sipConfig ?? (object) []) }}"
>
    {{-- Collapsed pill --}}
    <button
        type="button"
        class="osp-pill"
        x-show="!expanded"
        x-transition:enter="osp-fade-in"
        x-transition:leave="osp-fade-out"
        @click="expanded = true"
    >
        <span class="osp-pill-status" :class="statusClass()"></span>
        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 0 0 2.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 0 1-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 0 0-1.091-.852H4.5A2.25 2.25 0 0 0 2.25 4.5v2.25Z" />
        </svg>
        <span class="osp-pill-label" x-show="state === 'in-call'" x-text="'In call ' + formattedDuration()"></span>
        <span class="osp-pill-label danger" x-show="state === 'incoming'">Incoming</span>
    </button>

    {{-- Expanded card --}}
    <div class="osp-card" x-show="expanded"
        x-transition:enter="osp-fade-in"
        x-transition:leave="osp-fade-out"
    >
        <div class="osp-header">
            <span class="osp-header-title">
                <span class="osp-pill-status" :class="statusClass()" style="width: 0.5rem; height: 0.5rem;"></span>
                Phone
            </span>
            <button type="button" class="osp-header-close" @click="expanded = false" aria-label="Collapse">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
                </svg>
            </button>
        </div>

        <div class="osp-body">
            {{-- Incoming --}}
            <template x-if="state === 'incoming'">
                <div class="osp-stack-lg osp-center">
                    <p class="osp-muted">Incoming call from</p>
                    <p class="osp-caller-num" x-text="message || 'Unknown'"></p>
                    <div class="osp-row-2">
                        <button type="button" class="osp-btn osp-btn-success" @click="answer()">Answer</button>
                        <button type="button" class="osp-btn osp-btn-danger" @click="reject()">Reject</button>
                    </div>
                </div>
            </template>

            {{-- Dial input (not shown during incoming or transfer mode) --}}
            <template x-if="state !== 'incoming' && !transferMode">
                <div class="osp-dial-wrap">
                    <input
                        type="text"
                        class="osp-dial-input"
                        x-model="dialInput"
                        @keydown.enter="call()"
                        @keydown.backspace="backspaceDial()"
                        placeholder="Extension or number"
                    >
                    <button
                        type="button"
                        class="osp-clear-btn"
                        x-show="dialInput.length > 0"
                        @click="clearDial()"
                        title="Clear"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
            </template>

            {{-- Transfer input --}}
            <template x-if="transferMode">
                <div class="osp-stack">
                    <span class="osp-transfer-label">Transfer to</span>
                    <div class="osp-dial-wrap">
                        <input
                            type="text"
                            class="osp-dial-input osp-transfer-input"
                            x-model="transferInput"
                            @keydown.enter="executeTransfer()"
                            @keydown.escape="cancelTransfer()"
                            placeholder="Extension"
                            x-ref="transferInput"
                        >
                        <button type="button" class="osp-clear-btn" x-show="transferInput.length > 0" @click="transferInput = ''">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                </div>
            </template>

            {{-- Dial pad --}}
            <template x-if="state === 'idle' || state === 'in-call'">
                <div class="osp-pad">
                    <template x-for="key in ['1','2','3','4','5','6','7','8','9','*','0','#']" :key="key">
                        <button
                            type="button"
                            class="osp-pad-key"
                            @click="onPadKey(key)"
                            x-text="key"
                        ></button>
                    </template>
                </div>
            </template>

            {{-- Call button (idle / post-call states) --}}
            <template x-if="(state === 'idle' || state === 'ended' || state === 'error') && !transferMode">
                <button
                    type="button"
                    class="osp-btn osp-btn-primary"
                    :disabled="!dialInput"
                    @click="call()"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 0 0 2.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 0 1-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 0 0-1.091-.852H4.5A2.25 2.25 0 0 0 2.25 4.5v2.25Z" />
                    </svg>
                    Call
                </button>
            </template>

            {{-- Connecting / ringing --}}
            <template x-if="state === 'connecting' || state === 'ringing'">
                <div class="osp-stack osp-center">
                    <p class="osp-muted" x-text="state === 'connecting' ? 'Connecting…' : 'Ringing…'"></p>
                    <button type="button" class="osp-btn osp-btn-danger" @click="hangup()">Cancel</button>
                </div>
            </template>

            {{-- In call --}}
            <template x-if="state === 'in-call'">
                <div class="osp-stack">
                    <div class="osp-incall-chip">
                        <div class="osp-incall-left">
                            <span class="osp-incall-dot"></span>
                            In call
                        </div>
                        <span class="osp-incall-timer" x-text="formattedDuration()"></span>
                    </div>

                    <template x-if="!transferMode">
                        <div class="osp-stack">
                            <div class="osp-controls">
                                {{-- Mute --}}
                                <button
                                    type="button"
                                    class="osp-control-btn"
                                    :class="muted ? 'is-on muted' : ''"
                                    @click="toggleMute()"
                                    :title="muted ? 'Unmute' : 'Mute'"
                                >
                                    <template x-if="!muted">
                                        <svg class="osp-control-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 18.75a6 6 0 0 0 6-6v-1.5m-6 7.5a6 6 0 0 1-6-6v-1.5m6 7.5v3.75m-3.75 0h7.5M12 15.75a3 3 0 0 1-3-3V4.5a3 3 0 1 1 6 0v8.25a3 3 0 0 1-3 3Z" />
                                        </svg>
                                    </template>
                                    <template x-if="muted">
                                        <svg class="osp-control-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 18.75a6 6 0 0 0 6-6v-1.5m-6 7.5a6 6 0 0 1-6-6v-1.5m6 7.5v3.75m-3.75 0h7.5M12 15.75a3 3 0 0 1-3-3V4.5a3 3 0 1 1 6 0v8.25a3 3 0 0 1-3 3Z" />
                                            <line x1="3" y1="3" x2="21" y2="21" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" />
                                        </svg>
                                    </template>
                                    <span x-text="muted ? 'Unmute' : 'Mute'" style="font-size: 0.625rem;"></span>
                                </button>

                                {{-- Hold --}}
                                <button
                                    type="button"
                                    class="osp-control-btn"
                                    :class="held ? 'is-on held' : ''"
                                    @click="toggleHold()"
                                    :title="held ? 'Resume' : 'Hold'"
                                >
                                    <template x-if="!held">
                                        <svg class="osp-control-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M14.25 9v6m-4.5 0V9M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                                        </svg>
                                    </template>
                                    <template x-if="held">
                                        <svg class="osp-control-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0ZM15.91 11.672a.375.375 0 0 1 0 .656l-5.603 3.113a.375.375 0 0 1-.557-.328V8.887c0-.286.307-.466.557-.327l5.603 3.112Z" />
                                        </svg>
                                    </template>
                                    <span x-text="held ? 'Resume' : 'Hold'" style="font-size: 0.625rem;"></span>
                                </button>

                                {{-- Transfer --}}
                                <button
                                    type="button"
                                    class="osp-control-btn"
                                    @click="enterTransferMode()"
                                    title="Transfer"
                                >
                                    <svg class="osp-control-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M7.5 21 3 16.5m0 0L7.5 12M3 16.5h13.5m0-13.5L21 7.5m0 0L16.5 12M21 7.5H7.5" />
                                    </svg>
                                    <span style="font-size: 0.625rem;">Transfer</span>
                                </button>
                            </div>
                            <button type="button" class="osp-btn osp-btn-danger" @click="hangup()">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 3.75 18 6m0 0 2.25 2.25M18 6l2.25-2.25M18 6l-2.25 2.25m1.5 13.5c-8.284 0-15-6.716-15-15V4.5A2.25 2.25 0 0 1 4.5 2.25h1.372c.516 0 .966.351 1.091.852l1.106 4.423c.11.44-.055.902-.417 1.173l-1.293.97a1.062 1.062 0 0 0-.38 1.21 12.035 12.035 0 0 0 7.143 7.143c.441.162.928-.004 1.21-.38l.97-1.293a1.125 1.125 0 0 1 1.173-.417l4.423 1.106c.5.125.852.575.852 1.091V19.5a2.25 2.25 0 0 1-2.25 2.25h-2.25Z" />
                                </svg>
                                Hang up
                            </button>
                        </div>
                    </template>

                    {{-- Transfer mode buttons --}}
                    <template x-if="transferMode">
                        <div class="osp-row-2">
                            <button type="button" class="osp-btn osp-btn-primary" :disabled="!transferInput" @click="executeTransfer()">
                                Transfer
                            </button>
                            <button type="button" class="osp-btn osp-btn-gray" @click="cancelTransfer()">
                                Cancel
                            </button>
                        </div>
                    </template>
                </div>
            </template>

            {{-- Error --}}
            <template x-if="state === 'error'">
                <p class="osp-error" x-text="message"></p>
            </template>
        </div>
    </div>
</div>
