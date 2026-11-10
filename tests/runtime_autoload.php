<?php
declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
$_ENV['APP_URL'] = 'https://example.test/adguard';

require BASE_PATH . '/app/Autoload.php';

if (!class_exists(\App\Core\Router::class)) {
    throw new RuntimeException('Composer-free PSR-4 autoloading failed.');
}
if (!function_exists('env') || !function_exists('url')) {
    throw new RuntimeException('Application helper functions were not loaded.');
}
if (url('/login') !== 'https://example.test/adguard/login') {
    throw new RuntimeException('Subdirectory-aware URL generation failed: ' . url('/login'));
}

echo "Standalone runtime autoloader checks passed.\n";
