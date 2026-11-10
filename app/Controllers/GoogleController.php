<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Services\GoogleAdsApiService;
use PDO;
use Throwable;

final class GoogleController
{
    public function index(): void
    {
        requireAuth();
        $stmt = Database::connection()->prepare(
            "SELECT id, customer_id, login_customer_id, status, token_expires_at, created_at
             FROM google_connections WHERE user_id = :user_id ORDER BY customer_id"
        );
        $stmt->execute(['user_id' => (int) auth()['id']]);
        $connections = $stmt->fetchAll();
        require BASE_PATH . '/resources/views/dashboard/google.php';
    }

    public function connect(): void
    {
        requireAuth();
        try {
            $state = bin2hex(random_bytes(32));
            $_SESSION['google_oauth_state'] = $state;
            redirect((new \App\Services\GoogleOAuthService())->authorizationUrl($state));
        } catch (Throwable $e) {
            error_log('[AdGuard Google OAuth] ' . $e::class);
            $_SESSION['error'] = 'Google bağlantısı başlatılamadı. Yapılandırmayı kontrol edin.';
            redirect('/google');
        }
    }

    public function callback(): void
    {
        requireAuth();
        $state = (string) ($_GET['state'] ?? '');
        $expected = (string) ($_SESSION['google_oauth_state'] ?? '');
        unset($_SESSION['google_oauth_state']);
        if ($state === '' || $expected === '' || !hash_equals($expected, $state)) {
            $_SESSION['error'] = 'Google OAuth doğrulaması başarısız.';
            redirect('/google');
        }
        if (!empty($_GET['error'])) {
            $_SESSION['error'] = 'Google bağlantı isteği reddedildi.';
            redirect('/google');
        }

        try {
            $code = trim((string) ($_GET['code'] ?? ''));
            if ($code === '') {
                throw new \RuntimeException('Authorization code missing.');
            }
            $service = new \App\Services\GoogleOAuthService();
            $tokens = $service->exchangeCode($code);
            $refreshToken = (string) ($tokens['refresh_token'] ?? '');
            $accessToken = (string) ($tokens['access_token'] ?? '');
            if ($refreshToken === '' || $accessToken === '') {
                throw new \RuntimeException('Google did not return the required tokens. Revoke access and reconnect.');
            }
            $customers = $service->listAccessibleCustomers($accessToken);
            if (!$customers) {
                throw new \RuntimeException('No accessible Google Ads customer account was found.');
            }

            $pdo = Database::connection();
            $expiresAt = date('Y-m-d H:i:s', time() + (int) ($tokens['expires_in'] ?? 3600));
            $upsert = $pdo->prepare(
                "INSERT INTO google_connections
                 (user_id, customer_id, access_token, refresh_token, token_expires_at, scope, status)
                 VALUES
                 (:user_id, :customer_id, :access_token, :refresh_token, :expires_at, :scope, 'active')
                 ON DUPLICATE KEY UPDATE
                    access_token = VALUES(access_token),
                    refresh_token = VALUES(refresh_token),
                    token_expires_at = VALUES(token_expires_at),
                    scope = VALUES(scope),
                    status = 'active'"
            );
            foreach ($customers as $customerId) {
                $upsert->execute([
                    'user_id' => (int) auth()['id'],
                    'customer_id' => $customerId,
                    'access_token' => \App\Core\Security::encrypt($accessToken),
                    'refresh_token' => \App\Core\Security::encrypt($refreshToken),
                    'expires_at' => $expiresAt,
                    'scope' => (string) ($tokens['scope'] ?? 'https://www.googleapis.com/auth/adwords')
                ]);
            }
            $_SESSION['success'] = count($customers) . ' Google Ads hesabı bağlandı.';
        } catch (Throwable $e) {
            error_log('[AdGuard Google OAuth] ' . $e::class . ': ' . $e->getMessage());
            $_SESSION['error'] = 'Google Ads hesabı bağlanamadı. Google OAuth ve Developer Token ayarlarını kontrol edin.';
        }
        redirect('/google');
    }

