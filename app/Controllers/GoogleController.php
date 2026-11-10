<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Services\GoogleAdsApiClient;
use App\Services\GoogleOAuthService;
use PDO;
use Throwable;

final class GoogleController
{
    public function index(): void
    {
        requireAuth();

        $userId = (int) auth()['id'];
        $pdo = Database::connection();
        $oauth = new GoogleOAuthService($pdo);
        $connections = $oauth->connections($userId);
        $selected = $oauth->selectedConnection($userId);
        $campaigns = [];
        $rules = [];
        $history = [];
        $googleError = null;

        if ($selected) {
            try {
                $accessToken = $oauth->connectionAccessToken(
                    $userId,
                    (string) $selected['customer_id']
                );
                $api = new GoogleAdsApiClient(
                    $accessToken,
                    (string) env('GOOGLE_ADS_API_VERSION', 'v26'),
                    $selected['login_customer_id'] ?: null
                );
                $campaigns = $api->listCampaigns((string) $selected['customer_id']);
            } catch (Throwable $exception) {
                $googleError = $exception->getMessage();
            }
        }

        $ruleQuery = $pdo->prepare(
            "SELECT r.id, r.website_id, INET6_NTOA(r.ip_address) AS ip_address,
                    r.risk_score, r.evidence_count, r.reason, r.created_at,
                    w.name AS website_name, w.domain
             FROM ip_rules r
             INNER JOIN websites w ON w.id = r.website_id
             WHERE r.user_id = :user_id
               AND r.rule_type = 'deny'
               AND r.status = 'active'
             ORDER BY r.risk_score DESC, r.created_at DESC
             LIMIT 200"
        );
        $ruleQuery->execute(['user_id' => $userId]);
        $rules = $ruleQuery->fetchAll(PDO::FETCH_ASSOC);

        $historyQuery = $pdo->prepare(
            'SELECT customer_id, campaign_id, INET6_NTOA(ip_address) AS ip_address,
                    action, status, error_message, created_at
             FROM google_ads_operations
             WHERE user_id = :user_id
             ORDER BY id DESC
             LIMIT 30'
        );
        $historyQuery->execute(['user_id' => $userId]);
        $history = $historyQuery->fetchAll(PDO::FETCH_ASSOC);

        $writesEnabled = filter_var(
            env('GOOGLE_ADS_WRITES_ENABLED', false),
            FILTER_VALIDATE_BOOLEAN
        );

        require BASE_PATH . '/resources/views/dashboard/google.php';
    }

    public function connect(): void
    {
        requireAuth();

        try {
            $url = (new GoogleOAuthService(Database::connection()))->authorizationUrl();
            redirect($url);
        } catch (Throwable $exception) {
            $_SESSION['error'] = $exception->getMessage();
            redirect('/google');
        }
    }

    public function callback(): void
    {
        requireAuth();

        $expectedState = (string) ($_SESSION['google_oauth_state'] ?? '');
        $receivedState = (string) ($_GET['state'] ?? '');
        $startedAt = (int) ($_SESSION['google_oauth_started_at'] ?? 0);
        unset($_SESSION['google_oauth_state'], $_SESSION['google_oauth_started_at']);

        if (
            $expectedState === '' ||
            $receivedState === '' ||
            !hash_equals($expectedState, $receivedState) ||
            $startedAt < time() - 600
        ) {
            $_SESSION['error'] = 'Google bağlantı doğrulaması başarısız. Lütfen yeniden deneyin.';
            redirect('/google');
        }

        if (!empty($_GET['error'])) {
            $_SESSION['error'] = 'Google bağlantı isteği reddedildi.';
            redirect('/google');
        }

        $code = trim((string) ($_GET['code'] ?? ''));
        if ($code === '') {
            $_SESSION['error'] = 'Google yetkilendirme kodu alınamadı.';
            redirect('/google');
        }

        try {
            $oauth = new GoogleOAuthService(Database::connection());
            $tokens = $oauth->exchangeCode($code);
            $accounts = $oauth->storeAccessibleAccounts((int) auth()['id'], $tokens);
            $_SESSION['success'] = count($accounts) . ' Google Ads hesabı bağlantıya eklendi.';
        } catch (Throwable $exception) {
            error_log('[AdGuard Google OAuth] ' . $exception->getMessage());
            $_SESSION['error'] = $exception->getMessage();
        }

        redirect('/google');
    }

