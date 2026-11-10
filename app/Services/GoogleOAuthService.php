<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Security;
use PDO;
use RuntimeException;

final class GoogleOAuthService
{
    private array $config;

    public function __construct(private readonly PDO $db)
    {
        $this->config = require BASE_PATH . '/config/google.php';
    }

    public function authorizationUrl(): string
    {
        $clientId = trim((string) ($this->config['client_id'] ?? ''));
        $redirectUri = trim((string) ($this->config['redirect_uri'] ?? ''));

        if ($clientId === '' || $redirectUri === '') {
            throw new RuntimeException('Google OAuth Client ID ve Redirect URI ayarları eksik.');
        }

        $state = bin2hex(random_bytes(32));
        $_SESSION['google_oauth_state'] = $state;
        $_SESSION['google_oauth_started_at'] = time();

        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => (string) ($this->config['scope'] ?? 'https://www.googleapis.com/auth/adwords'),
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'state' => $state
        ]);
    }

    public function exchangeCode(string $code): array
    {
        $payload = $this->tokenRequest([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'client_id' => (string) $this->config['client_id'],
            'client_secret' => (string) $this->config['client_secret'],
            'redirect_uri' => (string) $this->config['redirect_uri']
        ]);

        if (empty($payload['access_token']) || empty($payload['refresh_token'])) {
            throw new RuntimeException(
                'Google refresh token döndürmedi. Google OAuth izinlerini kontrol edip yeniden bağlanın.'
            );
        }

        return $payload;
    }

    public function storeAccessibleAccounts(int $userId, array $tokens): array
    {
        if ($userId <= 0 || empty($tokens['access_token']) || empty($tokens['refresh_token'])) {
            throw new RuntimeException('Google OAuth token verisi eksik.');
        }

        $api = new GoogleAdsApiClient(
            (string) $tokens['access_token'],
            (string) ($this->config['api_version'] ?? 'v26')
        );
        $response = $api->listAccessibleCustomers();
        $resourceNames = $response['resourceNames'] ?? [];

        if (!is_array($resourceNames) || $resourceNames === []) {
            throw new RuntimeException('Bu Google hesabında erişilebilir Google Ads müşteri hesabı bulunamadı.');
        }

        $selectedQuery = $this->db->prepare(
            'SELECT COUNT(*) FROM google_connections WHERE user_id = :user_id AND is_selected = 1'
        );
        $selectedQuery->execute(['user_id' => $userId]);
        $hasSelected = (int) $selectedQuery->fetchColumn() > 0;

        $accessToken = Security::encrypt((string) $tokens['access_token']);
        $refreshToken = Security::encrypt((string) $tokens['refresh_token']);
        $expiresAt = date('Y-m-d H:i:s', time() + max(60, (int) ($tokens['expires_in'] ?? 3600)));
        $scope = (string) ($tokens['scope'] ?? '');

        $insert = $this->db->prepare(
            "INSERT INTO google_connections (
                user_id, customer_id, access_token, refresh_token,
                token_expires_at, scope, status, is_selected, created_at
            ) VALUES (
                :user_id, :customer_id, :access_token, :refresh_token,
                :token_expires_at, :scope, 'active', :is_selected, NOW()
            )
            ON DUPLICATE KEY UPDATE
                access_token = VALUES(access_token),
                refresh_token = IF(VALUES(refresh_token) <> '', VALUES(refresh_token), refresh_token),
                token_expires_at = VALUES(token_expires_at),
                scope = VALUES(scope),
                status = 'active'"
        );

        $saved = [];
        foreach ($resourceNames as $index => $resourceName) {
            if (!is_string($resourceName) || !preg_match('#^customers/([0-9]+)$#', $resourceName, $match)) {
                continue;
            }

            $customerId = $match[1];
            $isSelected = (!$hasSelected && $index === 0) ? 1 : 0;

            $insert->execute([
                'user_id' => $userId,
                'customer_id' => $customerId,
                'access_token' => $accessToken,
                'refresh_token' => $refreshToken,
                'token_expires_at' => $expiresAt,
                'scope' => $scope,
                'is_selected' => $isSelected
            ]);
            $saved[] = $customerId;
        }

        if ($saved === []) {
            throw new RuntimeException('Google Ads hesap listesi beklenen formatta dönmedi.');
        }

        return $saved;
    }

    public function connections(int $userId): array
    {
        $statement = $this->db->prepare(
            'SELECT id, customer_id, login_customer_id, status, is_selected, created_at
             FROM google_connections
             WHERE user_id = :user_id
             ORDER BY is_selected DESC, customer_id'
        );
        $statement->execute(['user_id' => $userId]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function selectedConnection(int $userId): ?array
    {
        $statement = $this->db->prepare(
            "SELECT * FROM google_connections
             WHERE user_id = :user_id AND is_selected = 1 AND status = 'active'
             LIMIT 1"
        );
        $statement->execute(['user_id' => $userId]);
        $connection = $statement->fetch(PDO::FETCH_ASSOC);
        return $connection ?: null;
    }

    public function connectionAccessToken(int $userId, string $customerId): string
    {
        $statement = $this->db->prepare(
            'SELECT id, access_token, refresh_token, token_expires_at, status
             FROM google_connections
             WHERE user_id = :user_id AND customer_id = :customer_id
             LIMIT 1'
        );
        $statement->execute([
            'user_id' => $userId,
            'customer_id' => $customerId
        ]);
        $connection = $statement->fetch(PDO::FETCH_ASSOC);

        if (!$connection || $connection['status'] !== 'active') {
            throw new RuntimeException('Google Ads hesabı bağlı veya aktif değil.');
        }

        $expiresAt = $connection['token_expires_at']
            ? strtotime((string) $connection['token_expires_at'])
            : 0;

        if ($expiresAt > time() + 300 && !empty($connection['access_token'])) {
            return Security::decrypt((string) $connection['access_token']);
        }

        $refreshToken = Security::decrypt((string) $connection['refresh_token']);
        if ($refreshToken === '') {
            throw new RuntimeException('Google refresh token bulunamadı. Hesabı yeniden bağlayın.');
        }

        try {
            $tokens = $this->tokenRequest([
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken,
                'client_id' => (string) $this->config['client_id'],
                'client_secret' => (string) $this->config['client_secret']
            ]);
        } catch (\Throwable $exception) {
            $update = $this->db->prepare(
                "UPDATE google_connections SET status = 'expired' WHERE id = :id"
            );
            $update->execute(['id' => (int) $connection['id']]);
            throw new RuntimeException('Google Ads bağlantısı yenilenemedi. Hesabı yeniden bağlayın.', 0, $exception);
        }

        if (empty($tokens['access_token'])) {
            throw new RuntimeException('Google yeni access token döndürmedi.');
        }

        $newAccessToken = (string) $tokens['access_token'];
        $newRefreshToken = !empty($tokens['refresh_token'])
            ? Security::encrypt((string) $tokens['refresh_token'])
            : (string) $connection['refresh_token'];
        $newExpiry = date('Y-m-d H:i:s', time() + max(60, (int) ($tokens['expires_in'] ?? 3600)));

        $update = $this->db->prepare(
            'UPDATE google_connections
             SET access_token = :access_token,
                 refresh_token = :refresh_token,
                 token_expires_at = :expires_at
             WHERE id = :id'
        );
        $update->execute([
            'access_token' => Security::encrypt($newAccessToken),
            'refresh_token' => $newRefreshToken,
            'expires_at' => $newExpiry,
            'id' => (int) $connection['id']
        ]);

        return $newAccessToken;
    }

    private function tokenRequest(array $parameters): array
    {
        $curl = curl_init('https://oauth2.googleapis.com/token');
        if ($curl === false) {
            throw new RuntimeException('Google OAuth bağlantısı başlatılamadı.');
        }

        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($parameters),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2
        ]);

        $body = curl_exec($curl);
        $curlError = curl_error($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($body === false) {
            throw new RuntimeException('Google OAuth bağlantısı başarısız: ' . $curlError);
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Google OAuth geçersiz yanıt döndürdü.');
        }

        if ($status < 200 || $status >= 300) {
            $message = (string) ($decoded['error_description'] ?? $decoded['error'] ?? 'Unknown OAuth error.');
            throw new RuntimeException('Google OAuth (' . $status . '): ' . $message);
        }

        return $decoded;
    }
}
