<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Services\GuestService;
use App\Services\Services;

/** Landing page and the "Join Channel" form. */
final class JoinController
{
    public function show(Request $request): Response
    {
        Services::maintenance()->runSometimes();

        // The channel can be pre-filled from a shared link: /?channel=security
        $channel = GuestService::normalizeChannel($request->input('channel'))
            ?? (string) Session::get('last_channel', '');

        $publicUrl = (string) Config::get('public_url', '');
        if ($publicUrl === '') {
            $publicUrl = self::resolvePublicUrl(
                $request->isSecure() ? 'https' : 'http',
                $request->host(),
                url('/')
            );
        } else {
            $publicUrl = rtrim($publicUrl, '/') . '/';
        }

        $html = View::render('join', [
            'title'     => 'Join a channel',
            'csrf'      => Session::csrfToken(),
            'nickname'  => (string) Session::get('nickname', ''),
            'channel'   => $channel,
            'error'     => Session::pull('flash_error'),
            'publicUrl' => $publicUrl,
            'maxPeers'  => (int) Config::get('signal.max_peers'),
            'scripts'   => ['qr.js', 'join.js'],
            'bodyClass' => 'wt-join-page',
        ]);
        return Response::html($html)->header('Cache-Control', 'no-store');
    }

    public function join(Request $request): Response
    {
        $nickname = GuestService::sanitizeNickname($request->input('nickname'));
        $channel = GuestService::normalizeChannel($request->input('channel'));

        if ($nickname === null) {
            return $this->back('Please enter a nickname (letters and numbers, up to ' . GuestService::MAX_NICKNAME . ' characters).', $request);
        }
        if ($channel === null) {
            Session::set('nickname', $nickname);
            return $this->back('Please enter a channel name (letters, numbers, - and _).', $request);
        }

        Session::set('nickname', $nickname);
        Session::set('last_channel', $channel);
        if (!is_string(Session::get('guest_id'))) {
            Session::set('guest_id', GuestService::newGuestId());
        }

        $max = (int) Config::get('signal.max_peers');
        if (Services::rooms()->countActive($channel) >= $max) {
            return $this->back('This channel is currently full. Maximum ' . $max . ' users are allowed.', $request);
        }

        return Response::redirect(url('/channel/' . rawurlencode($channel)));
    }

    public static function resolvePublicUrl(string $scheme, string $host, string $basePath): string
    {
        $host = trim($host);
        if ($host === '') {
            $host = 'localhost';
        }

        $basePath = rtrim((string) $basePath, '/');
        $basePath = $basePath === '' ? '' : $basePath;

        $parsed = parse_url('http://' . $host);
        $hostname = strtolower((string) ($parsed['host'] ?? $host));
        $port = isset($parsed['port']) ? ':' . $parsed['port'] : '';

        if (!self::isLoopbackHost($hostname)) {
            return $scheme . '://' . $hostname . $port . $basePath . '/';
        }

        $lanIp = self::detectLanIp();
        if ($lanIp !== null) {
            return $scheme . '://' . $lanIp . $port . $basePath . '/';
        }

        return $scheme . '://' . $hostname . $port . $basePath . '/';
    }

    private static function isLoopbackHost(string $host): bool
    {
        $host = strtolower($host);
        return $host === 'localhost'
            || $host === '127.0.0.1'
            || $host === '::1'
            || $host === '[::1]';
    }

    private static function detectLanIp(): ?string
    {
        $candidates = [];
        foreach (['SERVER_ADDR', 'LOCAL_ADDR', 'REMOTE_ADDR'] as $key) {
            $value = $_SERVER[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                $candidates[] = $value;
            }
        }

        foreach ($candidates as $candidate) {
            $ip = self::normalizeIpv4($candidate);
            if ($ip !== null && !self::isLoopbackHost($ip)) {
                return $ip;
            }
        }

        $output = '';
        if (DIRECTORY_SEPARATOR === '\\') {
            $output = (string) @shell_exec('ipconfig 2>NUL');
            if (trim($output) === '') {
                $output = (string) @shell_exec('wmic nicconfig where IPEnabled=true get IPAddress 2>NUL');
            }
        } else {
            $output = (string) @shell_exec('hostname -I 2>/dev/null || ip addr show scope global 2>/dev/null | awk \'/inet /{print $2}\' | cut -d/ -f1');
        }

        foreach (preg_split('/\s+/', trim($output)) ?: [] as $item) {
            $item = trim($item, " \t\n\r\0\x0B,\"'");
            $item = preg_replace('/^\[(.*)\]$/', '$1', $item);
            $ip = self::normalizeIpv4((string) $item);
            if ($ip !== null && !self::isLoopbackHost($ip)) {
                return $ip;
            }
        }

        return null;
    }

    private static function normalizeIpv4(string $address): ?string
    {
        $address = trim($address);
        if ($address === '') {
            return null;
        }

        $host = $address;
        if (str_contains($host, ':') && !str_contains($host, '.')) {
            return null;
        }

        $parsed = parse_url('http://' . $host);
        $candidate = strtolower((string) ($parsed['host'] ?? $host));
        $candidate = trim($candidate, '[]');

        if (filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return null;
        }

        if (self::isLoopbackHost($candidate)) {
            return null;
        }

        return $candidate;
    }

    private function back(string $message, Request $request): Response
    {
        Session::set('flash_error', $message);
        return Response::redirect(url('/'));
    }
}
