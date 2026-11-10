<?php
declare(strict_types=1);

namespace App\Services;

use RuntimeException;

final class GoogleOAuthService
{
    private string $clientId;
    private string $clientSecret;
    private string $redirectUri;
    private string $developerToken;

    public function __construct()
    {
        $config = require BASE_PATH . '/config/google.php';
        $this->clientId = (string) ($config['client_id'] ?? '');
        $this->clientSecret = (string) ($config['client_secret'] ?? '');
        $this->redirectUri = (string) ($config['redirect_uri'] ?? '');
        $this->developerToken = (string) ($config['developer_token'] ?? '');
        if ($this->clientId === '' || $this->clientSecret === '' || $this->redirectUri === '') {
            throw new RuntimeException('Google OAuth configuration is incomplete.');
        }
        if ($this->developerToken === '') {
            throw new RuntimeException('Google Ads developer token is not configured.');
        }
    }

    public function authorizationUrl(string $state): string
    {
        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'response_type' => 'code',
            'scope' => 'https://www.googleapis.com/auth/adwords',
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'state' => $state
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function exchangeCode(string $code): array
    {
        return $this->tokenRequest([
            'code' => $code,
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'redirect_uri' => $this->redirectUri,
            'grant_type' => 'authorization_code'
        ]);
    }

    public function refreshAccessToken(string $refreshToken): array
    {
        return $this->tokenRequest([
            'refresh_token' => $refreshToken,
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'grant_type' => 'refresh_token'
        ]);
    }

    public function listAccessibleCustomers(string $accessToken): array
    {
        $response = $this->request(
            'https://googleads.googleapis.com/' . $this->apiVersion() . '/customers:listAccessibleCustomers',
            'GET',
            null,
            ['Authorization: Bearer ' . $accessToken]
        );
        $ids = [];
        foreach (($response['resourceNames'] ?? []) as $name) {
            if (preg_match('#^customers/([0-9]{6,20})$#', (string) $name, $match)) {
                $ids[] = $match[1];
            }
        }
        return array_values(array_unique($ids));
    }

    public function search(string $accessToken, string $customerId, string $query): array
    {
        $this->assertCustomerId($customerId);
        $response = $this->request(
            'https://googleads.googleapis.com/' . $this->apiVersion() . '/customers/' . $customerId . '/googleAds:search',
            'POST',
            ['query' => $query],
            ['Authorization: Bearer ' . $accessToken]
        );
        return $response['results'] ?? [];
    }

    public function listCampaigns(string $accessToken, string $customerId): array
    {
        return $this->search(
            $accessToken,
            $customerId,
            "SELECT campaign.id, campaign.name, campaign.status
             FROM campaign
             WHERE campaign.status != 'REMOVED'
             ORDER BY campaign.name"
        );
    }

    public function excludeIpFromCampaign(
        string $accessToken,
        string $customerId,
        int $campaignId,
        string $ipAddress
    ): array {
        $this->assertCustomerId($customerId);
        if ($campaignId <= 0 || filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new RuntimeException('Google Ads IP exclusion requires a valid IPv4 address and campaign.');
        }

        return $this->request(
            'https://googleads.googleapis.com/' . $this->apiVersion() . '/customers/' . $customerId . '/campaignCriteria:mutate',
            'POST',
            [
                'operations' => [[
                    'create' => [
                        'campaign' => 'customers/' . $customerId . '/campaigns/' . $campaignId,
                        'ipBlock' => ['ipAddress' => $ipAddress]
                    ]
                ]],
                'partialFailure' => false,
                'validateOnly' => false
            ],
            ['Authorization: Bearer ' . $accessToken]
        );
    }

    private function tokenRequest(array $payload): array
    {
        return $this->request(
            'https://oauth2.googleapis.com/token',
            'POST',
            $payload,
            ['Content-Type: application/x-www-form-urlencoded']
        );
    }

    private function request(string $url, string $method, ?array $payload, array $headers): array
    {
        $isForm = in_array('Content-Type: application/x-www-form-urlencoded', $headers, true);
        if (!$isForm && str_starts_with($url, 'https://googleads.googleapis.com/')) {
            $headers[] = 'developer-token: ' . $this->developerToken;
        }
        if ($payload !== null && !$isForm) {
            $headers[] = 'Content-Type: application/json';
        }

        $curl = curl_init($url);
        if ($curl === false) {
            throw new RuntimeException('Unable to initialize HTTP client.');
        }
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2
        ];
        if ($payload !== null) {
            $options[CURLOPT_POSTFIELDS] = $isForm
                ? http_build_query($payload, '', '&', PHP_QUERY_RFC3986)
                : json_encode($payload, JSON_THROW_ON_ERROR);
        }
        curl_setopt_array($curl, $options);
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if (!is_string($body) || $status < 200 || $status >= 300) {
            error_log('[AdGuard Google API] HTTP ' . $status . ' ' . $error . ' ' . substr((string) $body, 0, 1000));
            throw new RuntimeException('Google Ads API request failed. Verify the developer token, account access and campaign permissions.');
        }
        try {
            $decoded = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new RuntimeException('Google API returned an invalid response.');
        }
        if (!is_array($decoded)) {
            throw new RuntimeException('Google API returned an unexpected response.');
        }
        return $decoded;
    }

    private function assertCustomerId(string $customerId): void
    {
        if (!preg_match('/^[0-9]{6,20}$/', $customerId)) {
            throw new RuntimeException('Invalid Google Ads customer ID.');
        }
    }

    private function apiVersion(): string
    {
        $config = require BASE_PATH . '/config/google.php';
        $version = (string) ($config['api_version'] ?? 'v22');
        if (!preg_match('/^v[0-9]+$/', $version)) {
            throw new RuntimeException('Invalid Google Ads API version.');
        }
        return $version;
    }
}
