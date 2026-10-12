<?php
declare(strict_types=1);

use App\Core\Config;

/** Escape a value for safe output inside HTML. */
function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Read a configuration value using dot notation, e.g. config('signal.max_peers'). */
function config(string $key, mixed $default = null): mixed
{
    return Config::get($key, $default);
}

/** URL path prefix of the application ("" or "/walkie-talkie"). */
function base_path(): string
{
    return (string) Config::get('base_path', '');
}

/** Build an application URL (path only) such as url('/join'). */
function url(string $path = '/'): string
{
    return base_path() . '/' . ltrim($path, '/');
}

/** URL of a static asset, with a cache-busting version taken from the file time. */
function asset(string $path): string
{
    $file = WT_ROOT . '/public/assets/' . ltrim($path, '/');
    $version = is_file($file) ? (string) filemtime($file) : '1';
    return url('/assets/' . ltrim($path, '/')) . '?v=' . $version;
}

/** JSON that is safe to embed inside a <script type="application/json"> tag. */
function json_for_html(mixed $data): string
{
    return (string) json_encode(
        $data,
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
}
