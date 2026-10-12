/*
 * join.js - join page helpers: QR code and a live preview of the channel name rules.
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', () => {
        // ----- QR code (contains only the public application URL) -----
        const canvas = document.getElementById('qrCanvas');
        if (canvas && window.WT && window.WT.drawQr) {
            const url = canvas.dataset.url || window.location.href;
            try {
                window.WT.drawQr(canvas, url);
            } catch (err) {
                canvas.hidden = true;
                console.warn('[qr] could not draw QR code', err);
            }
            const host = new URL(url, window.location.href).hostname;
            if (['localhost', '127.0.0.1', '::1', '[::1]'].includes(host)) {
                const hint = document.getElementById('qrLocalhostHint');
                if (hint) hint.hidden = false;
            }
        }

        // ----- channel input: show the normalised name as the user types -----
        const channel = document.getElementById('channel');
        if (channel) {
            channel.addEventListener('blur', () => {
                channel.value = channel.value.trim().toLowerCase()
                    .replace(/\s+/g, '-').replace(/[^a-z0-9_-]/g, '').replace(/-{2,}/g, '-').replace(/^[-_]+|[-_]+$/g, '');
            });
        }
    });
})();
