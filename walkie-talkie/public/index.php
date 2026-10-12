<?php
declare(strict_types=1);

// Front controller used when the web root points at the "public" folder
// (recommended for cPanel / production, and for `php -S localhost:8000 -t public`).
define('WT_ROOT', dirname(__DIR__));
require WT_ROOT . '/app/bootstrap.php';
