<?php
declare(strict_types=1);

/**
 * Command-line self test for the server side (no web server and no browser needed).
 *
 *   php tests/selftest.php
 *
 * It uses a temporary folder, so your real storage/ is not touched.
 * It covers: nickname/channel rules, signed tokens, the one-speaker-at-a-time
 * floor, speaker time limit, channel-full limit, signaling relay, leaving,
 * rate limiting and the JSON signaling API.
 */

if (PHP_SAPI !== 'cli') {
    exit("Run this file from the command line: php tests/selftest.php\n");
}

define('WT_ROOT', dirname(__DIR__));
spl_autoload_register(static function (string $class): void {
    if (strncmp($class, 'App\\', 4) === 0) {
        $file = WT_ROOT . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

use App\Controllers\JoinController;
use App\Services\GuestService;
use App\Services\RateLimiter;
use App\Services\RoomService;
use App\Services\SignalingService;
use App\Services\TokenException;
use App\Services\TokenService;

$passed = 0;
$failed = 0;

function check(string $name, bool $condition): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  [ OK ] $name\n";
    } else {
        $failed++;
        echo "  [FAIL] $name\n";
    }
}

function rmTree(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) ?: [] as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . '/' . $item;
        is_dir($path) ? rmTree($path) : @unlink($path);
    }
    @rmdir($dir);
}

$tmp = sys_get_temp_dir() . '/wt-selftest-' . bin2hex(random_bytes(4));
mkdir($tmp . '/rooms', 0775, true);
mkdir($tmp . '/ratelimit', 0775, true);

echo "\nInput rules\n";
check('nickname is trimmed and cleaned', GuestService::sanitizeNickname("  Alex <b>  ") === 'Alex b');
check('empty nickname is rejected', GuestService::sanitizeNickname("  <>  ") === null);
check('nickname limited to 24 characters', mb_strlen((string) GuestService::sanitizeNickname(str_repeat('a', 40))) === 24);
check('channel: spaces -> dashes, lowercase', GuestService::normalizeChannel('Team A Room') === 'team-a-room');
check('channel: dangerous characters removed', GuestService::normalizeChannel('../../etc/passwd') === 'etcpasswd');
check('channel: empty is rejected', GuestService::normalizeChannel(' /// ') === null);
check('channel limited to 64 characters', strlen((string) GuestService::normalizeChannel(str_repeat('x', 100))) === 64);

$serverAddr = $_SERVER['SERVER_ADDR'] ?? null;
$_SERVER['SERVER_ADDR'] = '192.168.1.20';
check('public QR URL prefers LAN IP over localhost', JoinController::resolvePublicUrl('http', 'localhost:8000', '/walkie-talkie') === 'http://192.168.1.20:8000/walkie-talkie');
if ($serverAddr === null) {
    unset($_SERVER['SERVER_ADDR']);
} else {
    $_SERVER['SERVER_ADDR'] = $serverAddr;
}

echo "\nTokens (HMAC-SHA256)\n";
$tokens = new TokenService('unit-test-secret-unit-test-secret', 3600);
$pidA = GuestService::newPeerId();
$pidB = GuestService::newPeerId();
$tokenA = $tokens->issue($pidA, 'Alex', 'room-101');
$claims = $tokens->verify($tokenA);
check('valid token verifies', $claims['pid'] === $pidA && $claims['nick'] === 'Alex' && $claims['ch'] === 'room-101');

$thrown = '';
try { $tokens->verify(substr($tokenA, 0, -2) . 'xx'); } catch (TokenException $e) { $thrown = $e->getMessage(); }
check('tampered token is rejected', $thrown === 'invalid_token');

[$body] = explode('.', $tokenA);
$forged = $body . '.' . rtrim(strtr(base64_encode(hash_hmac('sha256', $body, 'wrong-secret', true)), '+/', '-_'), '=');
$thrown = '';
try { $tokens->verify($forged); } catch (TokenException $e) { $thrown = $e->getMessage(); }
check('token signed with another secret is rejected', $thrown === 'invalid_token');

$expired = (new TokenService('unit-test-secret-unit-test-secret', -5))->issue($pidA, 'Alex', 'room-101');
$thrown = '';
try { $tokens->verify($expired); } catch (TokenException $e) { $thrown = $e->getMessage(); }
check('expired token is rejected', $thrown === 'expired');

echo "\nRooms and the speaking floor\n";
$rooms = new RoomService($tmp . '/rooms', 2, 400, 20000); // max 2 users, 0.4 s talk limit
$j1 = $rooms->join('room-101', $pidA, 'Alex');
check('Test 1: first user joins -> 1 user', $j1['ok'] === true && $j1['state']['count'] === 1);
$j2 = $rooms->join('room-101', $pidB, 'Mei');
check('Test 2: second user joins -> 2 users', $j2['ok'] === true && $j2['state']['count'] === 2);
$pidC = GuestService::newPeerId();
$j3 = $rooms->join('room-101', $pidC, 'Third');
check('Test 10: third user -> channel is full', $j3['ok'] === false && $j3['error'] === 'room_full');
check('countActive reports 2', $rooms->countActive('room-101') === 2);

$r = $rooms->requestFloor('room-101', $pidA);
check('Test 3: A gets the floor', $r['granted'] === true);
$r = $rooms->requestFloor('room-101', $pidB);
check('Test 4: B is told the channel is busy', $r['granted'] === false && $r['reason'] === 'busy' && $r['speaker']['nick'] === 'Alex');
$snap = $rooms->poll('room-101', $pidB, 0);
check('B sees Alex as the speaker', $snap !== null && $snap['state']['speaker']['pid'] === $pidA);
$rooms->releaseFloor('room-101', $pidA);
$snap = $rooms->poll('room-101', $pidB, 0);
check('Test 5: after release the channel is free', $snap['state']['speaker'] === null);
$r = $rooms->requestFloor('room-101', $pidB);
check('Test 6: B now gets the floor', $r['granted'] === true);

