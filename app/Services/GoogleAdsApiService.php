<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Security;
use PDO;
use RuntimeException;

final class GoogleAdsApiService
{
    public function __construct(
        private readonly PDO $db,
        private readonly GoogleOAuthService $oauth = new GoogleOAuthService()
    ) {}

    public function listCampaigns(int $userId, int $connectionId): array
    {
        $stmt = $this->db->prepare(
            "SELECT id, customer_id, access_token, refresh_token, token_expires_at, status
             FROM google_connections WHERE id = :id AND user_id = :user_id
             AND status = 'active' LIMIT 1"
        );
        $stmt->execute(['id' => $connectionId, 'user_id' => $userId]);
        $connection = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$connection) {
            throw new RuntimeException('Google Ads connection not found.');
        }

        $expires = $connection['token_expires_at'] ? strtotime($connection['token_expires_at']) : 0;
        if ($expires <= time() + 120) {
            $refresh = Security::decrypt((string) $connection['refresh_token']);
            $tokens = $this->oauth->refreshAccessToken($refresh);
            $accessToken = (string) ($tokens['access_token'] ?? '');
            if ($accessToken === '') {
                throw new RuntimeException('Google access token refresh failed.');
            }
            $expiresAt = date('Y-m-d H:i:s', time() + (int) ($tokens['expires_in'] ?? 3600));
            $update = $this->db->prepare(
                "UPDATE google_connections SET access_token = :token,
                 token_expires_at = :expires WHERE id = :id AND user_id = :user_id"
            );
            $update->execute([
                'token' => Security::encrypt($accessToken),
                'expires' => $expiresAt,
                'id' => $connectionId,
                'user_id' => $userId
            ]);
        } else {
            $accessToken = Security::decrypt((string) $connection['access_token']);
        }

        return $this->oauth->listCampaigns($accessToken, (string) $connection['customer_id']);
    }
}
