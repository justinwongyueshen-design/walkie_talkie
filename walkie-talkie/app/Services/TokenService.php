<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Short-lived signed tokens (HMAC-SHA256).
 *
 *   token = base64url(payload-json) . "." . base64url(hmac_sha256(payload, secret))
 *
 * The payload holds: pid (peer id), nick, ch (channel), iat, exp.
 * The secret never leaves the server, so a client cannot forge or change a token.
 */
final class TokenService
{
    private string $secret;

    public function __construct(string $secret, private int $ttlSeconds)
    {
        $this->secret = $secret;
    }

    /**
     * Use SIGNAL_SECRET from .env. When it is empty or still the placeholder,
     * create a random one once and keep it in storage/.secret.
     */
    public static function resolveSecret(string $configured, string $storageDir): string
    {
        if ($configured !== '' && $configured !== 'CHANGE_THIS_SECRET') {
            return $configured;
        }
        $file = $storageDir . '/.secret';
        if (is_file($file)) {
            $existing = trim((string) file_get_contents($file));
            if (strlen($existing) >= 32) {
                return $existing;
            }
        }
        if (!is_dir($storageDir)) {
            @mkdir($storageDir, 0775, true);
        }
        $secret = bin2hex(random_bytes(32));
        @file_put_contents($file, $secret, LOCK_EX);
        @chmod($file, 0600);
        return $secret;
    }

    public function issue(string $pid, string $nick, string $channel): string
    {
        $now = time();
        $payload = json_encode([
            'pid' => $pid,
            'nick' => $nick,
            'ch'  => $channel,
            'iat' => $now,
            'exp' => $now + $this->ttlSeconds,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $body = self::b64urlEncode((string) $payload);
        return $body . '.' . self::b64urlEncode(hash_hmac('sha256', $body, $this->secret, true));
    }

    /**
     * Check signature, expiry and payload shape.
     *
     * @return array{pid:string,nick:string,ch:string,iat:int,exp:int}
     * @throws TokenException message "invalid_token" or "expired"
     */
    public function verify(string $token): array
    {
        if ($token === '' || strlen($token) > 1024 || substr_count($token, '.') !== 1) {
            throw new TokenException('invalid_token');
        }
        [$body, $sig] = explode('.', $token, 2);

        $expected = self::b64urlEncode(hash_hmac('sha256', $body, $this->secret, true));
        if (!hash_equals($expected, $sig)) {
            throw new TokenException('invalid_token');
        }

        $payload = json_decode((string) self::b64urlDecode($body), true);
        if (!is_array($payload)
            || !isset($payload['pid'], $payload['nick'], $payload['ch'], $payload['exp'], $payload['iat'])
            || !is_string($payload['pid']) || !is_string($payload['nick']) || !is_string($payload['ch'])
            || !is_int($payload['exp']) || !is_int($payload['iat'])
        ) {
            throw new TokenException('invalid_token');
        }
        if (!GuestService::isValidPeerId($payload['pid'])
            || GuestService::sanitizeNickname($payload['nick']) !== $payload['nick']
            || GuestService::normalizeChannel($payload['ch']) !== $payload['ch']
        ) {
            throw new TokenException('invalid_token');
        }
        if ($payload['exp'] < time()) {
            throw new TokenException('expired');
        }
        return $payload;
    }

    /** True when less than a third of the lifetime is left (clients get a fresh token). */
    public function needsRefresh(array $claims): bool
    {
        return ((int) $claims['exp'] - time()) < intdiv($this->ttlSeconds, 3);
    }

    private static function b64urlEncode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function b64urlDecode(string $text): string
    {
        $decoded = base64_decode(strtr($text, '-_', '+/'), true);
        return $decoded === false ? '' : $decoded;
    }
}
