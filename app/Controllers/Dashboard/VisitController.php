<?php
declare(strict_types=1);

namespace App\Controllers\Dashboard;

use App\Core\Database;
use PDO;

final class VisitController
{
    public function index(): void
    {
        requireAuth();
        $userId = (int) auth()['id'];
        $websiteId = filter_input(INPUT_GET, 'website_id', FILTER_VALIDATE_INT) ?: 0;
        $pdo = Database::connection();

        $sql = "SELECT v.id, v.ip_address, v.page_url, v.risk_score, v.risk_level,
                       v.is_suspicious, v.created_at, w.name AS website_name
                FROM visits v
                INNER JOIN websites w ON w.id = v.website_id
                WHERE w.user_id = :user_id";
        $params = ['user_id' => $userId];

        if ($websiteId > 0) {
            $sql .= " AND w.id = :website_id";
            $params['website_id'] = $websiteId;
        }
        if (isset($_GET['risk_level']) && in_array($_GET['risk_level'], ['low','medium','high','critical'], true)) {
            $sql .= " AND v.risk_level = :risk_level";
            $params['risk_level'] = $_GET['risk_level'];
        }
        $sql .= " ORDER BY v.id DESC LIMIT 200";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $visits = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $sites = $pdo->prepare("SELECT id, name FROM websites WHERE user_id = :user_id ORDER BY name");
        $sites->execute(['user_id' => $userId]);
        $websites = $sites->fetchAll(PDO::FETCH_ASSOC);

        require BASE_PATH . '/resources/views/dashboard/visits.php';
    }

    public function show(): void
    {
        requireAuth();
        $stmt = Database::connection()->prepare(
            "SELECT v.*, INET6_NTOA(v.ip_address) AS ip_text, w.name AS website_name
             FROM visits v INNER JOIN websites w ON w.id = v.website_id
             WHERE v.id = :id AND w.user_id = :user_id LIMIT 1"
        );
        $stmt->execute([
            'id' => (int) ($_GET['id'] ?? 0),
            'user_id' => (int) auth()['id']
        ]);
        $visit = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$visit) {
            http_response_code(404);
            exit('Ziyaret bulunamadı.');
        }
        $reasons = json_decode((string) ($visit['detection_reasons'] ?? '[]'), true);
        if (!is_array($reasons)) {
            $reasons = [];
        }
        require BASE_PATH . '/resources/views/dashboard/visit-detail.php';
    }
}
