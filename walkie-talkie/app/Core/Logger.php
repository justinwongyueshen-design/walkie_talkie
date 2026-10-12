<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Writes technical errors to storage/logs/ (never shown to visitors).
 */
final class Logger
{
    public static function error(string $message, ?\Throwable $e = null): void
    {
        $dir = (string) Config::get('storage', WT_ROOT . '/storage') . '/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $line = '[' . date('Y-m-d H:i:s') . '] ' . $message;
        if ($e !== null) {
            $line .= ' | ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine();
        }
        @file_put_contents($dir . '/app-' . date('Y-m-d') . '.log', $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
