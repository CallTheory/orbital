/**
 * Alpine.data component for the operator softphone widget.
 *
 * Registered via `Alpine.data('softphone', softphone)` in app.js on
 * the `alpine:init` event so blade templates can reference it with
 * `x-data="softphone"`.
 *
 * SIP config is passed in via `data-sip-config` (JSON-encoded) on the
 * root element — pulled out in init().
 */
export default function softphone() {
    return {
        phone: null,
        state: 'idle',
        message: '',
        muted: false,
        held: false,
        dialInput: '',
        expanded: false,
        transferMode: false,
        transferInput: '',
        sipConfig: {},
        audioCtx: null,
        callTimer: null,
        callDuration: 0,
        dtmfFreqs: {
            '1': [697, 1209], '2': [697, 1336], '3': [697, 1477],
            '4': [770, 1209], '5': [770, 1336], '6': [770, 1477],
            '7': [852, 1209], '8': [852, 1336], '9': [852, 1477],
            '*': [941, 1209], '0': [941, 1336], '#': [941, 1477],
        },
        // Numpad key → dial character mapping
        numpadMap: {
            'Numpad0': '0', 'Numpad1': '1', 'Numpad2': '2', 'Numpad3': '3',
            'Numpad4': '4', 'Numpad5': '5', 'Numpad6': '6', 'Numpad7': '7',
            'Numpad8': '8', 'Numpad9': '9',
            'NumpadMultiply': '*', 'NumpadDecimal': '#',
        },

        async init() {
            try {
                this.sipConfig = JSON.parse(this.$el.dataset.sipConfig || '{}');
            } catch (_) {
                this.sipConfig = {};
            }

            if (!window.SipPhone || !this.sipConfig.wsUrl) return;

            // Persist the SipPhone instance on window so it survives
            // SPA navigation (wire:navigate).
            if (window._orbitalSipPhone) {
                this.phone = window._orbitalSipPhone;
                this.state = this.phone.state || 'idle';
                this.muted = this.phone.muted || false;
                this.held = this.phone.held || false;
                this.phone.onStateChange = (state, msg) => {
                    this._onStateChange(state, msg);
                };
                if (this.state === 'in-call') this._startTimer();
                return;
            }

            this.phone = new window.SipPhone();
            window._orbitalSipPhone = this.phone;
            this.phone.onStateChange = (state, msg) => {
                this._onStateChange(state, msg);
            };

            try {
                await this.phone.connect(this.sipConfig);
            } catch (err) {
                this.state = 'error';
                this.message = err?.message || 'Failed to connect';
            }

            // Global keyboard listener for dial pad input
            document.addEventListener('keydown', (e) => this._onKeyDown(e));
        },

        // ── State change handler ────────────────────────────────────
        _onStateChange(state, msg) {
            const prev = this.state;
            this.state = state;
            this.message = msg;

            if (state === 'in-call' && prev !== 'in-call') {
                this._startTimer();
                this.transferMode = false;
                this.transferInput = '';
            } else if (state !== 'in-call' && prev === 'in-call') {
                this._stopTimer();
                this.transferMode = false;
                this.transferInput = '';
                this.muted = false;
                this.held = false;
            }
        },

        // ── Call timer ──────────────────────────────────────────────
        _startTimer() {
            this.callDuration = 0;
            this._stopTimer();
            this.callTimer = setInterval(() => { this.callDuration++; }, 1000);
        },
        _stopTimer() {
            if (this.callTimer) {
                clearInterval(this.callTimer);
                this.callTimer = null;
            }
        },
        formattedDuration() {
            const m = Math.floor(this.callDuration / 60);
            const s = this.callDuration % 60;
            return `${m}:${s.toString().padStart(2, '0')}`;
        },

        // ── Keyboard handling ───────────────────────────────────────
        _onKeyDown(e) {
            // Only handle when softphone is expanded or in-call
            if (!this.expanded && this.state !== 'in-call' && this.state !== 'incoming') return;

            // Don't capture when typing in other inputs
            const tag = e.target?.tagName;
            if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') {
                // Allow dial input and transfer input
                if (!e.target.classList.contains('osp-dial-input') && !e.target.classList.contains('osp-transfer-input')) return;
            }

            // Numpad keys
            const numpadChar = this.numpadMap[e.code];
            if (numpadChar) {
                e.preventDefault();
                this.onPadKey(numpadChar);
                return;
            }

            // Regular digit keys (not in a text field)
            if (tag !== 'INPUT' && tag !== 'TEXTAREA') {
                if (e.key >= '0' && e.key <= '9') {
                    e.preventDefault();
                    this.onPadKey(e.key);
                    return;
                }
                if (e.key === '*' || e.key === '#') {
                    e.preventDefault();
                    this.onPadKey(e.key);
                    return;
                }
            }

            // Backspace — delete last digit from active input
            if (e.key === 'Backspace' && tag !== 'INPUT' && tag !== 'TEXTAREA') {
                e.preventDefault();
                if (this.transferMode) {
                    this.transferInput = this.transferInput.slice(0, -1);
                } else {
                    this.dialInput = this.dialInput.slice(0, -1);
                }
                return;
            }

            // Escape — clear input or cancel transfer
            if (e.key === 'Escape') {
                if (this.transferMode) {
                    this.cancelTransfer();
                } else {
                    this.dialInput = '';
                }
                return;
            }
        },

        // ── SIP actions ─────────────────────────────────────────────
        async call() {
            if (!this.phone || !this.dialInput) return;
            await this.phone.call(this.dialInput, this.sipConfig.domain);
        },
        answer() { this.phone?.answer(); },
        reject() { this.phone?.reject(); },
        hangup() {
            this.phone?.hangup();
            this.muted = false;
            this.held = false;
            this.transferMode = false;
            this.transferInput = '';
        },
        toggleMute() {
            if (!this.phone) return;
            this.muted = this.phone.toggleMute();
        },
        async toggleHold() {
            if (!this.phone) return;
            this.held = await this.phone.toggleHold();
        },
        sendDtmf(tone) {
            this.phone?.sendDTMF(tone);
        },

        // ── Transfer ────────────────────────────────────────────────
        enterTransferMode() {
            this.transferMode = true;
            this.transferInput = '';
        },
        cancelTransfer() {
            this.transferMode = false;
            this.transferInput = '';
        },
        executeTransfer() {
            if (!this.phone || !this.transferInput) return;
            this.phone.blindTransfer(this.transferInput, this.sipConfig.domain);
            this.transferMode = false;
            this.transferInput = '';
        },

        // ── Dial input helpers ──────────────────────────────────────
        clearDial() {
            this.dialInput = '';
        },
        backspaceDial() {
            this.dialInput = this.dialInput.slice(0, -1);
        },

        // ── DTMF audio feedback ─────────────────────────────────────
        playTone(key) {
            const f = this.dtmfFreqs[key];
            if (!f) return;

            try {
                if (!this.audioCtx) {
                    const Ctor = window.AudioContext || window.webkitAudioContext;
                    if (!Ctor) return;
                    this.audioCtx = new Ctor();
                }
                const ctx = this.audioCtx;
                if (ctx.state === 'suspended') ctx.resume();

                const now = ctx.currentTime;
                const attack = 0.005;
                const release = 0.03;
                const dur = 0.15;
                const peak = 0.5;

                const gain = ctx.createGain();
                gain.gain.setValueAtTime(0, now);
                gain.gain.linearRampToValueAtTime(peak, now + attack);
                gain.gain.setValueAtTime(peak, now + dur - release);
                gain.gain.linearRampToValueAtTime(0, now + dur);
                gain.connect(ctx.destination);

                f.forEach((hz) => {
                    const osc = ctx.createOscillator();
                    osc.type = 'sine';
                    osc.frequency.value = hz;
                    const oscGain = ctx.createGain();
                    oscGain.gain.value = 0.5;
                    osc.connect(oscGain).connect(gain);
                    osc.start(now);
                    osc.stop(now + dur + 0.02);
                });
            } catch (_) {
                // Audio unavailable
            }
        },

        // ── UI helpers ──────────────────────────────────────────────
        statusClass() {
            if (!this.phone || this.state === 'error') return 'is-error';
            return 'is-' + this.state;
        },

        onPadKey(key) {
            this.playTone(key);
            if (this.state === 'in-call') {
                this.sendDtmf(key);
                // Also append to dial display for visual feedback
                this.dialInput += key;
            } else if (this.transferMode) {
                this.transferInput += key;
            } else {
                this.dialInput += key;
            }
        },
    };
}
