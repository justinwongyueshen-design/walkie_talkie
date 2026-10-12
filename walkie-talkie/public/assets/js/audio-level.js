/*
 * audio-level.js - draws a VU meter, frequency bars and a waveform on a <canvas>.
 * Uses AudioContext + AnalyserNode only (no libraries).
 * Local microphone and every remote stream are mixed into one analyser, and since
 * only one person talks at a time the picture always shows "whoever is talking".
 */
(function () {
    'use strict';
    window.WT = window.WT || {};

    class AudioLevelMeter {
        constructor(canvas) {
            this.canvas = canvas;
            this.g = canvas.getContext('2d');
            this.audioCtx = null;
            this.analyser = null;
            this.sources = new Map();
            this.freq = null;
            this.wave = null;
            this.level = 0;
            this.draw = this.draw.bind(this);

            const css = getComputedStyle(document.documentElement);
            this.colors = {
                ok: (css.getPropertyValue('--wt-accent') || '#2fe38a').trim(),
                warn: (css.getPropertyValue('--wt-warn') || '#ffb020').trim(),
                hot: (css.getPropertyValue('--wt-danger') || '#ff4d5e').trim(),
                off: '#22304f',
                line: '#7cc4ff',
            };
            requestAnimationFrame(this.draw);
        }

        ensureContext() {
            if (this.audioCtx) return true;
            const AC = window.AudioContext || window.webkitAudioContext;
            if (!AC) return false;
            this.audioCtx = new AC();
            this.analyser = this.audioCtx.createAnalyser();
            this.analyser.fftSize = 256;
            this.analyser.smoothingTimeConstant = 0.7;
            this.freq = new Uint8Array(this.analyser.frequencyBinCount);
            this.wave = new Uint8Array(this.analyser.fftSize);
            // Silent output keeps the analyser "pulled" in every browser without playing sound twice.
            const mute = this.audioCtx.createGain();
            mute.gain.value = 0;
            this.analyser.connect(mute);
            mute.connect(this.audioCtx.destination);
            return true;
        }

        /** Must be called from a user gesture on some browsers (iOS). */
        resume() {
            if (this.audioCtx && this.audioCtx.state === 'suspended') {
                this.audioCtx.resume().catch(() => {});
            }
        }

        addStream(key, stream) {
            if (!this.ensureContext()) return;
            this.removeStream(key);
            try {
                const src = this.audioCtx.createMediaStreamSource(stream);
                src.connect(this.analyser);
                this.sources.set(key, src);
            } catch (err) {
                console.warn('[meter] cannot visualise stream', err);
            }
        }

        removeStream(key) {
            const src = this.sources.get(key);
            if (src) {
                try { src.disconnect(); } catch (_) { /* ignore */ }
                this.sources.delete(key);
            }
        }

        measure() {
            if (!this.analyser) { this.level = 0; return; }
            this.analyser.getByteFrequencyData(this.freq);
            this.analyser.getByteTimeDomainData(this.wave);
            let sum = 0;
            for (let i = 0; i < this.wave.length; i++) {
                const v = (this.wave[i] - 128) / 128;
                sum += v * v;
            }
            const rms = Math.sqrt(sum / this.wave.length);
            const target = Math.min(1, rms * 4);
            // quick attack, slow release - looks like a real VU meter
            this.level = target > this.level ? target : this.level * 0.9 + target * 0.1;
        }

        draw() {
            this.measure();
            const g = this.g;
            const w = this.canvas.width;
            const h = this.canvas.height;
            g.clearRect(0, 0, w, h);

            // 1) VU meter: 32 segments across the top
            const segs = 32;
            const gap = 4;
            const segW = (w - gap * (segs - 1)) / segs;
            const lit = Math.round(this.level * segs);
            for (let i = 0; i < segs; i++) {
                const x = i * (segW + gap);
                let color = this.colors.off;
                if (i < lit) color = i > segs * 0.85 ? this.colors.hot : i > segs * 0.6 ? this.colors.warn : this.colors.ok;
                g.fillStyle = color;
                g.fillRect(x, 4, segW, 22);
            }

            // 2) Frequency bars
            const bars = 48;
            const barGap = 3;
            const barW = (w - barGap * (bars - 1)) / bars;
            const top = 40;
            const area = h - top - 4;
            const usable = this.freq ? Math.floor(this.freq.length * 0.75) : 0;
            for (let i = 0; i < bars; i++) {
                const v = usable ? this.freq[Math.floor((i / bars) * usable)] / 255 : 0;
                const bh = Math.max(3, v * area);
                g.fillStyle = this.colors.ok;
                g.globalAlpha = 0.35 + v * 0.65;
                g.fillRect(i * (barW + barGap), top + area - bh, barW, bh);
            }
            g.globalAlpha = 1;

            // 3) Waveform line
            g.strokeStyle = this.colors.line;
            g.lineWidth = 2;
            g.beginPath();
            const mid = top + area / 2;
            for (let i = 0; i < w; i += 4) {
                const idx = this.wave ? Math.floor((i / w) * this.wave.length) : 0;
                const v = this.wave ? (this.wave[idx] - 128) / 128 : 0;
                const y = mid + v * (area / 2);
                if (i === 0) g.moveTo(i, y); else g.lineTo(i, y);
            }
            g.stroke();

            requestAnimationFrame(this.draw);
        }
    }

    window.WT.AudioLevelMeter = AudioLevelMeter;
})();
