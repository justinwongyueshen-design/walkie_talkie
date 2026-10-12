<?php
declare(strict_types=1);

use App\Core\Env;

/**
 * Central application configuration. Every value that may differ between
 * machines comes from the .env file.
 */

// STUN/TURN servers used by WebRTC. Kept in one place on purpose.
$stunUrls = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) Env::get('ICE_STUN_URLS', 'stun:stun.l.google.com:19302,stun:stun1.l.google.com:19302'))
)));

$iceServers = [['urls' => $stunUrls]];

// Optional TURN relay (needed on restrictive networks). See README.
if (Env::get('ICE_TURN_URL', '') !== '') {
    $iceServers[] = [
        'urls'       => Env::get('ICE_TURN_URL'),
        'username'   => Env::get('ICE_TURN_USERNAME', ''),
        'credential' => Env::get('ICE_TURN_CREDENTIAL', ''),
    ];
}

return [
    'name'       => 'Walkie Talkie',
    'env'        => Env::get('APP_ENV', 'local'),
    'debug'      => Env::bool('APP_DEBUG', false),
    'base_path'  => rtrim((string) Env::get('APP_BASE_PATH', ''), '/'),
    'public_url' => rtrim((string) Env::get('APP_PUBLIC_URL', ''), '/'),
    'storage'    => WT_ROOT . '/storage',

    'signal' => [
        'secret'                => (string) Env::get('SIGNAL_SECRET', ''),
        'max_peers'             => max(2, Env::int('SIGNAL_MAX_PEERS', 8)),
        'floor_timeout_ms'      => max(3000, Env::int('SIGNAL_FLOOR_TIMEOUT_MS', 30000)),
        'max_body'              => max(1024, Env::int('SIGNAL_MAX_BODY', 32768)),
        'max_data'              => max(512, Env::int('SIGNAL_MAX_DATA', 12288)),
        'token_ttl'             => max(120, Env::int('SIGNAL_TOKEN_TTL', 3600)),
        'peer_timeout_ms'       => max(5000, Env::int('SIGNAL_PEER_TIMEOUT_MS', 20000)),
        'poll_interval_ms'      => max(300, Env::int('SIGNAL_POLL_INTERVAL_MS', 800)),
        'rate_limit_per_minute' => max(60, Env::int('RATE_LIMIT_PER_MINUTE', 600)),
    ],

    'ice_servers' => $iceServers,
];
