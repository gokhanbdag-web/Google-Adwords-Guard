<?php
declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

require BASE_PATH . '/vendor/autoload.php';

use App\Core\Env;
use App\Core\Router;
use App\Core\Security;
use App\Core\Database;
use App\Controllers\TrackingController;

Env::load(BASE_PATH . '/.env');

$app = require BASE_PATH . '/config/app.php';
date_default_timezone_set($app['timezone'] ?? 'UTC');

if (PHP_SAPI !== 'cli' && session_status() !== PHP_SESSION_ACTIVE) {
    session_name($app['session']['name'] ?? 'adguard_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => (bool) ($app['session']['secure'] ?? true),
        'httponly' => true,
        'samesite' => $app['session']['samesite'] ?? 'Lax'
    ]);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');
    session_start();
}

if (PHP_SAPI !== 'cli') {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
}

$router = new Router();
require BASE_PATH . '/routes/web.php';

$router->post('/track', static function (): void {
    (new TrackingController(Database::connection()))->handle();
});

if (PHP_SAPI !== 'cli' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $externalCallbacks = ['/track', '/payments/paytr/callback', '/payments/iyzico/callback'];
    if (!in_array($path, $externalCallbacks, true) && !Security::verifyCsrf($_POST['_token'] ?? null)) {
        http_response_code(419);
        exit('Oturum doğrulaması başarısız. Sayfayı yenileyip tekrar deneyin.');
    }
}

return $router;
