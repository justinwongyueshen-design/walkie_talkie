<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Services\RateLimiter;

/** Limits how many requests one IP address may make per minute to a route. */
final class ThrottleMiddleware
{
    public function __construct(private int $perMinute)
    {
    }

    public function handle(Request $request): ?Response
    {
        $limiter = new RateLimiter((string) Config::get('storage') . '/ratelimit');
        $result = $limiter->hit('route:' . $request->path . ':' . $request->ip(), $this->perMinute);
        if (!$result['allowed']) {
            return Router::errorPage(429)->header('Retry-After', (string) $result['retry_after']);
        }
        return null;
    }
}