    public function campaigns(): void
    {
        requireAuth();
        $userId = (int) auth()['id'];
        $connectionId = (int) ($_GET['id'] ?? 0);
        $pdo = Database::connection();
        $connectionQuery = $pdo->prepare(
            "SELECT id, customer_id FROM google_connections
             WHERE id = :id AND user_id = :user_id AND status = 'active' LIMIT 1"
        );
        $connectionQuery->execute(['id' => $connectionId, 'user_id' => $userId]);
        $connection = $connectionQuery->fetch(PDO::FETCH_ASSOC);
        if (!$connection) {
            http_response_code(404);
            exit('Google Ads bağlantısı bulunamadı.');
        }

        try {
            $campaigns = (new GoogleAdsApiService($pdo))->listCampaigns($userId, $connectionId);
            $campaigns = array_values(array_filter(
                $campaigns,
                static function (array $row): bool {
                    $campaign = $row['campaign'] ?? [];
                    $channel = (string) ($campaign['advertisingChannelType'] ?? '');
                    $subChannel = (string) ($campaign['advertisingChannelSubType'] ?? '');
                    return in_array($channel, ['SEARCH', 'DISPLAY', 'SHOPPING'], true)
                        && $subChannel !== 'DISPLAY_SMART_CAMPAIGN';
                }
            ));
        } catch (Throwable $e) {
            error_log('[AdGuard Google Ads] ' . $e::class . ': ' . $e->getMessage());
            $_SESSION['error'] = 'Kampanyalar alınamadı. Google Ads bağlantısını ve API yetkilerini kontrol edin.';
            redirect('/google');
        }

        $rulesQuery = $pdo->prepare(
            "SELECT r.id, INET6_NTOA(r.ip_address) AS ip_text, w.name AS website_name
             FROM ip_rules r INNER JOIN websites w ON w.id = r.website_id
             WHERE w.user_id = :user_id AND r.rule_type = 'deny' AND r.status = 'active'
             ORDER BY r.created_at DESC LIMIT 300"
        );
        $rulesQuery->execute(['user_id' => $userId]);
        $rules = $rulesQuery->fetchAll(PDO::FETCH_ASSOC);
        $actionsQuery = $pdo->prepare(
            'SELECT id, ip_rule_id, customer_id, campaign_id, ip_address, action_type, status, error_message, created_at
             FROM google_ads_actions
             WHERE user_id = :user_id AND google_connection_id = :connection_id
             ORDER BY id DESC LIMIT 50'
        );
        $actionsQuery->execute(['user_id' => $userId, 'connection_id' => $connectionId]);
        $actions = $actionsQuery->fetchAll(PDO::FETCH_ASSOC);

        $writesEnabled = (bool) (require BASE_PATH . '/config/google.php')['writes_enabled'];
        require BASE_PATH . '/resources/views/dashboard/google-campaigns.php';
    }

