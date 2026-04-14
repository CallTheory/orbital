import { UserAgent, Registerer, Inviter, SessionState } from 'sip.js';

class SipPhone {
    constructor() {
        this.userAgent = null;
        this.registerer = null;
        this.session = null;
        this.state = 'idle'; // idle, connecting, ringing, in-call, incoming, ended, error
        this.onStateChange = null;
        this.remoteAudio = null;
        this.muted = false;
        this.held = false;
    }

    async connect(config) {
        const { wsUrl, domain, username, password } = config;

        const uri = UserAgent.makeURI(`sip:${username}@${domain}`);
        if (!uri) {
            this._setState('error', 'Invalid SIP URI');
            return;
        }

        const transportOptions = {
            server: wsUrl,
        };

        const userAgentOptions = {
            authorizationUsername: username,
            authorizationPassword: password,
            transportOptions,
            uri,
            sessionDescriptionHandlerFactoryOptions: {
                peerConnectionConfiguration: {
                    iceServers: [{ urls: 'stun:stun.l.google.com:19302' }],
                },
            },
            delegate: {
                onInvite: (invitation) => this._handleIncoming(invitation),
                onDisconnect: (error) => {
                    if (error) {
                        this._setState('error', 'Disconnected');
                    }
                },
            },
        };

        this.userAgent = new UserAgent(userAgentOptions);

        try {
            await this.userAgent.start();

            this.registerer = new Registerer(this.userAgent);
            await this.registerer.register();

            this._setState('idle', 'Registered');
        } catch (error) {
            this._setState('error', error.message || 'Connection failed');
        }
    }

    _handleIncoming(invitation) {
        this.session = invitation;
        this._setState('incoming', invitation.remoteIdentity?.uri?.user || 'Unknown');

        // Auto-reject if we're already in a call
        if (this.state === 'in-call') {
            invitation.reject();
            return;
        }

        this._setState('incoming', invitation.remoteIdentity?.uri?.user || 'Unknown');

        invitation.stateChange.addListener((state) => {
            switch (state) {
                case SessionState.Established:
                    this._setState('in-call');
                    this._setupAudio(invitation);
                    break;
                case SessionState.Terminated:
                    this._setState('idle');
                    this._cleanupAudio();
                    this.session = null;
                    break;
            }
        });
    }

    async answer() {
        if (this.session && this.state === 'incoming') {
            await this.session.accept({
                sessionDescriptionHandlerOptions: {
                    constraints: { audio: true, video: false },
                },
            });
        }
    }

    reject() {
        if (this.session && this.state === 'incoming') {
            this.session.reject();
            this.session = null;
            this._setState('idle');
        }
    }

    async call(extension, domain) {
        if (!this.userAgent) {
            this._setState('error', 'Not connected');
            return;
        }

        const target = UserAgent.makeURI(`sip:${extension}@${domain}`);
        if (!target) {
            this._setState('error', 'Invalid extension');
            return;
        }

        this._setState('connecting');

        const inviter = new Inviter(this.userAgent, target, {
            sessionDescriptionHandlerOptions: {
                constraints: { audio: true, video: false },
            },
        });

        inviter.stateChange.addListener((state) => {
            switch (state) {
                case SessionState.Establishing:
                    this._setState('ringing');
                    break;
                case SessionState.Established:
                    this._setState('in-call');
                    this._setupAudio(inviter);
                    break;
                case SessionState.Terminated:
                    this._setState('idle');
                    this._cleanupAudio();
                    this.session = null;
                    break;
            }
        });

        try {
            await inviter.invite();
            this.session = inviter;
        } catch (error) {
            this._setState('error', error.message || 'Call failed');
        }
    }

    hangup() {
        if (this.session) {
            switch (this.session.state) {
                case SessionState.Established:
                    this.session.bye();
                    break;
                case SessionState.Establishing:
                case SessionState.Initial:
                    if (this.session.cancel) {
                        this.session.cancel();
                    } else if (this.session.reject) {
                        this.session.reject();
                    }
                    break;
                default:
                    break;
            }
            this.session = null;
        }
        this.muted = false;
        this.held = false;
        this._setState('idle');
        this._cleanupAudio();
    }

