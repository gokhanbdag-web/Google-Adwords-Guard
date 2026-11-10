<?php
declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/vendor/autoload.php';

use App\Core\Env;
use App\Core\Database;
use Throwable;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

Env::load(BASE_PATH . '/.env');
date_default_timezone_set('Europe/Istanbul');

try {
    $pdo = Database::connection();
    $expired = $pdo->prepare(
        "UPDATE subscriptions
         SET status = 'expired'
         WHERE status = 'active'
           AND ends_at IS NOT NULL
           AND ends_at < NOW()"
    );
    $expired->execute();

    $stale = $pdo->prepare(
        "UPDATE queue_jobs
         SET status = CASE WHEN attempts >= 5 THEN 'failed' ELSE 'pending' END,
             available_at = NOW(),
             last_error = COALESCE(last_error, 'Recovered stale processing job.')
         WHERE status = 'processing'
           AND created_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE)"
    );
    $stale->execute();

    $retentionDays = max(7, min(3650, (int) env('VISIT_RETENTION_DAYS', 90)));
    $prune = $pdo->prepare(
        "DELETE FROM visits
         WHERE created_at < DATE_SUB(NOW(), INTERVAL {$retentionDays} DAY)
         LIMIT 10000"
    );
    $deletedVisits = 0;
    do {
        $prune->execute();
        $deletedBatch = $prune->rowCount();
        $deletedVisits += $deletedBatch;
    } while ($deletedBatch === 10000);

    echo 'Expired subscriptions: ' . $expired->rowCount() . PHP_EOL;
    echo 'Pruned visits: ' . $deletedVisits . PHP_EOL;
    echo 'Recovered queue jobs: ' . $stale->rowCount() . PHP_EOL;
} catch (Throwable $e) {
    error_log('[AdGuard Cron] ' . $e::class . ': ' . $e->getMessage());
    exit(1);
}
