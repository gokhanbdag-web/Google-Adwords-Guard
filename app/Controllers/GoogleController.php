<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Security;
use App\Services\GoogleOAuthService;
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
            redirect((new GoogleOAuthService())->authorizationUrl($state));
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
            $service = new GoogleOAuthService();
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
                    'access_token' => Security::encrypt($accessToken),
                    'refresh_token' => Security::encrypt($refreshToken),
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
