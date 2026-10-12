# Walkie Talkie — PWA Push-to-Talk (PHP + WebRTC)

A browser-based digital walkie-talkie. People enter a **nickname** and a **channel name**, join the same
channel, and talk by pressing and holding a big **Push-to-Talk** button. Only **one person can talk at a time**.
Voice travels directly between browsers using **WebRTC**; PHP only handles the "signaling" (who is in the
channel, who is talking, and the connection handshake).

Built with **PHP 8.3+, HTML5, CSS3, vanilla JavaScript, Bootstrap 5 (CDN), Font Awesome (CDN)**.
No database, no framework, no Node.js required.

---

## Features

- Landing page with nickname + channel, input sanitising, guest identity (no accounts)
- Channels created on the fly — same name = same room (2–8 users, configurable)
- Large round PTT button: mouse, touch, pointer events and **Space bar**
- **Communication floor**: one speaker at a time, "CHANNEL BUSY" / "Alex is currently speaking"
- Configurable **talk-time limit** (default 30 s), floor released automatically
- Live participant list (nickname, online, SPEAKING / LISTENING) and user count
- Connection states: `CONNECTING`, `CONNECTED`, `DISCONNECTED`, `RECONNECTING` + automatic reconnect with exponential backoff
- Microphone is **OFF** (track disabled) until you hold the button; friendly message when permission is denied (you can still listen)
- Audio level monitor: VU meter, frequency bars and waveform (`AudioContext` + `AnalyserNode` + `<canvas>`)
- PWA: `manifest.webmanifest`, service worker, installable, offline page
- QR code on the join page (generated in the browser, contains only the public URL)
- Security: HMAC-SHA256 signed short-lived tokens, CSRF protection, input validation, output escaping,
  rate limiting, payload limits, secure session cookie settings, CSP and other security headers
- Accessible: labels, ARIA live regions, `aria-pressed`, focus outlines, keyboard PTT
- Error handling with friendly messages; technical errors go to `storage/logs/`

---

## Project structure

```text
walkie-talkie/
├── index.php              front controller (project folder is the web root, e.g. XAMPP)
├── .htaccess              rewrite rules + blocks private folders
├── .env / .env.example    configuration
├── config/                app.php (settings), routes.php (route table)
├── app/
│   ├── bootstrap.php      autoloader + start
│   ├── Core/              App, Router, Request, Response, Session, View, Env, Config, Logger
│   ├── Controllers/       JoinController, ChannelController, SignalController
│   ├── Middleware/        CsrfMiddleware, ThrottleMiddleware
│   ├── Models/            Room (room data helpers)
│   ├── Services/          RoomService, SignalingService, TokenService, RateLimiter, GuestService, ...
│   └── Helpers/           functions.php (e(), url(), asset(), config() ...)
├── views/                 layouts/main.php, join.php, channel.php, errors/error.php
├── public/                index.php (alternative front controller), sw.js, manifest.webmanifest, offline.html
│   └── assets/            css/app.css, js/*.js, icons/
├── storage/               rooms/ (JSON), logs/, sessions/, ratelimit/  — must be writable
└── tests/selftest.php     command-line test of the server logic
```

| JavaScript file | Job |
|---|---|
| `signaling.js` | HTTP polling client: hello/poll/ptt/signal/leave, reconnect with backoff |
| `webrtc.js` | One `RTCPeerConnection` per other user, offer/answer/ICE, remote audio |
| `ptt.js` | Turns the button + Space bar into press/release events |
| `audio-level.js` | VU meter, bars and waveform |
| `app.js` | Connects everything, renders the UI |
| `pwa.js` | Service worker registration, install button |
| `qr.js`, `join.js` | QR code generator and join-page helpers |

---

## How it works (for students)

1. **Join** — `POST /join` validates nickname and channel, stores them in the PHP session and redirects to `/channel/<name>`.
2. **Token** — the channel page creates a *peer ID* and a **signed token** (`base64url(payload).base64url(HMAC-SHA256)`) containing
   `pid`, `nick`, `channel`, `exp`. The browser sends this token with every request; the server never trusts a nickname or channel from the client.