usleep(550000); // longer than the 0.4 s limit
$snap = $rooms->poll('room-101', $pidA, 0);
check('talk time limit frees the floor automatically', $snap['state']['speaker'] === null);
$timeoutEvents = array_filter($snap['events'], static fn(array $e): bool => $e['type'] === 'speaker_stop' && ($e['reason'] ?? '') === 'timeout');
check('a speaker_stop (timeout) event was emitted', count($timeoutEvents) === 1);

echo "\nWebRTC message relay\n";
$since = $rooms->poll('room-101', $pidB, 0)['seq'];
$rooms->relay('room-101', $pidA, $pidB, ['kind' => 'offer', 'sdp' => 'v=0...']);
$forB = $rooms->poll('room-101', $pidB, $since)['events'];
$forA = $rooms->poll('room-101', $pidA, $since)['events'];
check('B receives the offer from A', count($forB) === 1 && $forB[0]['type'] === 'signal' && $forB[0]['from'] === $pidA);
check('A does not receive a message meant for B', count($forA) === 0);
$res = $rooms->relay('room-101', $pidA, 'pdeadbeefdeadbe', ['kind' => 'offer', 'sdp' => 'x']);
check('relay to an unknown user fails', isset($res['error']) && $res['error'] === 'unknown_peer');

echo "\nLeaving\n";
$rooms->requestFloor('room-101', $pidA);
$rooms->leave('room-101', $pidA);
$snap = $rooms->poll('room-101', $pidB, 0);
check('Test 7: user removed from the participant list', $snap['state']['count'] === 1 && $snap['state']['peers'][0]['pid'] === $pidB);
check('leaving also releases the floor', $snap['state']['speaker'] === null);
check('a user who left must rejoin (poll returns null)', $rooms->poll('room-101', $pidA, 0) === null);

echo "\nRate limiter\n";
$limiter = new RateLimiter($tmp . '/ratelimit');
$allowed = 0;
for ($i = 0; $i < 7; $i++) {
    if ($limiter->hit('test-key', 5)['allowed']) {
        $allowed++;
    }
}
check('only 5 of 7 rapid requests are allowed', $allowed === 5);

echo "\nSignaling API (what POST /signal does)\n";
$rooms2 = new RoomService($tmp . '/rooms', 8, 30000, 20000);
$api = new SignalingService($tokens, $rooms2, new RateLimiter($tmp . '/ratelimit'), ['max_data' => 2000, 'rate_limit_per_minute' => 50]);
$tA = $tokens->issue($pidA, 'Alex', 'api-room');
$tB = $tokens->issue($pidB, 'Mei', 'api-room');

[$code, $out] = $api->handle(['type' => 'hello', 'token' => $tA], '127.0.0.1');
check('hello works', $code === 200 && $out['ok'] === true && $out['state']['count'] === 1);
[$code, $out] = $api->handle(['type' => 'hello', 'token' => $tB], '127.0.0.1');
check('second hello sees 2 users', $code === 200 && $out['state']['count'] === 2);
[$code, $out] = $api->handle(['type' => 'ptt_request', 'token' => $tA], '127.0.0.1');
check('ptt_request granted', $out['granted'] === true);
[$code, $out] = $api->handle(['type' => 'ptt_request', 'token' => $tB], '127.0.0.1');
check('ptt_request denied while busy', $out['granted'] === false);
[$code, $out] = $api->handle(['type' => 'signal', 'token' => $tA, 'to' => $pidB, 'data' => ['kind' => 'offer', 'sdp' => 'abc']], '127.0.0.1');
check('signal relay accepted', $code === 200 && $out['ok'] === true);
[$code, $out] = $api->handle(['type' => 'signal', 'token' => $tA, 'to' => $pidB, 'data' => ['kind' => 'offer', 'sdp' => str_repeat('A', 5000)]], '127.0.0.1');
check('oversized signaling data is rejected (413)', $code === 413);
[$code, $out] = $api->handle(['type' => 'signal', 'token' => $tA, 'to' => $pidB, 'data' => ['kind' => 'evil', 'sdp' => 'x']], '127.0.0.1');
check('unknown signal kind is rejected', $code === 400);
[$code, $out] = $api->handle(['type' => 'poll', 'token' => 'garbage', 'since' => 0], '127.0.0.1');
check('garbage token -> 401', $code === 401 && $out['error'] === 'invalid_token');
[$code, $out] = $api->handle(['type' => 'poll', 'token' => $expired, 'since' => 0], '127.0.0.1');
check('expired token -> 401 expired', $code === 401 && $out['error'] === 'expired');
[$code, $out] = $api->handle(['type' => 'nope', 'token' => $tA], '127.0.0.1');
check('unknown message type -> 400', $code === 400);

$tOther = $tokens->issue(GuestService::newPeerId(), 'Spammer', 'api-room');
$last = 200;
for ($i = 0; $i < 60; $i++) {
    [$last] = $api->handle(['type' => 'poll', 'token' => $tOther, 'since' => 0], '127.0.0.1');
}
check('rate limit kicks in (429)', $last === 429);

[$code, $out] = $api->handle(['type' => 'leave', 'token' => $tA], '127.0.0.1');
check('leave works', $code === 200 && $out['ok'] === true);

rmTree($tmp);
echo "\nResult: $passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
