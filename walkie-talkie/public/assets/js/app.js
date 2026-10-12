/*
 * app.js - the channel screen. It connects the other modules together:
 *   SignalingClient (signaling.js)  <->  server
 *   PeerManager     (webrtc.js)     <->  other browsers
 *   PushToTalk      (ptt.js)        <->  the big button
 *   AudioLevelMeter (audio-level.js)<->  the canvas
 */
(function () {
    'use strict';

    const cfg = JSON.parse(document.getElementById('wt-config').textContent);
    const $ = (id) => document.getElementById(id);

    const STATUS_TEXT = {
        connecting: 'CONNECTING',
        connected: 'CONNECTED',
        disconnected: 'DISCONNECTED',
        reconnecting: 'RECONNECTING',
    };

    class WalkieTalkieApp {
        constructor() {
            this.me = { pid: cfg.pid, nick: cfg.nick };
            this.snapshot = { peers: [], speaker: null, count: 0, max: cfg.maxPeers };
            this.status = 'connecting';

            // push-to-talk state
            this.requesting = false;
            this.transmitting = false;
            this.blockedUntilRelease = false;
            this.flash = null;          // temporary label such as "CHANNEL BUSY"
            this.flashTimer = null;
            this.noticeTimer = null;
            this.countdownTimer = null;
            this.talkExpiresAt = 0;
            this.leaving = false;

            // microphone state: "off" (not asked yet) | "ready" | "denied" | "unsupported" | "error"
            this.mic = { state: 'off', stream: null, track: null, pending: null };

            this.el = {
                status: $('connStatus'), statusText: $('connStatusText'),
                ptt: $('pttButton'), pttLabel: $('pttLabel'),
                speakerPanel: $('speakerPanel'), speakerText: $('speakerText'), live: $('liveBadge'),
                userList: $('userList'), userCount: $('userCount'),
                micStatus: $('micStatus'), micIcon: $('micIcon'), micBox: $('micStatus').parentElement,
                micBtn: $('micEnableBtn'), notice: $('notice'),
                unlockBtn: $('audioUnlockBtn'), leaveBtn: $('leaveBtn'),
                fatal: $('fatalBox'), fatalText: $('fatalText'),
            };

            this.meter = new window.WT.AudioLevelMeter($('levelCanvas'));
            this.signaling = new window.WT.SignalingClient(cfg);
            this.peers = new window.WT.PeerManager({
                selfPid: cfg.pid,
                iceServers: cfg.iceServers,
                signaling: this.signaling,
                audioContainer: $('remoteAudio'),
                onRemoteStream: (pid, stream) => this.meter.addStream('remote:' + pid, stream),
                onPeerClosed: (pid) => this.meter.removeStream('remote:' + pid),
                onAutoplayBlocked: () => { this.el.unlockBtn.hidden = false; },
            });
            this.ptt = new window.WT.PushToTalk(this.el.ptt, {
                onPress: () => this.handlePress(),
                onRelease: () => this.handleRelease(),
            });

            this.bindSignaling();
            this.bindUi();
            this.render();
            this.checkExistingMicPermission();
            this.signaling.start();
        }

        /* ================= signaling events ================= */

        bindSignaling() {
            const s = this.signaling;

            s.on('status', (status) => {
                this.status = status;
                if (status !== 'connected' && this.transmitting) this.stopTransmit(false);
                if (status === 'disconnected' || status === 'reconnecting') {
                    this.showNotice('Connection lost. Reconnecting...', 'warn', 0);
                } else if (status === 'connected') {
                    this.hideNotice();
                }
                this.render();
            });

            s.on('state', (snap) => {
                this.snapshot = snap;
                this.peers.syncPeers(snap.peers);

                // Safety net: the server thinks we are talking but we are not -> give the floor back.
                if (snap.speaker && snap.speaker.pid === this.me.pid && !this.transmitting && !this.requesting) {
                    this.signaling.pttRelease();
                }
                this.render();
            });

            s.on('event', (ev) => this.handleEvent(ev));

            s.on('fatal', (err) => {
                this.stopTransmit(false);
                this.peers.closeAll();
                this.el.fatalText.textContent = err.message || 'Unable to connect to the communication server.';
                this.el.fatal.hidden = false;
                this.setStatus('disconnected');
            });
        }

        setStatus(status) {
            this.status = status;
            this.render();
        }

        handleEvent(ev) {
            switch (ev.type) {
                case 'signal':
                    this.peers.handleSignal(ev.from, ev.data);
                    break;
                case 'peer_joined':
                    if (ev.pid !== this.me.pid) this.showNotice(ev.nick + ' joined the channel', 'info', 2500);
                    break;
                case 'peer_left':
                    if (ev.pid !== this.me.pid) this.showNotice(ev.nick + ' left the channel', 'info', 2500);
                    break;
                case 'speaker_stop':
                    if (ev.pid === this.me.pid && this.transmitting) {
                        // The server ended our turn (time limit or connection problem).
                        this.stopTransmit(false);
                        if (ev.reason === 'timeout') {
                            this.blockedUntilRelease = this.ptt.held;
                            this.showNotice('Talk time ended', 'warn', 4000);
                        }
                    }
                    break;
                default:
                    break;
            }
        }

        /* ================= push to talk ================= */

        async handlePress() {
            this.meter.resume();
            this.peers.unlockAudio();

            if (this.blockedUntilRelease) return;
            if (this.status !== 'connected') {
                this.showNotice('Connection lost. Reconnecting...', 'warn', 3000);
                return;
            }

            // 1) microphone permission (asks the first time)
            if (!(await this.ensureMic())) return;
            if (!this.ptt.held) return; // released while the permission prompt was open

            // 2) ask the server for the floor
            this.requesting = true;
            this.render();
            let result;
            try {
                result = await this.signaling.pttRequest();
            } catch (err) {
                this.requesting = false;
                this.showNotice('Unable to reach the communication server.', 'error', 3000);
                this.render();
                return;
            }
            this.requesting = false;

            if (!result.granted) {
                const who = result.speaker && result.speaker.nick;
                this.flashLabel('CHANNEL BUSY', 'is-denied', 1500);
                this.showNotice(who ? who + ' is currently speaking' : 'Channel is currently busy.', 'warn', 2500);
                if (navigator.vibrate) navigator.vibrate(80);
                this.render();
                return;
            }
            if (!this.ptt.held) { // released while we waited for the answer
                this.signaling.pttRelease();
                this.render();
                return;
            }
            this.startTransmit(result);
        }

        handleRelease() {
            const wasBlocked = this.blockedUntilRelease;
            this.blockedUntilRelease = false;
            if (this.transmitting) this.stopTransmit(true);
            else if (wasBlocked) this.render();
        }

        startTransmit(result) {
            this.transmitting = true;
            this.talkExpiresAt = result.expires_at;
            if (this.mic.track) this.mic.track.enabled = true; // microphone ON
            if (navigator.vibrate) navigator.vibrate(30);

            const seconds = Math.round((result.duration_ms || cfg.floorTimeoutMs) / 1000);
            this.showNotice('You have ' + seconds + ' seconds to talk.', 'info', 3000);
            this.countdownTimer = setInterval(() => this.renderSpeaker(), 250);
            this.render();
        }

        /** Stop talking. sendRelease=true tells the server to free the floor. */
        stopTransmit(sendRelease) {
            if (this.mic.track) this.mic.track.enabled = false; // microphone OFF
            const wasTransmitting = this.transmitting;
            this.transmitting = false;
            clearInterval(this.countdownTimer);
            this.countdownTimer = null;
            if (sendRelease && wasTransmitting) this.signaling.pttRelease();
            this.render();
        }

        flashLabel(text, cssClass, ms) {
            this.flash = { text, cssClass };
            clearTimeout(this.flashTimer);
            this.flashTimer = setTimeout(() => { this.flash = null; this.render(); }, ms);
        }

        /* ================= microphone ================= */

        async checkExistingMicPermission() {
            // If permission was already granted earlier we can prepare the microphone silently.
            try {
                if (navigator.permissions && navigator.permissions.query) {
                    const status = await navigator.permissions.query({ name: 'microphone' });
                    if (status.state === 'granted') this.ensureMic();
                    if (status.state === 'denied') { this.mic.state = 'denied'; this.render(); }
                }
            } catch (_) { /* Safari does not support this query - ignore */ }
        }

        async ensureMic() {
            if (this.mic.track && this.mic.track.readyState === 'live') return true;
            if (this.mic.pending) return this.mic.pending;

            if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                this.mic.state = 'unsupported';
                this.showNotice('Microphone access needs a secure connection (HTTPS) or localhost. You can still listen.', 'warn', 8000);
                this.render();
                return false;
            }

            this.mic.pending = (async () => {
                try {
                    const stream = await navigator.mediaDevices.getUserMedia({
                        audio: { echoCancellation: true, noiseSuppression: true, autoGainControl: true },
                        video: false,
                    });
                    const track = stream.getAudioTracks()[0];
                    track.enabled = false; // stays silent until the user holds PTT
                    track.addEventListener('ended', () => {
                        this.mic.track = null;
                        this.mic.state = 'off';
                        this.render();
                    });
                    this.mic.stream = stream;
                    this.mic.track = track;
                    this.mic.state = 'ready';
                    this.peers.setLocalTrack(track);
                    this.meter.addStream('local', stream);
                    return true;
                } catch (err) {
                    const denied = err && (err.name === 'NotAllowedError' || err.name === 'SecurityError');
                    this.mic.state = denied ? 'denied' : 'error';
                    this.showNotice(denied
                        ? 'Microphone permission is required to talk. You can still listen.'
                        : 'No microphone could be started. You can still listen.', 'warn', 6000);
                    return false;
                } finally {
                    this.mic.pending = null;
                    this.render();
                }
            })();
            return this.mic.pending;
        }

        releaseMic() {
            if (this.mic.stream) this.mic.stream.getTracks().forEach((t) => t.stop());
            this.meter.removeStream('local');
            this.mic.stream = null;
            this.mic.track = null;
        }

        /* ================= UI ================= */

        bindUi() {
            this.el.micBtn.addEventListener('click', () => { this.meter.resume(); this.ensureMic(); });

            this.el.unlockBtn.addEventListener('click', () => {
                this.meter.resume();
                this.peers.unlockAudio();
                this.el.unlockBtn.hidden = true;
            });
            // Any first tap on the page also unlocks audio playback.
            document.addEventListener('pointerdown', () => {
                this.meter.resume();
                if (this.peers.unlockAudio()) this.el.unlockBtn.hidden = true;
            }, { once: true });

            this.el.leaveBtn.addEventListener('click', () => this.leave());

            // Tell the server when the tab is closed.
            window.addEventListener('pagehide', () => { if (!this.leaving) this.signaling.beaconLeave(); });
        }

        async leave() {
            this.leaving = true;
            this.el.leaveBtn.disabled = true;
            this.stopTransmit(false);
            this.releaseMic();
            this.peers.closeAll();
            await this.signaling.leave();
            window.location.assign(cfg.homeUrl);
        }

        showNotice(text, kind, ms) {
            const n = this.el.notice;
            n.textContent = text;
            n.dataset.kind = kind || 'warn';
            n.hidden = false;
            clearTimeout(this.noticeTimer);
            if (ms) this.noticeTimer = setTimeout(() => this.hideNotice(), ms);
        }

        hideNotice() {
            clearTimeout(this.noticeTimer);
            this.el.notice.hidden = true;
        }

        render() {
            this.renderStatus();
            this.renderPtt();
            this.renderSpeaker();
            this.renderUsers();
            this.renderMic();
        }

        renderStatus() {
            this.el.status.dataset.state = this.status;
            this.el.statusText.textContent = STATUS_TEXT[this.status] || this.status.toUpperCase();
        }

        renderPtt() {
            const b = this.el.ptt;
            const other = this.snapshot.speaker && this.snapshot.speaker.pid !== this.me.pid;
            b.classList.toggle('is-transmitting', this.transmitting);
            b.classList.toggle('is-requesting', this.requesting);
            b.classList.toggle('is-busy-other', !!other && !this.transmitting);
            b.classList.toggle('is-denied', !!(this.flash && this.flash.cssClass === 'is-denied'));
            b.setAttribute('aria-pressed', this.transmitting ? 'true' : 'false');

            let label = 'HOLD TO TALK';
            if (this.transmitting) label = 'TRANSMITTING';
            else if (this.requesting) label = 'REQUESTING...';
            else if (this.flash) label = this.flash.text;
            this.el.pttLabel.textContent = label;
        }

        renderSpeaker() {
            const sp = this.snapshot.speaker;
            const panel = this.el.speakerPanel;
            let mode = 'idle';
            let text = 'Waiting for a speaker';

            if (this.transmitting) {
                mode = 'self';
                const left = Math.max(0, Math.ceil((this.talkExpiresAt - Date.now()) / 1000));
                text = 'You are transmitting' + (left > 0 && left < 3600 ? ' · ' + left + 's left' : '');
            } else if (sp && sp.pid !== this.me.pid) {
                mode = 'other';
                text = sp.nick + ' is talking';
            }
            panel.dataset.mode = mode;
            this.el.live.hidden = mode === 'idle';
            if (this.el.speakerText.textContent !== text) this.el.speakerText.textContent = text;
        }

        renderUsers() {
            const peers = this.snapshot.peers || [];
            const n = peers.length;
            this.el.userCount.innerHTML = '';
            const icon = document.createElement('i');
            icon.className = 'fa-solid fa-users';
            icon.setAttribute('aria-hidden', 'true');
            this.el.userCount.append(icon, ' ' + n + (n === 1 ? ' USER' : ' USERS'));

            const frag = document.createDocumentFragment();
            peers.forEach((p) => {
                const speaking = !!(this.snapshot.speaker && this.snapshot.speaker.pid === p.pid);
                const li = document.createElement('li');
                li.className = 'wt-user' + (speaking ? ' is-speaking' : '');

                const dot = document.createElement('span');
                dot.className = 'wt-user-dot';
                dot.setAttribute('aria-hidden', 'true');

                const name = document.createElement('span');
                name.className = 'wt-user-name';
                name.textContent = p.nick; // textContent => user text can never run as HTML
                if (p.pid === this.me.pid) {
                    const you = document.createElement('small');
                    you.textContent = ' (you)';
                    name.appendChild(you);
                }

                const state = document.createElement('span');
                state.className = 'wt-user-state';
                state.textContent = speaking ? 'SPEAKING' : 'LISTENING';

                li.append(dot, name, state);
                frag.appendChild(li);
            });
            this.el.userList.replaceChildren(frag);
        }

        renderMic() {
            const m = this.mic.state;
            let text = 'Microphone: OFF';
            let icon = 'fa-microphone-slash';
            if (m === 'ready') {
                text = this.transmitting ? 'Microphone: ON' : 'Microphone: OFF (ready)';
                icon = this.transmitting ? 'fa-microphone' : 'fa-microphone-slash';
            } else if (m === 'denied') text = 'Microphone blocked - allow it in your browser settings';
            else if (m === 'unsupported') text = 'Microphone unavailable (HTTPS required)';
            else if (m === 'error') text = 'Microphone not found';

            this.el.micStatus.textContent = text;
            this.el.micIcon.className = 'fa-solid ' + icon;
            this.el.micBox.classList.toggle('is-on', this.transmitting);
            this.el.micBox.classList.toggle('is-problem', m === 'denied' || m === 'unsupported' || m === 'error');
            this.el.micBtn.hidden = m === 'ready' || m === 'unsupported';
        }
    }

    document.addEventListener('DOMContentLoaded', () => { window.WT.app = new WalkieTalkieApp(); });
})();
