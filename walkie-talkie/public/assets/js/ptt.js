/*
 * ptt.js - turns a button into a Push-to-Talk control.
 * Supports mouse, touch (pointer events) and the Space bar.
 * It only reports "pressed" and "released"; app.js decides what to do.
 */
(function () {
    'use strict';
    window.WT = window.WT || {};

    class PushToTalk {
        constructor(button, handlers) {
            this.button = button;
            this.onPress = handlers.onPress || function () {};
            this.onRelease = handlers.onRelease || function () {};
            this.held = false;
            this.source = null; // "pointer" or "key"
            this.bind();
        }

        bind() {
            const b = this.button;

            b.addEventListener('pointerdown', (e) => {
                if (e.pointerType === 'mouse' && e.button !== 0) return;
                e.preventDefault();
                try { b.setPointerCapture(e.pointerId); } catch (_) { /* not critical */ }
                this.press('pointer');
            });
            const up = () => this.release('pointer');
            b.addEventListener('pointerup', up);
            b.addEventListener('pointercancel', up);
            b.addEventListener('lostpointercapture', up);
            b.addEventListener('contextmenu', (e) => e.preventDefault()); // long-press menu on phones

            document.addEventListener('keydown', (e) => {
                if (e.code !== 'Space') return;
                if (!this.isPttTarget(e)) return;
                e.preventDefault();               // stop page scrolling / button "click"
                if (!e.repeat) this.press('key');
            });
            document.addEventListener('keyup', (e) => {
                if (e.code !== 'Space' || this.source !== 'key') return;
                e.preventDefault();
                this.release('key');
            });

            // Never leave the microphone open if the window loses focus.
            window.addEventListener('blur', () => this.release(null));
            document.addEventListener('visibilitychange', () => { if (document.hidden) this.release(null); });
        }

        /** Space should only trigger PTT when no other control (input, link, other button) has focus. */
        isPttTarget(e) {
            const t = e.target;
            if (t === this.button) return true;
            if (!t || !t.tagName) return true;
            const tag = t.tagName;
            return !(tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || tag === 'BUTTON' || tag === 'A' || t.isContentEditable);
        }

        press(source) {
            if (this.held) return;
            this.held = true;
            this.source = source;
            this.button.classList.add('is-held');
            this.onPress();
        }

        /** source = null forces a release whatever started it. */
        release(source) {
            if (!this.held) return;
            if (source && source !== this.source) return;
            this.held = false;
            this.source = null;
            this.button.classList.remove('is-held');
            this.onRelease();
        }
    }

    window.WT.PushToTalk = PushToTalk;
})();
