<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;

final class DashboardController
{
    public function index(): void
    {
        requireAuth();

        $pdo = Database::connection();
        $userId = (int) auth()['id'];

        $counts = $pdo->prepare(
            "SELECT
                COUNT(DISTINCT w.id) AS websites,
                COUNT(v.id) AS visits,
                COALESCE(SUM(v.is_suspicious = 1), 0) AS suspicious
             FROM websites w
             LEFT JOIN visits v ON v.website_id = w.id
             WHERE w.user_id = :user_id"
        );
        $counts->execute(['user_id' => $userId]);
        $stats = $counts->fetch() ?: [
            'websites' => 0,
            'visits' => 0,
            'suspicious' => 0
        ];

        $today = $pdo->prepare(
            "SELECT COUNT(*)
             FROM visits v
             INNER JOIN websites w ON w.id = v.website_id
             WHERE w.user_id = :user_id
               AND v.created_at >= CURDATE()
               AND v.created_at < CURDATE() + INTERVAL 1 DAY"
        );
        $today->execute(['user_id' => $userId]);
        $todayVisits = (int) $today->fetchColumn();

        $websiteCount = (int) $stats['websites'];
        $suspiciousVisits = (int) $stats['suspicious'];
        $totalVisits = (int) $stats['visits'];

        require BASE_PATH . '/resources/views/dashboard/index.php';
    }
}
