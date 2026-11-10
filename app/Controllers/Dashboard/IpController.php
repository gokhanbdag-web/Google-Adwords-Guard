<?php
declare(strict_types=1);

namespace App\Controllers\Dashboard;

use App\Core\Database;
use App\Services\IpManagementService;
use PDO;
use Throwable;

final class IpController
{
    public function index(): void
    {
        requireAuth();
        $userId = (int) auth()['id'];
        $websiteId = (int) ($_GET['website_id'] ?? 0);
        $pdo = Database::connection();

        $sites = $pdo->prepare("SELECT id, name FROM websites WHERE user_id = :user_id ORDER BY name");
        $sites->execute(['user_id' => $userId]);
        $websites = $sites->fetchAll(PDO::FETCH_ASSOC);

        $sql = "SELECT r.id, r.website_id, r.ip_address, INET6_NTOA(r.ip_address) AS ip_text,
                       r.rule_type, r.reason, r.source, r.status, r.risk_score,
                       r.evidence_count, r.expires_at, r.created_at, w.name AS website_name
                FROM ip_rules r INNER JOIN websites w ON w.id = r.website_id
                WHERE w.user_id = :user_id";
        $params = ['user_id' => $userId];
        if ($websiteId > 0) {
            $sql .= " AND w.id = :website_id";
            $params['website_id'] = $websiteId;
        }
        $sql .= " ORDER BY r.id DESC LIMIT 300";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rules = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $historyQuery = $pdo->prepare(
            'SELECT h.id, INET6_NTOA(h.ip_address) AS ip_text, h.action,
                    h.old_rule_type, h.new_rule_type, h.old_status, h.new_status,
                    h.reason, h.created_at, w.name AS website_name
             FROM ip_rule_history h
             INNER JOIN websites w ON w.id = h.website_id
             WHERE w.user_id = :user_id
             ORDER BY h.id DESC LIMIT 100'
        );
        $historyQuery->execute(['user_id' => $userId]);
        $history = $historyQuery->fetchAll(PDO::FETCH_ASSOC);

        require BASE_PATH . '/resources/views/dashboard/ip-management.php';
    }

    public function create(): void
    {
        requireAuth();
        $pdo = Database::connection();
        $sites = $pdo->prepare("SELECT id, name FROM websites WHERE user_id = :user_id ORDER BY name");
        $sites->execute(['user_id' => (int) auth()['id']]);
        $websites = $sites->fetchAll(PDO::FETCH_ASSOC);
        require BASE_PATH . '/resources/views/dashboard/ip-create.php';
    }

    public function store(): void
    {
        requireAuth();
        try {
            $service = new IpManagementService(Database::connection());
            $service->addManualRule(
                (int) auth()['id'],
                (int) ($_POST['website_id'] ?? 0),
                trim((string) ($_POST['ip_address'] ?? '')),
                (string) ($_POST['rule_type'] ?? ''),
                trim((string) ($_POST['reason'] ?? '')),
                !empty($_POST['expires_at']) ? str_replace('T', ' ', (string) $_POST['expires_at']) . ':00' : null
            );
            $_SESSION['success'] = 'IP kuralı kaydedildi.';
            redirect('/ip-rules');
        } catch (\\RuntimeException $e) {
            $_SESSION['error'] = $e->getMessage();
            redirect('/ip-rules/create');
        } catch (Throwable $e) {
            error_log('[AdGuard IP rule] ' . $e::class . ': ' . $e->getMessage());
            $_SESSION['error'] = 'IP kuralı kaydedilemedi. Lütfen tekrar deneyin.';
            redirect('/ip-rules/create');
        }
    }

    public function approve(): void
    {
        requireAuth();
        $ok = (new IpManagementService(Database::connection()))->approveCandidate(
            (int) auth()['id'],
            (int) ($_POST['id'] ?? 0)
        );
        $_SESSION[$ok ? 'success' : 'error'] = $ok ? 'Aday IP kuralı onaylandı.' : 'Aday kural bulunamadı.';
        redirect('/ip-rules');
    }

    public function revoke(): void
    {
        requireAuth();
        $ok = (new IpManagementService(Database::connection()))->revokeRule(
            (int) auth()['id'],
            (int) ($_POST['id'] ?? 0)
        );
        $_SESSION[$ok ? 'success' : 'error'] = $ok ? 'IP kuralı iptal edildi.' : 'IP kuralı bulunamadı.';
        redirect('/ip-rules');
    }
}
