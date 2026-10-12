<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Small wrapper around the PHP request globals.
 */
final class Request
{
    /** @var array<string,string> */
    public array $routeParams = [];

    /**
     * @param array<string,mixed> $query
     * @param array<string,mixed> $post
     * @param array<string,mixed> $server
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        private array $query,
        private array $post,
        private array $server
    ) {
    }

    /** Build a Request from PHP globals, removing the base path (and "/public") from the URL. */
    public static function capture(string $basePath): self
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($method === 'HEAD') {
            $method = 'GET';
        }
        $uriPath = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        $path = rawurldecode($uriPath);

        if ($basePath !== '' && ($path === $basePath || strncmp($path, $basePath . '/', strlen($basePath) + 1) === 0)) {
            $path = substr($path, strlen($basePath));
        }
        // Allow opening http://host/walkie-talkie/public/ directly.
        if ($path === '/public' || strncmp($path, '/public/', 8) === 0) {
            $path = substr($path, 7);
        }
        $path = '/' . ltrim($path, '/');
        if (strlen($path) > 1) {
            $path = rtrim($path, '/');
        }
        if ($path === '/index.php') {
            $path = '/';
        }

        return new self($method, $path, $_GET, $_POST, $_SERVER);
    }

    /** String input from POST (preferred) or query string. Non-string values are ignored. */
    public function input(string $key, string $default = ''): string
    {
        $value = $this->post[$key] ?? $this->query[$key] ?? $default;
        return is_string($value) ? $value : $default;
    }

    public function param(string $key, string $default = ''): string
    {
        return $this->routeParams[$key] ?? $default;
    }

    public function ip(): string
    {
        return (string) ($this->server['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    public function isSecure(): bool
    {
        $https = $this->server['HTTPS'] ?? '';
        if ($https !== '' && strtolower((string) $https) !== 'off') {
            return true;
        }
        return strtolower((string) ($this->server['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    }

    public function host(): string
    {
        return (string) ($this->server['HTTP_HOST'] ?? 'localhost');
    }

    public function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return isset($this->server[$key]) ? (string) $this->server[$key] : null;
    }

    /**
     * Read the raw request body, but never more than $maxBytes.
     * Returns null when the body is larger than the limit.
     */
    public function rawBody(int $maxBytes): ?string
    {
        $length = (int) ($this->server['CONTENT_LENGTH'] ?? 0);
        if ($length > $maxBytes) {
            return null;
        }
        $handle = fopen('php://input', 'rb');
        if ($handle === false) {
            return '';
        }
        $data = stream_get_contents($handle, $maxBytes + 1);
        fclose($handle);
        if ($data === false) {
            return '';
        }
        return strlen($data) > $maxBytes ? null : $data;
    }

    /** True when an Origin header is absent, or points at this same host. */
    public function isSameOrigin(): bool
    {
        $origin = $this->header('Origin');
        if ($origin === null || $origin === '' || $origin === 'null') {
            return $origin === null || $origin === '';
        }
        $originHost = (string) parse_url($origin, PHP_URL_HOST);
        $originPort = parse_url($origin, PHP_URL_PORT);
        if ($originPort !== null && $originPort !== false) {
            $originHost .= ':' . $originPort;
        }
        return strcasecmp($originHost, $this->host()) === 0;
    }
}
