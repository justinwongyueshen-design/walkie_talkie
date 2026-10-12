/*
 * webrtc.js - one RTCPeerConnection per other user (a "mesh").
 *
 * Who sends the offer?  The user whose peer ID sorts first alphabetically.
 * Both sides can compute this, so two users never send offers at the same
 * time ("glare") and no extra coordination is needed.
 *
 * Audio design: every connection has ONE audio transceiver (send + receive).
 * The microphone track is attached with sender.replaceTrack(), so we never have
 * to renegotiate. The track is only "enabled" while the user holds the PTT button.
 *
 * ICE candidates: we wait (max ~2.5 s) until candidate gathering finishes and
 * send the complete SDP in one message. That means fewer HTTP requests, which is
 * ideal for polling signaling. Late candidates are still sent as "candidate" messages.
 */
(function () {
    'use strict';
    window.WT = window.WT || {};

    const STALE_CONNECTION_MS = 15000;
    const RETRY_MS = 8000;

    class PeerManager {
        constructor(opts) {
            this.selfPid = opts.selfPid;
            this.iceServers = opts.iceServers || [];
            this.signaling = opts.signaling;
            this.audioContainer = opts.audioContainer;
            this.onRemoteStream = opts.onRemoteStream || function () {};
            this.onPeerClosed = opts.onPeerClosed || function () {};
            this.onAutoplayBlocked = opts.onAutoplayBlocked || function () {};

            this.peers = new Map();    // pid -> entry
            this.orphans = new Map();  // pid -> candidates that arrived before their offer
            this.retryAt = new Map();  // pid -> earliest time for another offer
            this.localTrack = null;
            this.queue = Promise.resolve(); // handles incoming signals one at a time
        }

        /* ---------- local microphone ---------- */

        setLocalTrack(track) {
            this.localTrack = track;
            this.peers.forEach((entry) => this.attachTrack(entry));
        }

        attachTrack(entry) {
            if (!this.localTrack) return;
            const transceiver = entry.pc.getTransceivers()[0];
            if (transceiver) {
                transceiver.sender.replaceTrack(this.localTrack).catch((err) => console.warn('[webrtc] replaceTrack failed', err));
            }
        }

        /* ---------- keeping connections in sync with the user list ---------- */

        syncPeers(peerList) {
            const wanted = new Set(peerList.map((p) => p.pid).filter((pid) => pid !== this.selfPid));

            // Close connections to users that left.
            Array.from(this.peers.keys()).forEach((pid) => { if (!wanted.has(pid)) this.closePeer(pid); });

            // Start connections we are responsible for.
            const now = Date.now();
            wanted.forEach((pid) => {
                if (this.selfPid >= pid) return; // the other side sends the offer
                const entry = this.peers.get(pid);
                const state = entry ? entry.pc.connectionState : 'none';
                const broken = !entry || state === 'failed' || state === 'closed'
                    || (state !== 'connected' && now - entry.created > STALE_CONNECTION_MS);
                if (broken && now >= (this.retryAt.get(pid) || 0)) {
                    this.retryAt.set(pid, now + RETRY_MS);
                    this.connectTo(pid);
                }
            });
        }

        createEntry(pid) {
            const pc = new RTCPeerConnection({ iceServers: this.iceServers });
            const entry = { pid, pc, created: Date.now(), remoteSet: false, descSent: false, pending: [], audioEl: null };

            pc.onicecandidate = (e) => {
                // Candidates found before the description was sent are already inside the SDP.
                if (e.candidate && entry.descSent) {
                    this.signaling.sendSignal(pid, { kind: 'candidate', candidate: e.candidate.toJSON() });
                }
            };
            pc.ontrack = (e) => this.handleTrack(entry, e);
            pc.onconnectionstatechange = () => {
                if (pc.connectionState === 'failed') console.warn('[webrtc] connection to', pid, 'failed');
            };

            this.peers.set(pid, entry);
            return entry;
        }

        /* ---------- offering side ---------- */

        async connectTo(pid) {
            this.closePeer(pid);
            const entry = this.createEntry(pid);
            const pc = entry.pc;
            try {
                pc.addTransceiver('audio', { direction: 'sendrecv' });
                this.attachTrack(entry);
                await pc.setLocalDescription(await pc.createOffer());
                await this.waitForGathering(pc);
                if (this.peers.get(pid) !== entry) return; // replaced meanwhile
                entry.descSent = true;
                this.signaling.sendSignal(pid, { kind: 'offer', sdp: pc.localDescription.sdp });
            } catch (err) {
                console.error('[webrtc] offer failed', err);
                if (this.peers.get(pid) === entry) this.closePeer(pid);
            }
        }

        /* ---------- incoming messages ---------- */

        handleSignal(from, data) {
            if (!data || from === this.selfPid) return;
            this.queue = this.queue
                .then(() => this.processSignal(from, data))
                .catch((err) => console.error('[webrtc] signal error', err));
        }

        async processSignal(from, data) {
            if (data.kind === 'offer') return this.onOffer(from, data.sdp);
            if (data.kind === 'answer') return this.onAnswer(from, data.sdp);
            if (data.kind === 'candidate') return this.onCandidate(from, data.candidate);
        }

        async onOffer(from, sdp) {
            this.closePeer(from); // a new offer always replaces an old connection
            const entry = this.createEntry(from);
            const pc = entry.pc;
            try {
                await pc.setRemoteDescription({ type: 'offer', sdp });
                entry.remoteSet = true;

                // Make sure we can also SEND audio back (implicit transceivers start as receive-only).
                const transceiver = pc.getTransceivers()[0];
                if (transceiver) transceiver.direction = 'sendrecv';
                this.attachTrack(entry);

                await pc.setLocalDescription(await pc.createAnswer());
                await this.waitForGathering(pc);
                if (this.peers.get(from) !== entry) return;
                entry.descSent = true;
                this.signaling.sendSignal(from, { kind: 'answer', sdp: pc.localDescription.sdp });
                await this.flushCandidates(from, entry);
            } catch (err) {
                console.error('[webrtc] answer failed', err);
                if (this.peers.get(from) === entry) this.closePeer(from);
            }
        }

        async onAnswer(from, sdp) {
            const entry = this.peers.get(from);
            if (!entry || entry.pc.signalingState !== 'have-local-offer') return;
            await entry.pc.setRemoteDescription({ type: 'answer', sdp });
            entry.remoteSet = true;
            await this.flushCandidates(from, entry);
        }

        async onCandidate(from, candidate) {
            const entry = this.peers.get(from);
            if (!entry) {
                const list = this.orphans.get(from) || [];
                list.push(candidate);
                this.orphans.set(from, list.slice(-30));
                return;
            }
            if (!entry.remoteSet) { entry.pending.push(candidate); return; }
            try { await entry.pc.addIceCandidate(candidate); } catch (_) { /* stale candidate */ }
        }

        async flushCandidates(pid, entry) {
            const early = (this.orphans.get(pid) || []).concat(entry.pending);
            this.orphans.delete(pid);
            entry.pending = [];
            for (const c of early) {
                try { await entry.pc.addIceCandidate(c); } catch (_) { /* ignore */ }
            }
        }

        /* ---------- remote audio ---------- */

        handleTrack(entry, e) {
            // When the sender has no track yet, "streams" can be empty - build a stream ourselves.
            const stream = (e.streams && e.streams[0]) || new MediaStream([e.track]);
            if (!entry.audioEl) {
                const el = document.createElement('audio');
                el.autoplay = true;
                el.setAttribute('playsinline', '');
                this.audioContainer.appendChild(el);
                entry.audioEl = el;
            }
            entry.audioEl.srcObject = stream;
            const playing = entry.audioEl.play();
            if (playing && playing.catch) playing.catch(() => this.onAutoplayBlocked());
            this.onRemoteStream(entry.pid, stream);
        }

        /** Call from a click/tap: browsers only allow audio after a user gesture. */
        unlockAudio() {
            let blocked = false;
            this.peers.forEach((entry) => {
                if (entry.audioEl) {
                    const p = entry.audioEl.play();
                    if (p && p.catch) p.catch(() => { blocked = true; });
                }
            });
            return !blocked;
        }

        /* ---------- clean up ---------- */

        closePeer(pid) {
            const entry = this.peers.get(pid);
            this.orphans.delete(pid);
            if (!entry) return;
            this.peers.delete(pid);
            entry.pc.onicecandidate = null;
            entry.pc.ontrack = null;
            entry.pc.onconnectionstatechange = null;
            try { entry.pc.close(); } catch (_) { /* ignore */ }
            if (entry.audioEl) {
                entry.audioEl.srcObject = null;
                entry.audioEl.remove();
            }
            this.onPeerClosed(pid);
        }

        closeAll() {
            Array.from(this.peers.keys()).forEach((pid) => this.closePeer(pid));
        }

        waitForGathering(pc, timeoutMs = 2500) {
            return new Promise((resolve) => {
                if (pc.iceGatheringState === 'complete') { resolve(); return; }
                const finish = () => {
                    clearTimeout(timer);
                    pc.removeEventListener('icegatheringstatechange', onChange);
                    resolve();
                };
                const onChange = () => { if (pc.iceGatheringState === 'complete') finish(); };
                const timer = setTimeout(finish, timeoutMs);
                pc.addEventListener('icegatheringstatechange', onChange);
            });
        }
    }

    window.WT.PeerManager = PeerManager;
})();
