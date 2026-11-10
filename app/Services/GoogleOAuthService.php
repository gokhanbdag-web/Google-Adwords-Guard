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
        if ($this->developerToken === '') {
            throw new RuntimeException('Google Ads developer token is not configured.');
        }
        $response = $this->request(
            'https://googleads.googleapis.com/' . $this->apiVersion() . '/customers:listAccessibleCustomers',
            'GET',
            null,
            [
                'Authorization: Bearer ' . $accessToken,
                'developer-token: ' . $this->developerToken
            ]
        );
        $names = $response['resourceNames'] ?? [];
        $ids = [];
        foreach ($names as $name) {
            if (preg_match('#^customers/([0-9]{6,20})$#', (string) $name, $match)) {
                $ids[] = $match[1];
            }
        }
        return array_values(array_unique($ids));
    }

    public function listCampaigns(string $accessToken, string $customerId): array
    {
        if (!preg_match('/^[0-9]{6,20}$/', $customerId)) {
            throw new RuntimeException('Invalid Google Ads customer ID.');
        }
        $response = $this->request(
            'https://googleads.googleapis.com/' . $this->apiVersion() . '/customers/' . $customerId . '/googleAds:search',
            'POST',
            ['query' => "SELECT campaign.id, campaign.name, campaign.status FROM campaign WHERE campaign.status != 'REMOVED' ORDER BY campaign.name"],
            [
                'Authorization: Bearer ' . $accessToken,
                'developer-token: ' . $this->developerToken,
                'Content-Type: application/json'
            ]
        );
        return $response['results'] ?? [];
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
            $options[CURLOPT_POSTFIELDS] = str_contains(implode(' ', $headers), 'application/x-www-form-urlencoded')
                ? http_build_query($payload, '', '&', PHP_QUERY_RFC3986)
                : json_encode($payload, JSON_THROW_ON_ERROR);
        }
        curl_setopt_array($curl, $options);
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if (!is_string($body) || $status < 200 || $status >= 300) {
            error_log('[AdGuard Google API] HTTP ' . $status . ' ' . $error);
            throw new RuntimeException('Google API request failed. Check the Google connection and API configuration.');
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
