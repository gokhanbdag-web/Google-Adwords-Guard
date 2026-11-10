<?php
declare(strict_types=1);

// Minimal Composer-compatible PSR-4 loader. This project has no third-party
// runtime packages; keeping this file in the release ZIP removes the need for
// Composer on shared hosting.
spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) return;
    $relative = substr($class, strlen($prefix));
    $file = dirname(__DIR__) . '/app/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) require $file;
});
require_once dirname(__DIR__) . '/app/Helpers/functions.php';
return true;
