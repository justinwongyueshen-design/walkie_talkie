/*
 * sw.js - basic service worker.
 *
 * - Caches the app shell: CSS, JavaScript, icons, manifest and an offline page.
 * - NEVER caches /signal or HTML pages (they contain private session data).
 * - Pages are always fetched from the network; if the network is down the
 *   cached offline page is shown instead.
 */
const CACHE = 'walkie-talkie-v1';
// The scope looks like "/walkie-talkie/" or "/", so the worker works in any folder.
const SCOPE = new URL(self.registration.scope).pathname;

const SHELL = [
    'offline.html',
    'manifest.webmanifest',
    'assets/css/app.css',
    'assets/js/pwa.js',
    'assets/js/app.js',
    'assets/js/signaling.js',
    'assets/js/webrtc.js',
    'assets/js/ptt.js',
    'assets/js/audio-level.js',
    'assets/js/qr.js',
    'assets/js/join.js',
    'assets/icons/icon-192.png',
    'assets/icons/icon-512.png',
    'assets/icons/favicon-32.png',
    'assets/icons/apple-touch-icon.png',
].map((p) => SCOPE + p);

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE)
            // One missing file must not break the whole installation.
            .then((cache) => Promise.allSettled(SHELL.map((url) => cache.add(url))))
            .then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
            .then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', (event) => {
    const req = event.request;
    if (req.method !== 'GET') return;                       // POST /signal etc. go straight to the network

    const url = new URL(req.url);
    if (url.origin !== self.location.origin) return;        // CDN files are handled by the browser
    if (url.pathname.endsWith('/signal')) return;           // never touch signaling

    // Page navigations: network first, offline page as fallback. HTML is never stored.
    if (req.mode === 'navigate') {
        event.respondWith(
            fetch(req).catch(() => caches.match(SCOPE + 'offline.html'))
        );
        return;
    }

    // Static files: serve from cache and refresh the cache in the background.
    const isStatic = url.pathname.startsWith(SCOPE + 'assets/') || url.pathname === SCOPE + 'manifest.webmanifest';
    if (isStatic) {
        event.respondWith(
            caches.open(CACHE).then((cache) =>
                cache.match(req, { ignoreSearch: true }).then((cached) => {
                    const network = fetch(req).then((res) => {
                        if (res && res.ok) cache.put(req, res.clone());
                        return res;
                    }).catch(() => cached);
                    return cached || network;
                })
            )
        );
    }
});
