<?php
declare(strict_types=1);

use App\Controllers\AuthController;
use App\Controllers\DashboardController;
use App\Controllers\HomeController;
use App\Controllers\GoogleController;
use App\Controllers\IpRuleController;
use App\Controllers\SiteController;
use App\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Controllers\TrackingController;

$router->get('/', [HomeController::class, 'index']);
$router->get('/login', [AuthController::class, 'showLogin']);
$router->post('/login', [AuthController::class, 'login']);
$router->get('/register', [AuthController::class, 'showRegister']);
$router->post('/register', [AuthController::class, 'register']);
$router->post('/logout', [AuthController::class, 'logout']);

$router->get('/dashboard', [DashboardController::class, 'index']);
$router->get('/sites', [SiteController::class, 'index']);
$router->get('/sites/create', [SiteController::class, 'create']);
$router->post('/sites', [SiteController::class, 'store']);

$router->get('/ip-rules', [IpRuleController::class, 'index']);

$router->get('/google', [GoogleController::class, 'index']);
$router->get('/google/connect', [GoogleController::class, 'connect']);
$router->get('/google/callback', [GoogleController::class, 'callback']);
$router->post('/google/select', [GoogleController::class, 'select']);
$router->post('/google/apply', [GoogleController::class, 'applyIpExclusion']);
$router->post('/google/remove', [GoogleController::class, 'removeIpExclusion']);
$router->post('/ip-rules', [IpRuleController::class, 'store']);
$router->post('/ip-rules/approve', [IpRuleController::class, 'approve']);
$router->post('/ip-rules/revoke', [IpRuleController::class, 'revoke']);

$router->post('/track', static function (): void {
    (new TrackingController(\App\Core\Database::connection()))->handle();
});

$router->get('/admin', [AdminDashboardController::class, 'index']);
