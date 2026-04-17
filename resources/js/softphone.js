/**
 * Alpine.data component for the operator softphone widget.
 *
 * Registered via `Alpine.data('softphone', softphone)` in app.js on
 * the `alpine:init` event so blade templates can reference it with
 * `x-data="softphone"` and skip the HTML-attribute-escaping landmine
 * we hit when this logic was inlined — a double-quote inside a JS
 * comment terminated the attribute and spilled the function body onto
 * the page.
 *
 * SIP config is passed in via `data-sip-config` (JSON-encoded) on the
 * root element — pulled out in init(). Keeping config in data-* keeps
 * JS and HTML cleanly decoupled.
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
        sipConfig: {},
        audioCtx: null,
        dtmfFreqs: {
            '1': [697, 1209], '2': [697, 1336], '3': [697, 1477],
            '4': [770, 1209], '5': [770, 1336], '6': [770, 1477],
            '7': [852, 1209], '8': [852, 1336], '9': [852, 1477],
            '*': [941, 1209], '0': [941, 1336], '#': [941, 1477],
        },

        async init() {
            try {
                this.sipConfig = JSON.parse(this.$el.dataset.sipConfig || '{}');
            } catch (_) {
                this.sipConfig = {};
            }

            if (!window.SipPhone || !this.sipConfig.wsUrl) return;

            // Persist the SipPhone instance on window so it survives
            // SPA navigation (wire:navigate). If a phone already exists
            // from a previous page, reuse it — don't re-register.
            if (window._orbitalSipPhone) {
                this.phone = window._orbitalSipPhone;
                this.state = this.phone.state || 'idle';
                this.phone.onStateChange = (state, msg) => {
                    this.state = state;
                    this.message = msg;
                };
                return;
            }

            this.phone = new window.SipPhone();
            window._orbitalSipPhone = this.phone;
            this.phone.onStateChange = (state, msg) => {
                this.state = state;
                this.message = msg;
            };

            try {
                await this.phone.connect(this.sipConfig);
            } catch (err) {
                this.state = 'error';
                this.message = err?.message || 'Failed to connect';
            }
        },

        // ── SIP actions ──────────────────────────────────────────────
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
            this.dialInput += tone;
        },
        transfer() {
            if (!this.phone || !this.dialInput) return;
            this.phone.blindTransfer(this.dialInput, this.sipConfig.domain);
            this.dialInput = '';
        },

        // ── DTMF audio feedback ──────────────────────────────────────
        // Two sine waves summed, 150ms burst, linear attack + sustain +
        // release envelope so it sounds like a real phone press, not a
        // chirp. Lazy AudioContext because browser autoplay policy only
        // permits audio under a user gesture (a click qualifies).
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
                    // Per-oscillator gain so the summed peak stays under
                    // 1.0 when the two sines happen to be in phase.
                    const oscGain = ctx.createGain();
                    oscGain.gain.value = 0.5;
                    osc.connect(oscGain).connect(gain);
                    osc.start(now);
                    osc.stop(now + dur + 0.02);
                });
            } catch (_) {
                // Audio unavailable — fall back to silent feedback.
            }
        },

        // ── UI helpers ───────────────────────────────────────────────
        // Used by the pill status dot. Kept as a method rather than a
        // computed Alpine getter to keep reactivity explicit and avoid
        // the Magic() wrapper cost on every re-render.
        statusClass() {
            if (!this.phone || this.state === 'error') return 'is-error';
            return 'is-' + this.state;
        },

        // Click handler for dial-pad keys. Always plays the local tone
        // (user explicitly asked for audio feedback on pad clicks), then
        // either routes the digit as in-call DTMF or appends it to the
        // dial-input buffer when idle.
        onPadKey(key) {
            this.playTone(key);
            if (this.state === 'in-call') {
                this.sendDtmf(key);
            } else {
                this.dialInput += key;
            }
        },
    };
}
