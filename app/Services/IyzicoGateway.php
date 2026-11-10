<?php
declare(strict_types=1);

namespace App\Services;

use RuntimeException;

final class IyzicoGateway
{
    public function initializeCheckout(array $order, array $customer): array
    {
        $path = '/payment/iyzipos/checkoutform/initialize/auth/ecom';
        $conversationId = $order['merchant_order_id'];
        $nameParts = preg_split('/\s+/', trim((string) $customer['name']), 2) ?: [];
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
                'id' => (string) $order['user_id'],
                'name' => $nameParts[0] ?? 'Müşteri',
                'surname' => $nameParts[1] ?? 'AdGuard',
                'gsmNumber' => $customer['phone'],
                'email' => $customer['email'],
                'identityNumber' => $customer['identity_number'],
                'registrationAddress' => $customer['address'],
                'ip' => $customer['ip'],
                'city' => $customer['city'],
                'country' => 'Turkey',
                'zipCode' => $customer['zip']
            ],
            'billingAddress' => [
                'contactName' => $customer['name'],
                'city' => $customer['city'],
                'country' => 'Turkey',
                'address' => $customer['address'],
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
        $response = $this->request('POST', $path, $payload);
        if (($response['status'] ?? '') !== 'success' || empty($response['token'])) {
            throw new RuntimeException('iyzico ödeme formu oluşturulamadı: ' . (string) ($response['errorMessage'] ?? 'Sağlayıcı hatası'));
        }
        return [
            'token' => (string) $response['token'],
            'content' => (string) ($response['checkoutFormContent'] ?? $response['htmlContent'] ?? ''),
            'payment_page_url' => (string) ($response['paymentPageUrl'] ?? '')
        ];
    }

    public function initializeSubscription(array $order, array $customer, string $pricingPlanReference): array
    {
        $nameParts = preg_split('/\s+/', trim((string) $customer['name']), 2) ?: [];
        $address = [
            'address' => $customer['address'],
            'zipCode' => $customer['zip'],
            'contactName' => $customer['name'],
            'city' => $customer['city'],
            'country' => 'Turkey'
        ];
        $payload = [
            'locale' => 'tr',
            'conversationId' => $order['merchant_order_id'],
            'callbackUrl' => rtrim((string) env('APP_URL'), '/') . '/payments/iyzico/callback',
            'pricingPlanReferenceCode' => $pricingPlanReference,
            'subscriptionInitialStatus' => 'ACTIVE',
            'customer' => [
                'name' => $nameParts[0] ?? 'Müşteri',
                'surname' => $nameParts[1] ?? 'AdGuard',
                'email' => $customer['email'],
                'gsmNumber' => $customer['phone'],
                'identityNumber' => $customer['identity_number'],
                'billingAddress' => $address,
                'shippingAddress' => $address
            ]
        ];
        $response = $this->request('POST', '/v2/subscription/checkoutform/initialize', $payload);
        if (($response['status'] ?? '') !== 'success' || empty($response['token'])) {
            throw new RuntimeException('iyzico abonelik formu oluşturulamadı: ' . (string) ($response['errorMessage'] ?? 'Sağlayıcı hatası'));
        }
        return [
            'token' => (string) $response['token'],
            'content' => (string) ($response['checkoutFormContent'] ?? '')
        ];
    }

    public function retrieveCheckout(string $token): array
    {
        return $this->request('POST', '/payment/iyzipos/checkoutform/auth/ecom/detail', [
            'locale' => 'tr',
            'conversationId' => 'adguard-callback',
            'token' => $token
        ]);
    }

    public function retrieveSubscription(string $token): array
    {
        return $this->request('GET', '/v2/subscription/checkoutform/' . rawurlencode($token), []);
    }

    public function cancelSubscription(string $referenceCode): array
    {
        return $this->request('POST', '/v2/subscription/subscriptions/' . rawurlencode($referenceCode) . '/cancel', [
            'subscriptionReferenceCode' => $referenceCode
        ]);
    }

    private function request(string $method, string $path, array $payload): array
    {
        $apiKey = (string) env('IYZICO_API_KEY', '');
        $secret = (string) env('IYZICO_SECRET_KEY', '');
        if ($apiKey === '' || $secret === '') {
            throw new RuntimeException('iyzico API bilgileri yapılandırılmamış.');
        }
        $base = rtrim((string) env('IYZICO_BASE_URL', 'https://sandbox-api.iyzipay.com'), '/');
        $body = $method === 'GET' ? '' : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $random = (string) (int) (microtime(true) * 1000) . bin2hex(random_bytes(5));
        $signature = hash_hmac('sha256', $random . $path . $body, $secret);
        $authString = 'apiKey:' . $apiKey . '&randomKey:' . $random . '&signature:' . $signature;
        $authorization = 'IYZWSv2 ' . base64_encode($authString);
        $ch = curl_init($base . $path);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_POSTFIELDS => $method === 'GET' ? null : $body,
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
