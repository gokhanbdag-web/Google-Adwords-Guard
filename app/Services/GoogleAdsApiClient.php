<?php
declare(strict_types=1);

namespace App\Services;

use RuntimeException;

final class GoogleAdsApiClient
{
    public function __construct(
        private readonly string $accessToken,
        private readonly string $apiVersion = 'v26',
        private readonly ?string $loginCustomerId = null
    ) {
        if ($accessToken === '') {
            throw new RuntimeException('Google Ads access token is missing.');
        }
        if (!preg_match('/^v[0-9]+$/', $apiVersion)) {
            throw new RuntimeException('Google Ads API version is invalid.');
        }
    }

    public function listAccessibleCustomers(): array
    {
        return $this->request('GET', 'customers:listAccessibleCustomers');
    }

    public function listCampaigns(string $customerId): array
    {
        $customerId = $this->normalizeCustomerId($customerId);
        $query = "SELECT campaign.id, campaign.name, campaign.status, campaign.advertising_channel_type
                  FROM campaign
                  WHERE campaign.status != 'REMOVED'
                  ORDER BY campaign.name";
        $rows = $this->search($customerId, $query);
        $campaigns = [];

        foreach ($rows as $row) {
            $campaign = $row['campaign'] ?? [];
            if (!isset($campaign['id'])) {
                continue;
            }
            $campaigns[] = [
                'id' => (string) $campaign['id'],
                'name' => (string) ($campaign['name'] ?? ''),
                'status' => (string) ($campaign['status'] ?? ''),
                'channel' => (string) ($campaign['advertisingChannelType'] ?? '')
            ];
        }

        return $campaigns;
    }

    public function createIpExclusion(
        string $customerId,
        string $campaignId,
        string $ipAddress,
        bool $validateOnly = true
    ): array {
        $customerId = $this->normalizeCustomerId($customerId);
        if (!preg_match('/^[0-9]+$/', $campaignId)) {
            throw new RuntimeException('Campaign ID is invalid.');
        }
        if (filter_var($ipAddress, FILTER_VALIDATE_IP) === false) {
            throw new RuntimeException('IP address is invalid.');
        }

        $campaignResource = "customers/{$customerId}/campaigns/{$campaignId}";
        $escapedIp = addslashes($ipAddress);
        $query = "SELECT campaign_criterion.resource_name,
                         campaign_criterion.ip_block.ip_address
                  FROM campaign_criterion
                  WHERE campaign_criterion.campaign = '{$campaignResource}'
                    AND campaign_criterion.type = IP_BLOCK
                    AND campaign_criterion.status != REMOVED
                    AND campaign_criterion.ip_block.ip_address = '{$escapedIp}'";

        $existing = $this->search($customerId, $query);
        if ($existing !== []) {
            return [
                'already_exists' => true,
                'validate_only' => $validateOnly,
                'resource_name' => $existing[0]['campaignCriterion']['resourceName'] ?? null
            ];
        }

        return $this->request(
            'POST',
            "customers/{$customerId}/campaignCriteria:mutate",
            [
                'operations' => [
                    [
                        'create' => [
                            'campaign' => $campaignResource,
                            'negative' => true,
                            'ipBlock' => ['ipAddress' => $ipAddress]
                        ]
                    ]
                ],
                'partialFailure' => false,
                'validateOnly' => $validateOnly
            ]
        );
    }

    private function search(string $customerId, string $query): array
    {
        $allRows = [];
        $pageToken = null;
        $pageCount = 0;

        do {
            $payload = ['query' => $query, 'pageSize' => 1000];
            if ($pageToken !== null) {
                $payload['pageToken'] = $pageToken;
            }

            $response = $this->request(
                'POST',
                'customers/' . $this->normalizeCustomerId($customerId) . '/googleAds:search',
                $payload
            );

            $rows = $response['results'] ?? [];
            if (is_array($rows)) {
                $allRows = array_merge($allRows, $rows);
            }

            $pageToken = $response['nextPageToken'] ?? null;
            $pageCount++;
        } while (is_string($pageToken) && $pageToken !== '' && $pageCount < 10);

        return $allRows;
    }

    private function request(string $method, string $path, ?array $payload = null): array
    {
        $url = 'https://googleads.googleapis.com/' . $this->apiVersion . '/' . ltrim($path, '/');
        $headers = [
            'Authorization: Bearer ' . $this->accessToken,
            'Accept: application/json',
            'Content-Type: application/json'
        ];

        if ($this->loginCustomerId !== null && $this->loginCustomerId !== '') {
            $headers[] = 'login-customer-id: ' . $this->normalizeCustomerId($this->loginCustomerId);
        }

        $curl = curl_init($url);
        if ($curl === false) {
            throw new RuntimeException('Unable to initialize Google Ads API request.');
        }

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 40,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2
        ];

        if ($payload !== null) {
            $json = json_encode(
                $payload,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
            $options[CURLOPT_POSTFIELDS] = $json;
        }

        curl_setopt_array($curl, $options);
        $body = curl_exec($curl);
        $curlError = curl_error($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($body === false) {
            throw new RuntimeException('Google Ads API connection failed: ' . $curlError);
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Google Ads API returned an invalid response.');
        }

        if ($status < 200 || $status >= 300) {
            $message = (string) ($decoded['error']['message'] ?? 'Unknown Google Ads API error.');
            throw new RuntimeException('Google Ads API (' . $status . '): ' . $message);
        }

        return $decoded;
    }

    private function normalizeCustomerId(string $customerId): string
    {
        $customerId = preg_replace('/[^0-9]/', '', $customerId) ?? '';
        if (!preg_match('/^[0-9]{6,20}$/', $customerId)) {
            throw new RuntimeException('Google Ads customer ID is invalid.');
        }
        return $customerId;
    }
}
