<?php
declare(strict_types=1);

/**
 * Lightweight PSR-4 loader for shared hosting.
 * The production application must not require Composer or a vendor directory.
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file = BASE_PATH . '/app/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

require_once BASE_PATH . '/app/Helpers/functions.php';
