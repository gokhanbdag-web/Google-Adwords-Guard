<?php
declare(strict_types=1);

define('BASE_PATH', __DIR__);

$autoload = BASE_PATH . '/vendor/autoload.php';
if (!is_file($autoload)) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    exit('AdGuard kurulumu tamamlanmamış. Önce composer install çalıştırın.');
}
require $autoload;

use App\Core\Env;
use App\Core\Router;

try {
    Env::load(BASE_PATH . '/.env');
} catch (Throwable $exception) {
    error_log('[AdGuard] Environment loading failed: ' . $exception->getMessage());
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    exit('AdGuard yapılandırması eksik. .env dosyasını oluşturun.');
}

$config = require BASE_PATH . '/config/app.php';
date_default_timezone_set($config['timezone'] ?? 'Europe/Istanbul');

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
session_name((string) ($config['session']['name'] ?? 'adguard_session'));
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => (bool) ($config['session']['secure'] ?? true),
    'httponly' => true,
    'samesite' => (string) ($config['session']['samesite'] ?? 'Lax')
]);
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$router = new Router();
require BASE_PATH . '/routes/web.php';
$router->dispatch();
