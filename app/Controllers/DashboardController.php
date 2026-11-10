<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Services\QuotaService;

final class DashboardController
{
    public function index(): void
    {
        requireAuth();
        $pdo = Database::connection();
        $userId = (int) auth()['id'];

        $sites = $pdo->prepare(
            "SELECT id, name, domain, status FROM websites
             WHERE user_id = :user_id ORDER BY id DESC"
        );
        $sites->execute(['user_id' => $userId]);
        $websites = $sites->fetchAll();

        $counts = $pdo->prepare(
            "SELECT COUNT(*) AS total,
                    COALESCE(SUM(is_suspicious = 1), 0) AS suspicious
             FROM visits v
             INNER JOIN websites w ON w.id = v.website_id
             WHERE w.user_id = :user_id AND DATE(v.created_at) = CURDATE()"
        );
        $counts->execute(['user_id' => $userId]);
        $stats = $counts->fetch() ?: ['total' => 0, 'suspicious' => 0];

        $websiteCount = count($websites);
        $todayVisits = (int) $stats['total'];
        $suspiciousVisits = (int) $stats['suspicious'];
        $quota = (new QuotaService($pdo))->getUsage($userId);

        require BASE_PATH . '/resources/views/dashboard/index.php';
    }
}
