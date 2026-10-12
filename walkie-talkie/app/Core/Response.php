<?php
declare(strict_types=1);

namespace App\Core;

/**
 * An HTTP response: status code, headers and body.
 */
final class Response
{
    /** @var array<string,string> */
    private array $headers = [];

    public function __construct(private string $body = '', private int $status = 200)
    {
    }

    public static function html(string $html, int $status = 200): self
    {
        return (new self($html, $status))->header('Content-Type', 'text/html; charset=UTF-8');
    }

    /** @param array<string,mixed> $data */
    public static function json(array $data, int $status = 200): self
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        return (new self($json === false ? '{"ok":false}' : $json, $status))
            ->header('Content-Type', 'application/json; charset=UTF-8')
            ->header('Cache-Control', 'no-store');
    }

    public static function redirect(string $location, int $status = 302): self
    {
        return (new self('', $status))->header('Location', $location);
    }

    public function header(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->securityHeaders() as $name => $value) {
                if (!isset($this->headers[$name])) {
                    header($name . ': ' . $value);
                }
            }
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value);
            }
        }
        echo $this->body;
    }

    /** @return array<string,string> */
    private function securityHeaders(): array
    {
        $csp = "default-src 'self'; "
            . "script-src 'self' https://cdn.jsdelivr.net; "
            . "style-src 'self' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; "
            . "font-src 'self' https://cdnjs.cloudflare.com; "
            . "img-src 'self' data:; "
            . "media-src 'self' blob:; "
            . "connect-src 'self'; "
            . "manifest-src 'self'; worker-src 'self'; "
            . "object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'";

        return [
            'X-Content-Type-Options'  => 'nosniff',
            'X-Frame-Options'         => 'DENY',
            'Referrer-Policy'         => 'same-origin',
            'Permissions-Policy'      => 'microphone=(self), camera=(), geolocation=()',
            'Content-Security-Policy' => $csp,
        ];
    }
}