    public function excludeIp(): void
    {
        requireAuth();
        $userId = (int) auth()['id'];
        $connectionId = (int) ($_POST['connection_id'] ?? 0);
        $scope = (string) ($_POST['scope'] ?? 'campaign');
        $campaignId = $scope === 'account' ? 0 : (int) ($_POST['campaign_id'] ?? 0);
        $ruleId = (int) ($_POST['rule_id'] ?? 0);
        $confirmLive = (string) ($_POST['confirm_live'] ?? '') === '1';
        $pdo = Database::connection();

        if (!in_array($scope, ['account', 'campaign'], true) || ($scope === 'campaign' && $campaignId <= 0)) {
            $_SESSION['error'] = 'Google Ads hedefi geçersiz.';
            redirect('/google');
        }

        $ruleQuery = $pdo->prepare(
            "SELECT r.id, r.website_id, INET6_NTOA(r.ip_address) AS ip_text,
                    r.rule_type, r.status, w.user_id
             FROM ip_rules r INNER JOIN websites w ON w.id = r.website_id
             WHERE r.id = :rule_id AND w.user_id = :user_id
               AND r.rule_type = 'deny' AND r.status = 'active'
             LIMIT 1"
        );
        $ruleQuery->execute(['rule_id' => $ruleId, 'user_id' => $userId]);
        $rule = $ruleQuery->fetch(PDO::FETCH_ASSOC);
        if (!$rule) {
            $_SESSION['error'] = 'Önce onaylanmış bir IP engelleme kuralı seçmelisiniz.';
            redirect('/google');
        }

        $connectionQuery = $pdo->prepare(
            "SELECT customer_id FROM google_connections
             WHERE id = :id AND user_id = :user_id AND status = 'active' LIMIT 1"
        );
        $connectionQuery->execute(['id' => $connectionId, 'user_id' => $userId]);
        $connection = $connectionQuery->fetch(PDO::FETCH_ASSOC);
        if (!$connection) {
            $_SESSION['error'] = 'Google Ads hesabı bulunamadı.';
            redirect('/google');
        }

        $actionType = $scope === 'account' ? 'account_ip_exclusion' : 'ip_exclusion';
        $audit = $pdo->prepare(
            "INSERT INTO google_ads_actions
             (user_id, google_connection_id, ip_rule_id, customer_id, campaign_id,
              ip_address, action_type, status)
             VALUES (:user_id, :connection_id, :rule_id, :customer_id, :campaign_id,
                     :ip_address, :action_type, 'processing')"
        );
        $audit->execute([
            'user_id' => $userId,
            'connection_id' => $connectionId,
            'rule_id' => $ruleId,
            'customer_id' => $connection['customer_id'],
            'campaign_id' => $campaignId,
            'ip_address' => $rule['ip_text'],
            'action_type' => $actionType
        ]);
        $auditId = (int) $pdo->lastInsertId();

        try {
            $service = new GoogleAdsApiService($pdo);
            $result = $scope === 'account'
                ? $service->excludeIpForAccount($userId, $connectionId, (string) $rule['ip_text'], $confirmLive)
                : $service->excludeIpForCampaign($userId, $connectionId, $campaignId, (string) $rule['ip_text'], $confirmLive);

            $status = !empty($result['already_exists'])
                ? 'already_exists'
                : (!empty($result['validate_only']) ? 'validated' : 'succeeded');
            $resourceName = $result['response']['results'][0]['resourceName'] ?? null;
            $update = $pdo->prepare(
                'UPDATE google_ads_actions SET status = :status, provider_resource_name = :resource_name WHERE id = :id'
            );
            $update->execute([
                'status' => $status,
                'resource_name' => $resourceName,
                'id' => $auditId
            ]);

            $_SESSION['success'] = !empty($result['already_exists'])
                ? 'Bu IP adresi Google Ads üzerinde zaten hariç tutuluyor.'
                : (!empty($result['validate_only'])
                    ? 'Google Ads doğrulaması başarılı. Canlı değişiklik yapılmadı.'
                    : ($scope === 'account'
                        ? 'IP adresi Google Ads hesabının tamamında hariç tutuldu.'
                        : 'IP adresi Google Ads kampanyasında hariç tutuldu.'));
        } catch (Throwable $e) {
            error_log('[AdGuard Google Ads exclusion] ' . $e::class . ': ' . $e->getMessage());
            $update = $pdo->prepare(
                "UPDATE google_ads_actions SET status = 'failed', error_message = :error WHERE id = :id"
            );
            $update->execute([
                'error' => mb_substr($e->getMessage(), 0, 1000),
                'id' => $auditId
            ]);
            $_SESSION['error'] = 'Google Ads IP hariç tutma işlemi başarısız oldu. API erişimini ve kampanya türünü kontrol edin.';
        }
        redirect('/google');
    }

