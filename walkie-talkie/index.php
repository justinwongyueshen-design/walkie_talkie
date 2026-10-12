<?php
declare(strict_types=1);

// Front controller used when the project folder itself is the web root
// (XAMPP: http://localhost/walkie-talkie/). See .htaccess for the rewrite rules.
define('WT_ROOT', __DIR__);
require __DIR__ . '/app/bootstrap.php';
