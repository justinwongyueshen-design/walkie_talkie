<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Validation and normalisation of guest input (nickname, channel) and ID generation.
 */
final class GuestService
{
    public const MAX_NICKNAME = 24;
    public const MAX_CHANNEL = 64;

    /** Clean a nickname. Returns null when nothing usable is left. */
    public static function sanitizeNickname(string $raw): ?string
    {
        // Letters, numbers, space, underscore, dash and dot only (any language).
        $clean = preg_replace('/[^\p{L}\p{N} _.\-]/u', '', $raw);
        if ($clean === null) {
            return null;
        }
        $clean = preg_replace('/\s+/u', ' ', $clean) ?? '';
        $clean = trim($clean);
        $clean = trim(mb_substr($clean, 0, self::MAX_NICKNAME, 'UTF-8'));
        return $clean === '' ? null : $clean;
    }

    /**
     * Turn any text into a safe channel name: lowercase a-z, 0-9, "-" and "_".
     * Spaces become "-". Returns null when the result would be empty.
     */
    public static function normalizeChannel(string $raw): ?string
    {
        $name = mb_strtolower(trim($raw), 'UTF-8');
        $name = preg_replace('/\s+/u', '-', $name) ?? '';
        $name = preg_replace('/[^a-z0-9_\-]/', '', $name) ?? '';
        $name = preg_replace('/-{2,}/', '-', $name) ?? '';
        $name = trim(substr($name, 0, self::MAX_CHANNEL), '-_');
        return $name === '' ? null : $name;
    }

    /** Peer ID: starts with a letter so it is never mistaken for a number by PHP arrays. */
    public static function newPeerId(): string
    {
        return 'p' . bin2hex(random_bytes(7));
    }

    public static function isValidPeerId(string $pid): bool
    {
        return (bool) preg_match('/^p[a-f0-9]{14}$/', $pid);
    }

    public static function newGuestId(): string
    {
        return 'guest-' . bin2hex(random_bytes(3));
    }
}