3. **Signaling** — the browser sends `hello`, then calls `poll` about every 0.8 s. `poll` is a heartbeat *and* returns new events
   (user joined/left, speaker started/stopped, WebRTC messages). Everything is stored in `storage/rooms/*.json`.
4. **Floor control** — `ptt_request` runs inside an exclusive `flock()`, so if two people press at the same instant the file lock
   decides: the first gets `granted`, the second gets `busy`.
5. **WebRTC** — when you appear in the user list, the peer with the alphabetically smaller ID sends an offer (so two people never offer at the
   same time). Offer/answer/ICE candidates are relayed through `POST /signal`. Audio then flows **directly** between browsers.
6. **Microphone** — each connection has one audio transceiver. Your mic track is attached with `replaceTrack()` once, and
   `track.enabled` is switched on only while you hold PTT. No renegotiation is ever needed.

Messages accepted by `POST /signal`: `hello`, `poll`, `ptt_request`, `ptt_release`, `signal`, `leave`.

---

## Requirements

```text
PHP 8.3+ (8.1+ should also work)
Apache with mod_rewrite (XAMPP, cPanel hosting) — or PHP's built-in server for quick tests
Chrome / Edge / Firefox / Safari (recent versions)
HTTPS for microphone access in production (http://localhost is also allowed)
```

---

## Quick start without Apache (built-in PHP server)

```bash
cd walkie-talkie
php -S localhost:8000 -t public public/index.php
```
Open <http://localhost:8000>. The built-in server handles one request at a time, which is fine for testing with 2–3 browser tabs
but use Apache for real use.

---

## XAMPP installation (Windows)

1. **Copy the project** to `C:\xampp\htdocs\walkie-talkie`.
2. **Configure `.env`**: it already exists. Leave `SIGNAL_SECRET=` empty and the app generates a random secret in `storage/.secret`,
   or paste your own long random value (`php -r "echo bin2hex(random_bytes(32));"`). Leave `APP_BASE_PATH=` empty to auto-detect `/walkie-talkie`.
3. **Start Apache** in the XAMPP Control Panel (`mod_rewrite` is enabled by default; `AllowOverride All` is the XAMPP default).
4. **Open** <http://localhost/walkie-talkie/>.
5. **Join a channel**: enter a nickname and a channel, e.g. `room-101`.
6. **Allow the microphone** when the browser asks (or click *Enable microphone*).
7. To try it alone, open a **second tab** (or a private window) and join the same channel with another nickname.
   Use headphones, otherwise you will hear yourself through the speakers.

Check the server logic any time with: `C:\xampp\php\php.exe tests\selftest.php`

---

## Mobile testing

1. Put the phone and the PC on the **same Wi-Fi**.
2. Find the PC's LAN IP (`ipconfig` on Windows → e.g. `192.168.1.20`). Allow Apache through the Windows firewall (private network).
3. Set `APP_PUBLIC_URL=http://192.168.1.20/walkie-talkie` in `.env` so the join page QR code points to the right address, then scan it.
4. **HTTPS requirement:** browsers only expose the microphone on **secure origins** (HTTPS or `localhost`). Over plain `http://192.168.x.x`
   the phone can *listen* but cannot *talk*. Options for testing:
   - Enable XAMPP's HTTPS (`https://192.168.1.20/walkie-talkie`, accept the self-signed certificate warning once), or
   - Use a free HTTPS tunnel (e.g. Cloudflare Tunnel or ngrok) pointing at your Apache, or
   - Chrome Android only (testing): `chrome://flags/#unsafely-treat-insecure-origin-as-secure` → add `http://192.168.1.20`.
5. When the browser asks for the microphone, tap **Allow**. If you tapped Block: lock icon in the address bar → Site settings → Microphone → Allow.

---

## PWA installation

- **Android Chrome:** open the site over HTTPS → menu ⋮ → *Install app* (or use the *Install app* button on the join page).
- **iPhone Safari:** Share button → *Add to Home Screen*.
- **Desktop Chrome / Edge:** install icon at the right of the address bar → *Install*.

