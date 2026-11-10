<?php
declare(strict_types=1);

$router = require dirname(__DIR__) . '/bootstrap/app.php';
$router->dispatch();
