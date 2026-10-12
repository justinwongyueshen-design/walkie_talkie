/*
 * pwa.js - registers the service worker and handles the "Install app" button.
 */
(function () {
    'use strict';

    const base = document.documentElement.dataset.base || '';

    if ('serviceWorker' in navigator) {
        window.addEventListener('load', () => {
            navigator.serviceWorker
                .register(base + '/sw.js', { scope: base + '/' })
                .catch((err) => console.warn('[pwa] service worker not registered:', err.message));
        });
    }

    let deferredPrompt = null;

    window.addEventListener('beforeinstallprompt', (e) => {
        e.preventDefault();
        deferredPrompt = e;
        const btn = document.getElementById('installBtn');
        if (btn) btn.hidden = false;
    });

    document.addEventListener('DOMContentLoaded', () => {
        const btn = document.getElementById('installBtn');
        if (btn) {
            btn.addEventListener('click', async () => {
                if (!deferredPrompt) return;
                deferredPrompt.prompt();
                await deferredPrompt.userChoice;
                deferredPrompt = null;
                btn.hidden = true;
            });
        }

        // iPhone/iPad Safari has no install prompt: show a short hint instead.
        const ios = /iphone|ipad|ipod/i.test(navigator.userAgent);
        const standalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone;
        const hint = document.getElementById('iosHint');
        if (hint && ios && !standalone) hint.hidden = false;
    });

    window.addEventListener('appinstalled', () => {
        const btn = document.getElementById('installBtn');
        if (btn) btn.hidden = true;
    });
})();
