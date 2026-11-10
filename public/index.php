<?php
declare(strict_types=1);

$base = dirname(__DIR__);
if (!is_file($base . '/storage/installed.lock') || !is_file($base . '/.env') || !is_file($base . '/vendor/autoload.php')) {
    require $base . '/install/index.php';
    return;
}
$router = require $base . '/bootstrap/app.php';
$router->dispatch();
