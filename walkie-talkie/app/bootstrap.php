<?php
declare(strict_types=1);

/**
 * Bootstrap: defines the project root, registers a tiny PSR-4 autoloader
 * (namespace "App\" => app/) and starts the application.
 */

if (!defined('WT_ROOT')) {
    define('WT_ROOT', dirname(__DIR__));
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = WT_ROOT . '/app/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require_once WT_ROOT . '/app/Helpers/functions.php';

(new \App\Core\App(WT_ROOT))->run();