    public function select(): void
    {
        requireAuth();
        requireCsrf();

        $userId = (int) auth()['id'];
        $customerId = preg_replace('/[^0-9]/', '', (string) ($_POST['customer_id'] ?? '')) ?? '';
        $managerIdInput = trim((string) ($_POST['login_customer_id'] ?? ''));
        $managerId = $managerIdInput === ''
            ? null
            : (preg_replace('/[^0-9]/', '', $managerIdInput) ?? '');

        if (!preg_match('/^[0-9]{6,20}$/', $customerId)
            || ($managerId !== null && !preg_match('/^[0-9]{6,20}$/', $managerId))) {
            $_SESSION['error'] = 'Google Ads müşteri veya yönetici hesap numarası geçersiz.';
            redirect('/google');
        }

        $pdo = Database::connection();
        $pdo->beginTransaction();

        try {
            $exists = $pdo->prepare(
                "SELECT id FROM google_connections
                 WHERE user_id = :user_id AND customer_id = :customer_id AND status = 'active'
                 LIMIT 1"
            );
            $exists->execute(['user_id' => $userId, 'customer_id' => $customerId]);
            $connectionId = $exists->fetchColumn();

            if (!$connectionId) {
                throw new \RuntimeException('Seçilen Google Ads hesabı bu kullanıcıya bağlı değil.');
            }

            $clear = $pdo->prepare(
                'UPDATE google_connections SET is_selected = 0 WHERE user_id = :user_id'
            );
            $clear->execute(['user_id' => $userId]);

            $select = $pdo->prepare(
                'UPDATE google_connections
                 SET is_selected = 1, login_customer_id = :login_customer_id
                 WHERE id = :id AND user_id = :user_id'
            );
            $select->execute([
                'login_customer_id' => $managerId,
                'id' => (int) $connectionId,
                'user_id' => $userId
            ]);

            $pdo->commit();
            $_SESSION['success'] = 'Google Ads hesabı seçildi.';
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $_SESSION['error'] = $exception->getMessage();
        }

        redirect('/google');
    }