    public function removeIp(): void
    {
        requireAuth();
        $userId = (int) auth()['id'];
        $actionId = (int) ($_POST['action_id'] ?? 0);
        $confirmLive = (string) ($_POST['confirm_live'] ?? '') === '1';
        $pdo = Database::connection();

        $sourceQuery = $pdo->prepare(
            "SELECT id, google_connection_id, ip_rule_id, customer_id, campaign_id,
                    ip_address, action_type
             FROM google_ads_actions
             WHERE id = :id AND user_id = :user_id
               AND action_type IN ('ip_exclusion', 'account_ip_exclusion')
               AND status = 'succeeded'
             LIMIT 1"
        );
        $sourceQuery->execute(['id' => $actionId, 'user_id' => $userId]);
        $source = $sourceQuery->fetch(PDO::FETCH_ASSOC);
        if (!$source) {
            $_SESSION['error'] = 'Kaldırılabilecek başarılı bir Google Ads IP hariç tutma kaydı bulunamadı.';
            redirect('/google');
        }

        $connectionId = (int) $source['google_connection_id'];
        $scope = $source['action_type'] === 'account_ip_exclusion' ? 'account' : 'campaign';
        $campaignId = (int) $source['campaign_id'];
        $removeActionType = $scope === 'account' ? 'account_ip_exclusion_remove' : 'ip_exclusion_remove';

        $audit = $pdo->prepare(
            "INSERT INTO google_ads_actions
             (user_id, google_connection_id, ip_rule_id, customer_id, campaign_id,
              ip_address, action_type, status)
             VALUES (:user_id, :connection_id, :rule_id, :customer_id, :campaign_id,
                     :ip_address, :action_type, 'processing')"
        );
        $audit->execute([
            'user_id' => $userId,
            'connection_id' => $connectionId,
            'rule_id' => (int) $source['ip_rule_id'],
            'customer_id' => $source['customer_id'],
            'campaign_id' => $campaignId,
            'ip_address' => $source['ip_address'],
            'action_type' => $removeActionType
        ]);
        $auditId = (int) $pdo->lastInsertId();

        try {
            $result = (new GoogleAdsApiService($pdo))->removeIpExclusion(
                $userId,
                $connectionId,
                $scope,
                (string) $source['ip_address'],
                $scope === 'campaign' ? $campaignId : null,
                $confirmLive
            );
            $status = !empty($result['not_found'])
                ? 'not_found'
                : (!empty($result['validate_only']) ? 'validated' : 'succeeded');
            $update = $pdo->prepare(
                'UPDATE google_ads_actions SET status = :status WHERE id = :id'
            );
            $update->execute(['status' => $status, 'id' => $auditId]);

            $_SESSION['success'] = !empty($result['not_found'])
                ? 'Bu IP hariç tutması Google Ads hesabında bulunamadı.'
                : (!empty($result['validate_only'])
                    ? 'Kaldırma doğrulaması başarılı. Canlı değişiklik yapılmadı.'
                    : 'IP hariç tutması Google Ads hesabından kaldırıldı.');
        } catch (Throwable $e) {
            error_log('[AdGuard Google Ads removal] ' . $e::class . ': ' . $e->getMessage());
            $pdo->prepare(
                "UPDATE google_ads_actions SET status = 'failed', error_message = :error WHERE id = :id"
            )->execute([
                'error' => mb_substr($e->getMessage(), 0, 1000),
                'id' => $auditId
            ]);
            $_SESSION['error'] = 'Google Ads IP hariç tutması kaldırılamadı. API erişimini kontrol edin.';
        }

        redirect('/google');
    }

    public function disconnect(): void
    {
        requireAuth();
        $stmt = Database::connection()->prepare(
            "UPDATE google_connections SET status = 'revoked', access_token = NULL,
             refresh_token = '' WHERE id = :id AND user_id = :user_id"
        );
        $stmt->execute([
            'id' => (int) ($_POST['id'] ?? 0),
            'user_id' => (int) auth()['id']
        ]);
        $_SESSION['success'] = 'Google Ads bağlantısı kaldırıldı.';
        redirect('/google');
    }
}
