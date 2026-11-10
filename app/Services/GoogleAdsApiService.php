<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Security;
use PDO;
use RuntimeException;
use Throwable;

final class GoogleAdsApiService
{
    public function __construct(
        private readonly PDO $db,
        private readonly GoogleOAuthService $oauth = new GoogleOAuthService()
    ) {}

    public function listCampaigns(int $userId, int $connectionId): array
    {
        [$connection, $accessToken] = $this->connectionAndToken($userId, $connectionId);
        return $this->oauth->listCampaigns(
            $accessToken,
            (string) $connection['customer_id'],
            $connection['login_customer_id'] ?: null
        );
    }

    public function excludeIpForCampaign(
        int $userId,
        int $connectionId,
        int $campaignId,
        string $ipAddress,
        bool $confirmLive = false
    ): array {
        $validateOnly = $this->validateOnly($confirmLive);
        if (filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new RuntimeException('Google Ads campaign IP exclusions require IPv4.');
        }

        [$connection, $accessToken] = $this->connectionAndToken($userId, $connectionId);
        $customerId = (string) $connection['customer_id'];
        $loginCustomerId = $connection['login_customer_id'] ?: null;

        $campaigns = $this->oauth->listCampaigns($accessToken, $customerId, $loginCustomerId);
        $selectedCampaign = null;
        foreach ($campaigns as $row) {
            $campaign = $row['campaign'] ?? [];
            if ((string) ($campaign['id'] ?? '') === (string) $campaignId) {
                $selectedCampaign = $campaign;
                break;
            }
        }

        if (!$selectedCampaign) {
            throw new RuntimeException('The selected campaign does not belong to this Google Ads account or is unavailable.');
        }

        $channel = (string) ($selectedCampaign['advertisingChannelType'] ?? '');
        $subChannel = (string) ($selectedCampaign['advertisingChannelSubType'] ?? '');
        if (!in_array($channel, ['SEARCH', 'DISPLAY', 'SHOPPING'], true)
            || $subChannel === 'DISPLAY_SMART_CAMPAIGN') {
            throw new RuntimeException('Campaign-level IP exclusions are not supported for this campaign type. Use account-wide exclusion instead.');
        }

        $escapedIp = addslashes($ipAddress);
        $existing = $this->oauth->search(
            $accessToken,
            $customerId,
            "SELECT campaign_criterion.resource_name
             FROM campaign_criterion
             WHERE campaign.id = {$campaignId}
               AND campaign_criterion.type = IP_BLOCK
               AND campaign_criterion.status != REMOVED
               AND campaign_criterion.ip_block.ip_address = '{$escapedIp}'",
            $loginCustomerId
        );
        if ($existing !== []) {
            return ['already_exists' => true, 'validate_only' => $validateOnly, 'response' => null];
        }

        $all = $this->oauth->search(
            $accessToken,
            $customerId,
            "SELECT campaign_criterion.resource_name
             FROM campaign_criterion
             WHERE campaign.id = {$campaignId}
               AND campaign_criterion.type = IP_BLOCK
               AND campaign_criterion.status != REMOVED",
            $loginCustomerId
        );
        if (count($all) >= 500) {
            throw new RuntimeException('The selected campaign has reached the 500 IP exclusion limit.');
        }

        $response = $this->oauth->excludeIpFromCampaign(
            $accessToken,
            $customerId,
            $campaignId,
            $ipAddress,
            $validateOnly,
            $loginCustomerId
        );

        return ['already_exists' => false, 'validate_only' => $validateOnly, 'response' => $response];
    }

    public function excludeIpForAccount(
        int $userId,
        int $connectionId,
        string $ipAddress,
        bool $confirmLive = false
    ): array {
        $validateOnly = $this->validateOnly($confirmLive);
        if (filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new RuntimeException('Google Ads account IP exclusions require IPv4.');
        }

        [$connection, $accessToken] = $this->connectionAndToken($userId, $connectionId);
        $result = $this->oauth->excludeIpFromAccount(
            $accessToken,
            (string) $connection['customer_id'],
            $ipAddress,
            $validateOnly,
            $connection['login_customer_id'] ?: null
        );
        $result['validate_only'] = $validateOnly;
        return $result;
    }

