<?php
declare(strict_types=1);

namespace App\Services;

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
        [$connection, $accessToken] = $this->connectionAndToken($userId, $connectionId);
        return $this->oauth->listCampaigns($accessToken, (string) $connection['customer_id']);
    }

    public function excludeIpForCampaign(
        int $userId,
        int $connectionId,
        int $campaignId,
        string $ipAddress
    ): array {
        $config = require BASE_PATH . '/config/google.php';
        if (empty($config['writes_enabled'])) {
            throw new RuntimeException('Google Ads writes are disabled. Enable GOOGLE_ADS_WRITES_ENABLED only after reviewing the account.');
        }
        if (filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new RuntimeException('Google Ads campaign IP exclusions currently require IPv4.');
        }

        [$connection, $accessToken] = $this->connectionAndToken($userId, $connectionId);
        $customerId = (string) $connection['customer_id'];

        $campaigns = $this->oauth->listCampaigns($accessToken, $customerId);
        $campaignFound = false;
        foreach ($campaigns as $row) {
            if ((string) ($row['campaign']['id'] ?? '') === (string) $campaignId) {
                $campaignFound = true;
                break;
            }
        }
        if (!$campaignFound) {
            throw new RuntimeException('The selected campaign does not belong to this Google Ads account or is unavailable.');
        }

        $existing = $this->oauth->search(
            $accessToken,
            $customerId,
            "SELECT campaign_criterion.ip_block.ip_address
             FROM campaign_criterion
             WHERE campaign.id = {$campaignId}
               AND campaign_criterion.type = IP_BLOCK
               AND campaign_criterion.status != REMOVED"
        );
        foreach ($existing as $row) {
            if ((string) ($row['campaignCriterion']['ipBlock']['ipAddress'] ?? '') === $ipAddress) {
                return ['already_exists' => true, 'response' => null];
            }
        }

        $response = $this->oauth->excludeIpFromCampaign(
            $accessToken,
            $customerId,
            $campaignId,
            $ipAddress
        );
        return ['already_exists' => false, 'response' => $response];
    }

    private function connectionAndToken(int $userId, int $connectionId): array
    {
        $stmt = $this->db->prepare(
            "SELECT id, customer_id, access_token, refresh_token, token_expires_at, status
             FROM google_connections
             WHERE id = :id AND user_id = :user_id AND status = 'active'
             LIMIT 1"
        );
        $stmt->execute(['id' => $connectionId, 'user_id' => $userId]);
        $connection = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$connection) {
            throw new RuntimeException('Google Ads connection not found.');
        }

        $expires = $connection['token_expires_at'] ? strtotime($connection['token_expires_at']) : 0;
        if ($expires <= time() + 120) {
            $refreshToken = Security::decrypt((string) $connection['refresh_token']);
            $tokens = $this->oauth->refreshAccessToken($refreshToken);
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
        return [$connection, $accessToken];
    }
}
