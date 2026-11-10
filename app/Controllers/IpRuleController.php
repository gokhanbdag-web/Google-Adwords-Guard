<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Services\IpManagementService;
use DateTimeImmutable;
use RuntimeException;
use Throwable;

final class IpRuleController
{
    public function index(): void
    {
        requireAuth();
        $userId = (int) auth()['id'];
        $pdo = Database::connection();

        $websiteQuery = $pdo->prepare(
            'SELECT id, name, domain FROM websites WHERE user_id = :user_id ORDER BY name'
        );
        $websiteQuery->execute(['user_id' => $userId]);
        $websites = $websiteQuery->fetchAll();

        $ruleQuery = $pdo->prepare(
            'SELECT r.id, r.website_id, INET6_NTOA(r.ip_address) AS ip_address,
                    r.rule_type, r.status, r.source, r.risk_score,
                    r.evidence_count, r.reason, r.expires_at, r.created_at,
                    w.name AS website_name, w.domain
             FROM ip_rules r
             INNER JOIN websites w ON w.id = r.website_id
             WHERE r.user_id = :user_id
             ORDER BY
                CASE WHEN r.status = "pending" THEN 0 ELSE 1 END,
                r.created_at DESC
             LIMIT 500'
        );
        $ruleQuery->execute(['user_id' => $userId]);
        $rules = $ruleQuery->fetchAll();

        $historyQuery = $pdo->prepare(
            'SELECT h.id, INET6_NTOA(h.ip_address) AS ip_address,
                    h.action, h.old_status, h.new_status, h.reason, h.created_at,
                    w.name AS website_name
             FROM ip_rule_history h
             INNER JOIN websites w ON w.id = h.website_id
             WHERE w.user_id = :user_id
             ORDER BY h.id DESC
             LIMIT 100'
        );
        $historyQuery->execute(['user_id' => $userId]);
        $history = $historyQuery->fetchAll(PDO::FETCH_ASSOC);

        require BASE_PATH . '/resources/views/dashboard/ip-rules.php';
    }

    public function store(): void
    {
        requireAuth();
        requireCsrf();

        $userId = (int) auth()['id'];
        $websiteId = (int) ($_POST['website_id'] ?? 0);
        $ipAddress = trim((string) ($_POST['ip_address'] ?? ''));
        $ruleType = (string) ($_POST['rule_type'] ?? '');
        $reason = trim((string) ($_POST['reason'] ?? ''));
        $expirationInput = trim((string) ($_POST['expires_at'] ?? ''));

        $expiresAt = null;
        if ($expirationInput !== '') {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $expirationInput);
            if (!$date || $date->format('Y-m-d\TH:i') !== $expirationInput) {
                $_SESSION['error'] = 'Geçerlilik tarihi hatalı.';
                redirect('/ip-rules');
            }
            $expiresAt = $date->format('Y-m-d H:i:s');
        }

        try {
            (new IpManagementService(Database::connection()))->addManualRule(
                $userId,
                $websiteId,
                $ipAddress,
                $ruleType,
                $reason,
                $expiresAt
            );
            $_SESSION['success'] = 'IP kuralı kaydedildi. Bu kayıt henüz Google Ads hesabına gönderilmedi.';
        } catch (RuntimeException $exception) {
            $_SESSION['error'] = $exception->getMessage();
        } catch (Throwable $exception) {
            error_log('[AdGuard IP Rule] ' . $exception->getMessage());
            $_SESSION['error'] = 'IP kuralı kaydedilemedi. Lütfen tekrar deneyin.';
        }

        redirect('/ip-rules');
    }

    public function approve(): void
    {
        requireAuth();
        requireCsrf();

        $approved = (new IpManagementService(Database::connection()))->approveCandidate(
            (int) auth()['id'],
            (int) ($_POST['rule_id'] ?? 0)
        );

        $_SESSION[$approved ? 'success' : 'error'] = $approved
            ? 'IP adayı kurum içi engel listesine alındı. Google Ads hesabında değişiklik yapılmadı.'
            : 'IP adayı bulunamadı veya artık onaylanabilir durumda değil.';

        redirect('/ip-rules');
    }

    public function revoke(): void
    {
        requireAuth();
        requireCsrf();

        $revoked = (new IpManagementService(Database::connection()))->revokeRule(
            (int) auth()['id'],
            (int) ($_POST['rule_id'] ?? 0)
        );

        $_SESSION[$revoked ? 'success' : 'error'] = $revoked
            ? 'IP kuralı pasife alındı.'
            : 'IP kuralı bulunamadı veya zaten pasif.';

        redirect('/ip-rules');
    }
}
