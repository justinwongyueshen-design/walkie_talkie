<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;

/**
 * Tiny service factory: builds the service objects from the configuration.
 * (Kept deliberately simple - no dependency-injection container needed.)
 */
final class Services
{
    private static function storage(): string
    {
        return (string) Config::get('storage');
    }

    public static function tokens(): TokenService
    {
        $secret = TokenService::resolveSecret((string) Config::get('signal.secret', ''), self::storage());
        return new TokenService($secret, (int) Config::get('signal.token_ttl'));
    }

    public static function rooms(): RoomService
    {
        return new RoomService(
            self::storage() . '/rooms',
            (int) Config::get('signal.max_peers'),
            (int) Config::get('signal.floor_timeout_ms'),
            (int) Config::get('signal.peer_timeout_ms')
        );
    }

    public static function limiter(): RateLimiter
    {
        return new RateLimiter(self::storage() . '/ratelimit');
    }

    public static function signaling(): SignalingService
    {
        return new SignalingService(
            self::tokens(),
            self::rooms(),
            self::limiter(),
            [
                'max_data'              => (int) Config::get('signal.max_data'),
                'rate_limit_per_minute' => (int) Config::get('signal.rate_limit_per_minute'),
            ]
        );
    }

    public static function maintenance(): MaintenanceService
    {
        return new MaintenanceService(self::storage());
    }
}
