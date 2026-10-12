<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Application kernel: loads configuration, starts the session and runs the router.
 */
final class App
{
    public function __construct(private string $root)
    {
    }

    public function run(): void
    {
        Env::load($this->root . '/.env');
        /** @var array<string,mixed> $config */
        $config = require $this->root . '/config/app.php';
        Config::load($config);

        $debug = (bool) Config::get('debug', false);
        ini_set('display_errors', $debug ? '1' : '0');
        error_reporting(E_ALL);

        $basePath = (string) Config::get('base_path', '');
        if ($basePath === '') {
            $basePath = $this->detectBasePath();
            Config::set('base_path', $basePath);
        } elseif ($basePath[0] !== '/') {
            $basePath = '/' . $basePath;
            Config::set('base_path', $basePath);
        }

        $request = Request::capture($basePath);
        $isSignal = $request->path === '/signal';

        $publicFile = $this->publicFileResponse($request->path);
        if ($publicFile !== null) {
            $publicFile->send();
            return;
        }

        try {
            // The signaling endpoint is authenticated by a signed token, so it needs no session.
            if (!$isSignal) {
                Session::start($request->isSecure());
            }

            /** @var array<int,mixed> $routes */
            $routes = require $this->root . '/config/routes.php';
            $response = (new Router($routes))->dispatch($request);
        } catch (\Throwable $e) {
            Logger::error('Unhandled exception on ' . $request->method . ' ' . $request->path, $e);
            if ($isSignal) {
                $response = Response::json([
                    'ok'      => false,
                    'error'   => 'server_error',
                    'message' => 'Unable to connect to the communication server.',
                ], 500);
            } else {
                $response = Router::errorPage(500, $debug ? $e->getMessage() : '');
            }
        }

        $response->send();
    }

    /** Work out the URL prefix from where index.php lives relative to the web root. */
    private function detectBasePath(): string
    {
        $docRoot = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
        $script = realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));
        if ($docRoot === false || $script === false) {
            return '';
        }
        $docRoot = rtrim(str_replace('\\', '/', $docRoot), '/');
        $dir = str_replace('\\', '/', dirname($script));
        if ($docRoot === '' || strpos($dir, $docRoot) !== 0) {
            return '';
        }
        $relative = substr($dir, strlen($docRoot));
        if (substr($relative, -7) === '/public') {
            $relative = substr($relative, 0, -7);
        }
        return rtrim($relative, '/');
    }

    /** Serve static public files when the app is run from the project root without Apache rewrites. */
    private function publicFileResponse(string $path): ?Response
    {
        $publicRoot = realpath($this->root . '/public');
        if ($publicRoot === false) {
            return null;
        }

        $allowed = ['/sw.js', '/manifest.webmanifest', '/offline.html'];
        if (strncmp($path, '/assets/', 8) === 0) {
            $relative = ltrim($path, '/');
        } elseif (in_array($path, $allowed, true)) {
            $relative = ltrim($path, '/');
        } else {
            return null;
        }

        $target = $publicRoot . '/' . ltrim($relative, '/');
        $resolved = realpath($target);
        if ($resolved === false || $resolved === $publicRoot || strpos($resolved, $publicRoot . DIRECTORY_SEPARATOR) !== 0) {
            return Response::html('Not found', 404);
        }

        $contents = @file_get_contents($resolved);
        if ($contents === false) {
            return Response::html('Not found', 404);
        }

        $extension = strtolower(pathinfo($resolved, PATHINFO_EXTENSION));
        $mime = match ($extension) {
            'css' => 'text/css; charset=UTF-8',
            'js' => 'application/javascript; charset=UTF-8',
            'html' => 'text/html; charset=UTF-8',
            'json' => 'application/json; charset=UTF-8',
            'webmanifest' => 'application/manifest+json; charset=UTF-8',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'svg' => 'image/svg+xml',
            'ico' => 'image/x-icon',
            'woff2' => 'font/woff2',
            default => mime_content_type($resolved) ?: 'application/octet-stream',
        };

        return (new Response($contents, 200))
            ->header('Content-Type', $mime)
            ->header('Cache-Control', 'public, max-age=86400');
    }
}
