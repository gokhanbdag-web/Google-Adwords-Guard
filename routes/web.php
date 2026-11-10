<?php
declare(strict_types=1);

use App\Controllers\AuthController;
use App\Controllers\HomeController;
use App\Controllers\DashboardController;
use App\Controllers\SiteController;
use App\Controllers\Admin\DashboardController as AdminDashboardController;

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
$router->get('/admin', [AdminDashboardController::class, 'index']);