    public function applyIpExclusion(): void
    {
        requireAuth();
        requireCsrf();

        $userId = (int) auth()['id'];
        $ruleId = (int) ($_POST['rule_id'] ?? 0);
        $campaignId = preg_replace('/[^0-9]/', '', (string) ($_POST['campaign_id'] ?? '')) ?? '';
        $pdo = Database::connection();
        $connectionId = 0;
        $customerId = '';
        $ipAddress = '';
        $validateOnly = true;
        $action = 'validate';

        try {
            $oauth = new GoogleOAuthService($pdo);
            $connection = $oauth->selectedConnection($userId);
            if (!$connection) {
                throw new \RuntimeException('Önce bir Google Ads hesabı seçmelisiniz.');
            }

            $connectionId = (int) $connection['id'];
            $customerId = (string) $connection['customer_id'];

            if (!preg_match('/^[0-9]+$/', $campaignId)) {
                throw new \RuntimeException('Kampanya seçimi geçersiz.');
            }

            $ruleQuery = $pdo->prepare(
                "SELECT r.id, r.ip_address, INET6_NTOA(r.ip_address) AS ip_text
                 FROM ip_rules r
                 INNER JOIN websites w ON w.id = r.website_id
                 WHERE r.id = :rule_id
                   AND r.user_id = :user_id
                   AND w.user_id = :user_id
                   AND r.rule_type = 'deny'
                   AND r.status = 'active'
                 LIMIT 1"
            );
            $ruleQuery->execute(['rule_id' => $ruleId, 'user_id' => $userId]);
            $rule = $ruleQuery->fetch(PDO::FETCH_ASSOC);

            if (!$rule) {
                throw new \RuntimeException('Seçilen IP kuralı bulunamadı veya aktif bir deny kuralı değil.');
            }

            $ipAddress = (string) $rule['ip_text'];
            $config = require BASE_PATH . '/config/google.php';
            $writesEnabled = (bool) ($config['writes_enabled'] ?? false);
            $confirmed = (string) ($_POST['confirm_live'] ?? '') === '1';
            $validateOnly = !($writesEnabled && $confirmed);
            $action = $validateOnly ? 'validate' : 'create';

            $accessToken = $oauth->connectionAccessToken($userId, $customerId);
            $api = new GoogleAdsApiClient(
                $accessToken,
                (string) ($config['api_version'] ?? 'v26'),
                $connection['login_customer_id'] ?: null
            );
            $response = $api->createIpExclusion(
                $customerId,
                $campaignId,
                $ipAddress,
                $validateOnly
            );

            $status = !empty($response['already_exists'])
                ? 'already_exists'
                : ($validateOnly ? 'validated' : 'succeeded');

            $this->logOperation(
                $pdo,
                $userId,
                $connectionId,
                $customerId,
                $campaignId,
                $ipAddress,
                $action,
                $status,
                ['ip_address' => $ipAddress, 'campaign_id' => $campaignId, 'validate_only' => $validateOnly],
                $response,
                null
            );

            $_SESSION['success'] = !empty($response['already_exists'])
                ? 'Bu IP adresi seçilen kampanyada zaten hariç tutuluyor.'
                : ($validateOnly
                    ? 'Google Ads doğrulaması başarılı. Canlı değişiklik yapılmadı.'
                    : 'IP adresi seçilen Google Ads kampanyasına hariç tutma olarak eklendi.');
        } catch (Throwable $exception) {
            if ($connectionId > 0 && $customerId !== '' && $campaignId !== '' && $ipAddress !== '') {
                $packedIp = inet_pton($ipAddress);
                if ($packedIp !== false) {
                    $this->logOperation(
                        $pdo,
                        $userId,
                        $connectionId,
                        $customerId,
                        $campaignId,
                        $ipAddress,
                        $action,
                        'failed',
                        ['ip_address' => $ipAddress, 'campaign_id' => $campaignId, 'validate_only' => $validateOnly],
                        null,
                        $exception->getMessage()
                    );
                }
            }
            error_log('[AdGuard Google Ads] ' . $exception->getMessage());
            $_SESSION['error'] = $exception->getMessage();
        }

        redirect('/google');
    }

    private function logOperation(
        PDO $pdo,
        int $userId,
        int $connectionId,
        string $customerId,
        string $campaignId,
        string $ipAddress,
        string $action,
        string $status,
        array $request,
        ?array $response,
        ?string $error
    ): void {
        $packedIp = inet_pton($ipAddress);
        if ($packedIp === false) {
            return;
        }

        $statement = $pdo->prepare(
            'INSERT INTO google_ads_operations (
                user_id, connection_id, customer_id, campaign_id, ip_address,
                action, status, request_payload, response_payload, error_message, created_at
             ) VALUES (
                :user_id, :connection_id, :customer_id, :campaign_id, :ip_address,
                :action, :status, :request_payload, :response_payload, :error_message, NOW()
             )'
        );
        $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $statement->bindValue(':connection_id', $connectionId, PDO::PARAM_INT);
        $statement->bindValue(':customer_id', $customerId);
        $statement->bindValue(':campaign_id', $campaignId);
        $statement->bindValue(':ip_address', $packedIp, PDO::PARAM_LOB);
        $statement->bindValue(':action', $action);
        $statement->bindValue(':status', $status);
        $statement->bindValue(':request_payload', json_encode($request, JSON_THROW_ON_ERROR));
        $statement->bindValue(
            ':response_payload',
            $response === null ? null : json_encode($response, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)
        );
        $statement->bindValue(':error_message', $error);
        $statement->execute();
    }
}