PWAs need HTTPS (except `localhost`). The service worker caches only static files and an offline page — never `/signal` and never HTML pages.

---

## cPanel deployment

1. **Upload** the project (File Manager or FTP). Recommended layout: put the whole folder *above* `public_html`
   (for example `/home/USER/walkie-talkie/`) and make the **document root point to `walkie-talkie/public`**
   (cPanel → *Domains* → *Document Root*, or use a subdomain such as `radio.example.com` with that document root).
   If you cannot change the document root, upload into `public_html/walkie-talkie/` — the root `.htaccess` handles it.
2. **Configure `.env`**: copy `.env.example` to `.env`, set `APP_ENV=production`, `APP_DEBUG=false`, a strong `SIGNAL_SECRET`,
   and `APP_PUBLIC_URL=https://radio.example.com`. Leave `APP_BASE_PATH` empty (auto-detect) unless the app lives in a sub-folder you want to force.
3. **Enable rewrite**: `mod_rewrite` is normally on in cPanel; the included `.htaccess` files do the rest. Select **PHP 8.3** in *MultiPHP Manager*.
4. **Permissions**: `storage/` and its sub-folders (`rooms`, `logs`, `sessions`, `ratelimit`) must be writable by PHP (folders `755`/`775`, never `777` on shared hosting if avoidable).
5. **Enable HTTPS**: *SSL/TLS Status* → run AutoSSL (free Let's Encrypt), then optionally force HTTPS in `.htaccess`.
6. Open the site on two devices and test.

Shared hosting note: each browser makes about 1–2 requests per second. That is fine for small teams but check your host's limits.

---

## Configuration (`.env`)

| Key | Default | Meaning |
|---|---|---|
| `APP_ENV`, `APP_DEBUG` | `local`, `false` | Debug shows error messages on screen — keep `false` in production |
| `APP_BASE_PATH` | empty | URL folder, e.g. `/walkie-talkie`; empty = auto-detect |
| `APP_PUBLIC_URL` | empty | Address encoded in the QR code |
| `SIGNAL_SECRET` | auto | HMAC secret. Empty/`CHANGE_THIS_SECRET` → random secret saved to `storage/.secret` |
| `SIGNAL_MAX_PEERS` | `8` | Users per channel |
| `SIGNAL_FLOOR_TIMEOUT_MS` | `30000` | Maximum talk time per press |
| `SIGNAL_MAX_BODY` / `SIGNAL_MAX_DATA` | `32768` / `12288` | Request body / WebRTC data size limits (bytes) |
| `SIGNAL_TOKEN_TTL` | `3600` | Token lifetime in seconds (refreshed automatically while connected) |
| `SIGNAL_PEER_TIMEOUT_MS` | `20000` | A user who stops polling is removed after this time |
| `SIGNAL_POLL_INTERVAL_MS` | `800` | Poll interval (lower = snappier, more requests) |
| `RATE_LIMIT_PER_MINUTE` | `600` | Requests per peer per minute on `/signal` |
| `ICE_STUN_URLS` | Google STUN | Comma-separated STUN servers |
| `ICE_TURN_URL/USERNAME/CREDENTIAL` | empty | Optional TURN relay |

### STUN and TURN (important)

The app uses public **STUN** servers so browsers can discover their public addresses. **STUN alone does not guarantee a connection on every
network.** Strict corporate firewalls, some mobile carriers and symmetric NATs block direct peer-to-peer traffic. For reliable production use
you need a **TURN** relay (for example self-hosted [coturn](https://github.com/coturn/coturn) or a paid TURN service): set `ICE_TURN_URL`,
`ICE_TURN_USERNAME` and `ICE_TURN_CREDENTIAL` in `.env`. Everything lives in one place (`config/app.php`).

---

## Testing checklist

Run the automated server tests first:

```bash
php tests/selftest.php
```

It covers Tests 1–7 and 10 (join, 2 users, floor, busy, release, leave, channel full), token validation, the talk-time limit, signaling relay and rate limiting.

Then test in browsers (two tabs or two devices):

| # | Test | Expected |
|---|---|---|
| 1 | One user joins | `CONNECTED`, `1 USER` |
| 2 | Second user joins | `2 USERS`, both see each other |
| 3 | A holds PTT | A: `TRANSMITTING`, B: `Alex is talking`, B hears audio |
| 4 | B presses PTT while A talks | `CHANNEL BUSY` + "Alex is currently speaking" |
| 5 | A releases | B sees "Waiting for a speaker" |
| 6 | B presses PTT | B: `TRANSMITTING` |
| 7 | A clicks *Leave Channel* | A disappears from B's list within ~1 s |
| 8 | Stop Apache for ~10 s, start it again | `DISCONNECTED` → `RECONNECTING` → `CONNECTED` |
| 9 | Block the microphone | Friendly warning, page keeps working, you can still listen |
| 10 | Set `SIGNAL_MAX_PEERS=2`, join with a third user | "This channel is currently full." |

---

## Troubleshooting

| Problem | What to check |
|---|---|
| **Microphone unavailable** | Needs HTTPS or `localhost`. Check the browser's site permission and that no other app is using the mic. |
| **Channel full** | `SIGNAL_MAX_PEERS` reached. Idle users disappear after `SIGNAL_PEER_TIMEOUT_MS` (20 s). |
| **WebRTC connection failed / no sound** | Click *Tap to enable audio* (autoplay rules). Check the user list shows both people. Try the same Wi-Fi first; across different networks you probably need a **TURN** server. |
| **Signaling unavailable** | Open `/signal` in the browser: you should see a "Method not allowed" (405) page, not a 404. Check Apache `mod_rewrite`, `AllowOverride All`, and `storage/logs/`. |
| **Everything is 404 / no CSS** | `.htaccess` is not applied (rewrite disabled) or `APP_BASE_PATH` is wrong. Try leaving it empty. |
| **"Unable to connect to the communication server"** | `storage/` is not writable. See `storage/logs/app-YYYY-MM-DD.log`. |
| **PWA not installing** | Needs HTTPS (or localhost), a valid manifest and a registered service worker. Check DevTools → Application. |
| **Phone cannot connect** | Same Wi-Fi? Use the PC's LAN IP, not `localhost`. Windows Firewall must allow Apache. |
| **Hear an echo / feedback** | Two devices side by side with speakers on. Use headphones. |

---

## Security notes

- **Token**: HMAC-SHA256 signed, 1 hour by default, validated for signature, expiry, channel and peer ID on every call. Never trust client-supplied identity.
- **CSRF**: the join form uses a per-session token plus a same-origin check. `/signal` has no cookies; it is authenticated by the signed bearer token in the request body, so a foreign website cannot use it, and a same-origin check is applied as well.
- **Validation / escaping**: nickname ≤ 24 chars, channel ≤ 64 chars (`a-z 0-9 - _`). PHP escapes with `htmlspecialchars` (`e()`), JavaScript writes user text with `textContent` only.
- **Rate limiting** per IP (join, bad tokens) and per peer (`/signal`); **payload limits** on body and WebRTC data.
- **Files**: room files are named by a hash of the channel name (no path traversal). Private folders are blocked by `.htaccess`.
- **Headers**: Content-Security-Policy, X-Frame-Options, X-Content-Type-Options, Referrer-Policy, Permissions-Policy.
- **Not included in the basic version**: user accounts, passwords or private channels. Anyone who knows a channel name can join it. Use hard-to-guess names for private talks.

## Limitations (by design, to stay simple)

- HTTP polling means speaker changes appear on other screens after about 1 second. A Node.js WebSocket signaling server would make this instant (the `SignalingClient` class is the only piece you would replace).
- Audio uses a full mesh (each user connects to every other user), which is fine for 2–8 users.
- JSON files + `flock()` are great for learning and small teams. The `RoomService` / `Room` classes are the only code that touches storage, so a MySQL version can replace them later.

Voice only — there is no camera or video code in this project.
