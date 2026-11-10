<?php
declare(strict_types=1);

return [
    'name' => env('APP_NAME', 'AdGuard'),
    'env' => env('APP_ENV', 'production'),
    'debug' => filter_var(env('APP_DEBUG', false), FILTER_VALIDATE_BOOLEAN),
    'url' => rtrim((string) env('APP_URL', ''), '/'),
    'key' => (string) env('APP_KEY', ''),
    'timezone' => 'Europe/Istanbul',
    'session' => [
        'name' => env('SESSION_NAME', 'adguard_session'),
        'secure' => filter_var(env('SESSION_SECURE', true), FILTER_VALIDATE_BOOLEAN),
        'samesite' => env('SESSION_SAMESITE', 'Lax'),
    ],
];