    toggleMute() {
        if (!this.session) return false;

        const sdh = this.session.sessionDescriptionHandler;
        if (!sdh) return false;

        const pc = sdh.peerConnection;
        if (!pc) return false;

        const senders = pc.getSenders();
        const audioSender = senders.find(s => s.track && s.track.kind === 'audio');

        if (audioSender && audioSender.track) {
            audioSender.track.enabled = !audioSender.track.enabled;
            this.muted = !audioSender.track.enabled;
            return this.muted;
        }

        return false;
    }

    async toggleHold() {
        if (!this.session || this.session.state !== SessionState.Established) return false;

        try {
            if (this.held) {
                // Unhold — re-invite with sendrecv
                const sdh = this.session.sessionDescriptionHandler;
                if (sdh && sdh.peerConnection) {
                    sdh.peerConnection.getSenders().forEach(sender => {
                        if (sender.track) sender.track.enabled = true;
                    });
                }
                this.held = false;
            } else {
                // Hold — stop sending audio
                const sdh = this.session.sessionDescriptionHandler;
                if (sdh && sdh.peerConnection) {
                    sdh.peerConnection.getSenders().forEach(sender => {
                        if (sender.track) sender.track.enabled = false;
                    });
                }
                this.held = true;
            }
            return this.held;
        } catch (e) {
            console.error('Hold toggle failed:', e);
            return this.held;
        }
    }

    sendDTMF(tone) {
        if (!this.session || this.session.state !== SessionState.Established) return;

        const sdh = this.session.sessionDescriptionHandler;
        if (sdh && sdh.peerConnection) {
            const senders = sdh.peerConnection.getSenders();
            const audioSender = senders.find(s => s.track && s.track.kind === 'audio');
            if (audioSender && audioSender.dtmf) {
                audioSender.dtmf.insertDTMF(tone, 100, 70);
            }
        }
    }

    async blindTransfer(extension, domain) {
        if (!this.session || this.session.state !== SessionState.Established) return false;

        const target = UserAgent.makeURI(`sip:${extension}@${domain}`);
        if (!target) return false;

        try {
            await this.session.refer(target);
            this.hangup();
            return true;
        } catch (e) {
            console.error('Blind transfer failed:', e);
            return false;
        }
    }

    _setupAudio(session) {
        const sdh = session.sessionDescriptionHandler;
        if (!sdh) return;

        const pc = sdh.peerConnection;
        if (!pc) return;

        if (!this.remoteAudio) {
            this.remoteAudio = document.createElement('audio');
            this.remoteAudio.id = 'sip-remote-audio';
            this.remoteAudio.autoplay = true;
            document.body.appendChild(this.remoteAudio);
        }

        const remoteStream = new MediaStream();
        pc.getReceivers().forEach(receiver => {
            if (receiver.track) {
                remoteStream.addTrack(receiver.track);
            }
        });

        this.remoteAudio.srcObject = remoteStream;
    }

    _cleanupAudio() {
        if (this.remoteAudio) {
            this.remoteAudio.srcObject = null;
            this.remoteAudio.remove();
            this.remoteAudio = null;
        }
    }

    _setState(state, message = '') {
        this.state = state;
        if (this.onStateChange) {
            this.onStateChange(state, message);
        }
        // Dispatch custom event for Livewire integration
        window.dispatchEvent(new CustomEvent('sip-state-change', {
            detail: { state, message }
        }));
    }

    disconnect() {
        this.hangup();
        if (this.registerer) {
            this.registerer.unregister().catch(() => {});
            this.registerer = null;
        }
        if (this.userAgent) {
            this.userAgent.stop();
            this.userAgent = null;
        }
        this._setState('idle');
    }
}

// Export as global for Alpine.js usage
window.SipPhone = SipPhone;
