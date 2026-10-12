/*
 * signaling.js - talks to the PHP endpoint (POST /signal) using HTTP polling.
 *
 * Flow:  hello  ->  poll, poll, poll ...   (+ ptt_request / ptt_release / signal / leave)
 *
 * Events you can listen for with on():
 *   status  (name)   "connecting" | "connected" | "disconnected" | "reconnecting"
 *   state   (state)  full snapshot: peers, speaker, count, max
 *   event   (event)  peer_joined, peer_left, speaker_start, speaker_stop, signal
 *   fatal   (error)  unrecoverable problem (expired session, channel full ...)
 */
(function () {
    'use strict';
    window.WT = window.WT || {};

    const FATAL_CODES = ['invalid_token', 'expired', 'room_full', 'forbidden'];

    class SignalingClient {
        constructor(cfg) {
            this.url = cfg.signalUrl;
            this.token = cfg.token;
            this.pollMs = cfg.pollIntervalMs || 800;
            this.since = 0;
            this.status = 'connecting';
            this.failures = 0;
            this.running = false;
            this.joined = false;
            this.handlers = {};
            this.sendChain = Promise.resolve();
            this.fastUntil = 0;
            this.wakeFn = null;

            // Retry immediately when the browser says the network is back.
            window.addEventListener('online', () => this.wake());
        }

        on(name, fn) {
            (this.handlers[name] = this.handlers[name] || []).push(fn);
            return this;
        }

        emit(name, ...args) {
            (this.handlers[name] || []).forEach((fn) => {
                try { fn(...args); } catch (err) { console.error('[signaling] handler error', err); }
            });
        }

        setStatus(status) {
            if (this.status === status) return;
            this.status = status;
            this.emit('status', status);
        }

        get connected() { return this.status === 'connected'; }

        /** Poll faster for a few seconds (used while WebRTC messages are being exchanged). */
        speedUp() { this.fastUntil = Date.now() + 6000; this.wake(); }

        wake() { if (this.wakeFn) this.wakeFn(); }

        sleep(ms) {
            return new Promise((resolve) => {
                const timer = setTimeout(done, ms);
                const self = this;
                function done() { clearTimeout(timer); self.wakeFn = null; resolve(); }
                this.wakeFn = done;
            });
        }

        /** POST one JSON message. Throws on network problems; returns {status, data}. */
        async post(body, opts = {}) {
            const controller = new AbortController();
            const timer = setTimeout(() => controller.abort(), opts.timeout || 8000);
            try {
                const res = await fetch(this.url, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(Object.assign({}, body, { token: this.token })),
                    signal: controller.signal,
                    cache: 'no-store',
                    credentials: 'omit',
                    keepalive: !!opts.keepalive,
                });
                let data = null;
                try { data = await res.json(); } catch (_) { /* not JSON */ }
                if (!data) {
                    const err = new Error('Bad response from server');
                    err.retry = true;
                    throw err;
                }
                return { status: res.status, data };
            } finally {
                clearTimeout(timer);
            }
        }

        /** Look at an API reply and throw a suitable error when it is not ok. */
        check(res) {
            const { status, data } = res;
            if (data.ok) return data;

            if (FATAL_CODES.includes(data.error)) {
                const err = new Error(data.message || 'Connection error');
                err.fatal = true;
                err.code = data.error;
                throw err;
            }
            if (data.error === 'not_joined') {
                this.joined = false;
                const err = new Error('rejoin');
                err.rejoin = true;
                throw err;
            }
            if (status === 429) {
                const err = new Error('rate limited');
                err.retryAfter = Number(data.retry_after) || 2;
                throw err;
            }
            const err = new Error(data.message || 'Server error');
            err.retry = true;
            throw err;
        }

        applyToken(data) {
            if (typeof data.token === 'string') this.token = data.token;
        }

        async hello() {
            const data = this.check(await this.post({ type: 'hello' }));
            this.joined = true;
            this.since = data.seq;
            this.applyToken(data);
            this.emit('hello', data);
            this.emit('state', data.state);
        }

        async poll() {
            const data = this.check(await this.post({ type: 'poll', since: this.since }));
            this.applyToken(data);
            this.since = data.seq; // always trust the server's number (handles a restarted room)
            (data.events || []).forEach((ev) => {
                if (ev.type === 'signal') this.fastUntil = Date.now() + 6000;
                this.emit('event', ev);
            });
            this.emit('state', data.state);
        }

        /** Start the endless hello/poll loop. */
        async start() {
            if (this.running) return;
            this.running = true;
            this.setStatus('connecting');

            while (this.running) {
                let delay = Date.now() < this.fastUntil ? 300 : this.pollMs;
                if (this.failures > 0) this.setStatus('reconnecting');

                try {
                    if (!this.joined) await this.hello(); else await this.poll();
                    if (this.failures > 0 || this.status !== 'connected') {
                        this.failures = 0;
                        this.setStatus('connected');
                    }
                } catch (err) {
                    if (err && err.fatal) {
                        this.running = false;
                        this.emit('fatal', err);
                        return;
                    }
                    if (err && err.rejoin) {
                        delay = 0; // join again straight away
                    } else if (err && err.retryAfter) {
                        delay = err.retryAfter * 1000;
                    } else {
                        // Network problem: exponential backoff 1s, 2s, 4s ... max 10s (+ jitter).
                        this.failures += 1;
                        if (this.failures === 1) this.setStatus('disconnected');
                        delay = Math.min(10000, 1000 * Math.pow(2, this.failures - 1)) + Math.random() * 500;
                    }
                }
                if (this.running && delay > 0) await this.sleep(delay);
            }
        }

        stop() { this.running = false; this.wake(); }

        /** Ask for permission to talk. Resolves with {granted, ...}. */
        async pttRequest() {
            const data = this.check(await this.post({ type: 'ptt_request' }));
            return data;
        }

        async pttRelease() {
            try { return this.check(await this.post({ type: 'ptt_release' })); } catch (_) { return null; }
        }

        /** Send a WebRTC offer/answer/candidate. Messages are sent one after another to keep their order. */
        sendSignal(to, data) {
            this.fastUntil = Date.now() + 6000;
            this.sendChain = this.sendChain
                .then(() => this.post({ type: 'signal', to, data }))
                .then((res) => { try { this.check(res); } catch (_) { /* peer may already be gone */ } })
                .catch((err) => console.warn('[signaling] could not send signal', err));
            return this.sendChain;
        }

        /** Tell the server we are leaving (used by the Leave button). */
        async leave() {
            this.running = false;
            this.wake();
            try { await this.post({ type: 'leave' }, { keepalive: true, timeout: 2500 }); } catch (_) { /* ignore */ }
        }

        /** Last-chance notification when the page is closed. */
        beaconLeave() {
            this.running = false;
            const payload = JSON.stringify({ type: 'leave', token: this.token });
            try {
                if (navigator.sendBeacon) {
                    navigator.sendBeacon(this.url, new Blob([payload], { type: 'application/json' }));
                    return;
                }
            } catch (_) { /* fall through */ }
            try { fetch(this.url, { method: 'POST', body: payload, keepalive: true, credentials: 'omit' }); } catch (_) { /* ignore */ }
        }
    }

    window.WT.SignalingClient = SignalingClient;
})();
