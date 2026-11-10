<?php
declare(strict_types=1);

namespace App\Services;

use RuntimeException;

final class IyzicoGateway
{
    public function initializeCheckout(array $order, array $customer): array
    {
        $apiKey = (string) env('IYZICO_API_KEY', '');
        $secret = (string) env('IYZICO_SECRET_KEY', '');
        if ($apiKey === '' || $secret === '') {
            throw new RuntimeException('iyzico API bilgileri yapılandırılmamış.');
        }

        $base = rtrim((string) env('IYZICO_BASE_URL', 'https://sandbox-api.iyzipay.com'), '/');
        $path = '/payment/iyzipos/checkoutform/initialize/auth/ecom';
        $conversationId = $order['merchant_order_id'];
        $buyerId = (string) $order['user_id'];
        $nameParts = preg_split('/\s+/', trim((string) $customer['name']), 2) ?: [];
        $firstName = $nameParts[0] ?? 'Müşteri';
        $lastName = $nameParts[1] ?? 'AdGuard';
        $ip = filter_var($customer['ip'] ?? '', FILTER_VALIDATE_IP) ? $customer['ip'] : '127.0.0.1';
        $address = (string) $customer['address'];

        $payload = [
            'locale' => 'tr',
            'conversationId' => $conversationId,
            'price' => number_format((float) $order['amount'], 2, '.', ''),
            'paidPrice' => number_format((float) $order['amount'], 2, '.', ''),
            'currency' => strtoupper((string) $order['currency']),
            'basketId' => $conversationId,
            'paymentGroup' => 'SUBSCRIPTION',
            'callbackUrl' => rtrim((string) env('APP_URL'), '/') . '/payments/iyzico/callback',
            'enabledInstallments' => [1],
            'buyer' => [
                'id' => $buyerId,
                'name' => $firstName,
                'surname' => $lastName,
                'gsmNumber' => $customer['phone'],
                'email' => $customer['email'],
                'identityNumber' => (string) env('IYZICO_DEFAULT_IDENTITY_NUMBER', '11111111111'),
                'lastLoginDate' => date('Y-m-d H:i:s'),
                'registrationDate' => date('Y-m-d H:i:s'),
                'registrationAddress' => $address,
                'ip' => $ip,
                'city' => $customer['city'],
                'country' => 'Turkey',
                'zipCode' => $customer['zip']
            ],
            'shippingAddress' => [
                'contactName' => $customer['name'],
                'city' => $customer['city'],
                'country' => 'Turkey',
                'address' => $address,
                'zipCode' => $customer['zip']
            ],
            'billingAddress' => [
                'contactName' => $customer['name'],
                'city' => $customer['city'],
                'country' => 'Turkey',
                'address' => $address,
                'zipCode' => $customer['zip']
            ],
            'basketItems' => [[
                'id' => (string) $order['plan_id'],
                'name' => (string) $order['plan_name'],
                'category1' => 'Software',
                'itemType' => 'VIRTUAL',
                'price' => number_format((float) $order['amount'], 2, '.', '')
            ]]
        ];

        $response = $this->request('POST', $base, $path, $payload, $apiKey, $secret);
        if (($response['status'] ?? '') !== 'success' || empty($response['token'])) {
            throw new RuntimeException('iyzico ödeme formu oluşturulamadı: ' . (string) ($response['errorMessage'] ?? 'Sağlayıcı hatası'));
        }
        return [
            'token' => (string) $response['token'],
            'content' => (string) ($response['checkoutFormContent'] ?? ''),
            'payment_page_url' => (string) ($response['paymentPageUrl'] ?? '')
        ];
    }

    public function retrieveCheckout(string $token): array
    {
        $apiKey = (string) env('IYZICO_API_KEY', '');
        $secret = (string) env('IYZICO_SECRET_KEY', '');
        $base = rtrim((string) env('IYZICO_BASE_URL', 'https://sandbox-api.iyzipay.com'), '/');
        return $this->request('POST', $base, '/payment/iyzipos/checkoutform/auth/ecom/detail', [
            'locale' => 'tr',
            'conversationId' => 'adguard-callback',
            'token' => $token
        ], $apiKey, $secret);
    }

    private function request(string $method, string $base, string $path, array $payload, string $apiKey, string $secret): array
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $random = bin2hex(random_bytes(16));
        $signature = hash_hmac('sha256', $random . $path . $body, $secret);
        $authorization = 'IYZWSv2 ' . base64_encode($apiKey . ':' . $random . ':' . $signature);
        $ch = curl_init($base . $path);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: ' . $authorization,
                'x-iyzi-rnd: ' . $random
            ]
        ]);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($raw === false || $status < 200 || $status >= 300) {
            throw new RuntimeException('iyzico bağlantı hatası: ' . ($error ?: 'HTTP ' . $status));
        }
        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('iyzico geçersiz yanıt döndürdü.');
        }
        return $decoded;
    }
}