    public function removeIpExclusion(
        int $userId,
        int $connectionId,
        string $scope,
        string $ipAddress,
        ?int $campaignId = null,
        bool $confirmLive = false
    ): array {
        $validateOnly = $this->validateOnly($confirmLive);
        if (filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new RuntimeException('Google Ads IP exclusion removal requires IPv4.');
        }

        [$connection, $accessToken] = $this->connectionAndToken($userId, $connectionId);
        $customerId = (string) $connection['customer_id'];
        $loginCustomerId = $connection['login_customer_id'] ?: null;

        if ($scope === 'account') {
            $result = $this->oauth->removeIpFromAccount(
                $accessToken,
                $customerId,
                $ipAddress,
                $validateOnly,
                $loginCustomerId
            );
        } elseif ($scope === 'campaign' && $campaignId !== null && $campaignId > 0) {
            $result = $this->oauth->removeIpFromCampaign(
                $accessToken,
                $customerId,
                $campaignId,
                $ipAddress,
                $validateOnly,
                $loginCustomerId
            );
        } else {
            throw new RuntimeException('Invalid Google Ads IP exclusion scope.');
        }

        $result['validate_only'] = $validateOnly;
        return $result;
    }

    private function validateOnly(bool $confirmLive): bool
    {
        $config = require BASE_PATH . '/config/google.php';
        $writesEnabled = !empty($config['writes_enabled']);
        return !($writesEnabled && $confirmLive);
    }

    private function connectionAndToken(int $userId, int $connectionId): array
    {
        $stmt = $this->db->prepare(
            "SELECT id, customer_id, login_customer_id, access_token, refresh_token, token_expires_at, status
             FROM google_connections
             WHERE id = :id AND user_id = :user_id AND status = 'active'
             LIMIT 1"
        );
        $stmt->execute(['id' => $connectionId, 'user_id' => $userId]);
        $connection = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$connection) {
            throw new RuntimeException('Google Ads connection not found.');
        }

        $expires = $connection['token_expires_at'] ? strtotime((string) $connection['token_expires_at']) : 0;
        if ($expires <= time() + 180) {
            try {
                $refreshToken = Security::decrypt((string) $connection['refresh_token']);
                $tokens = $this->oauth->refreshAccessToken($refreshToken);
                $accessToken = (string) ($tokens['access_token'] ?? '');
                if ($accessToken === '') {
                    throw new RuntimeException('Google access token refresh failed.');
                }

                $refreshEncrypted = !empty($tokens['refresh_token'])
                    ? Security::encrypt((string) $tokens['refresh_token'])
                    : (string) $connection['refresh_token'];
                $expiresAt = date('Y-m-d H:i:s', time() + max(60, (int) ($tokens['expires_in'] ?? 3600)));
                $update = $this->db->prepare(
                    "UPDATE google_connections
                     SET access_token = :token, refresh_token = :refresh_token,
                         token_expires_at = :expires, status = 'active'
                     WHERE id = :id AND user_id = :user_id"
                );
                $update->execute([
                    'token' => Security::encrypt($accessToken),
                    'refresh_token' => $refreshEncrypted,
                    'expires' => $expiresAt,
                    'id' => $connectionId,
                    'user_id' => $userId
                ]);
            } catch (Throwable $exception) {
                $this->db->prepare(
                    "UPDATE google_connections SET status = 'expired' WHERE id = :id AND user_id = :user_id"
                )->execute(['id' => $connectionId, 'user_id' => $userId]);
                throw new RuntimeException('Google Ads connection expired. Reconnect the account.', 0, $exception);
            }
        } else {
            $accessToken = Security::decrypt((string) $connection['access_token']);
        }

        return [$connection, $accessToken];
    }
}
