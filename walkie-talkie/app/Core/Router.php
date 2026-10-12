<?php
declare(strict_types=1);

namespace App\Core;

use App\Middleware\CsrfMiddleware;
use App\Middleware\ThrottleMiddleware;

/**
 * Matches the request path against the route table and calls the controller.
 */
final class Router
{
    /** @param array<int,array{0:string,1:string,2:array{0:class-string,1:string},3:array<int,string>}> $routes */
    public function __construct(private array $routes)
    {
    }

    public function dispatch(Request $request): Response
    {
        $pathMatched = false;

        foreach ($this->routes as [$method, $pattern, $handler, $middleware]) {
            $regex = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $pattern) . '$#';
            if (!preg_match($regex, $request->path, $matches)) {
                continue;
            }
            if ($method !== $request->method) {
                $pathMatched = true;
                continue;
            }
            foreach ($matches as $key => $value) {
                if (is_string($key)) {
                    $request->routeParams[$key] = (string) $value;
                }
            }
            foreach ($middleware as $name) {
                $early = $this->runMiddleware($name, $request);
                if ($early !== null) {
                    return $early;
                }
            }
            [$class, $action] = $handler;
            return (new $class())->$action($request);
        }

        return $pathMatched ? self::errorPage(405) : self::errorPage(404);
    }

    private function runMiddleware(string $spec, Request $request): ?Response
    {
        [$name, $arg] = array_pad(explode(':', $spec, 2), 2, '');
        return match ($name) {
            'csrf'     => (new CsrfMiddleware())->handle($request),
            'throttle' => (new ThrottleMiddleware((int) $arg ?: 30))->handle($request),
            default    => null,
        };
    }

    /** Friendly HTML error page (no technical details). */
    public static function errorPage(int $status, string $message = ''): Response
    {
        $titles = [
            403 => 'Access denied',
            404 => 'Page not found',
            405 => 'Method not allowed',
            419 => 'Session expired',
            429 => 'Too many requests',
            500 => 'Something went wrong',
        ];
        $defaults = [
            404 => 'The page you are looking for does not exist.',
            405 => 'This action is not allowed here.',
            419 => 'Your page session has expired. Please go back and try again.',
            429 => 'Too many requests. Please wait a moment and try again.',
            500 => 'Something went wrong on our side. Please try again later.',
        ];
        $html = View::render('errors/error', [
            'title'   => $titles[$status] ?? 'Error',
            'status'  => $status,
            'message' => $message !== '' ? $message : ($defaults[$status] ?? 'An error occurred.'),
        ]);
        return Response::html($html, $status);
    }
}
