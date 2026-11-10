<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/vendor/autoload.php';

use App\Core\Database;
use App\Core\Env;

Env::load(BASE_PATH . '/.env');

$days = (int) env('VISIT_RETENTION_DAYS', 90);
$days = max(7, min(3650, $days));
$cutoff = (new DateTimeImmutable('-' . $days . ' days'))->format('Y-m-d H:i:s');
$pdo = Database::connection();

$totalDeleted = 0;
$statement = $pdo->prepare('DELETE FROM visits WHERE created_at < :cutoff LIMIT 10000');

do {
    $statement->execute(['cutoff' => $cutoff]);
    $deleted = $statement->rowCount();
    $totalDeleted += $deleted;
} while ($deleted === 10000);

fwrite(STDOUT, "Deleted {$totalDeleted} visits older than {$days} days.\n");
