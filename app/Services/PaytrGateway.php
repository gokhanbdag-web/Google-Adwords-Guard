<?php
declare(strict_types=1);

namespace App\Services;

use RuntimeException;

final class PaytrGateway
{
    public function createIframeToken(array $order, array $customer): array
    {
        $merchantId = (string) env('PAYTR_MERCHANT_ID', '');
        $merchantKey = (string) env('PAYTR_MERCHANT_KEY', '');
        $merchantSalt = (string) env('PAYTR_MERCHANT_SALT', '');
        if ($merchantId === '' || $merchantKey === '' || $merchantSalt === '') {
            throw new RuntimeException('PayTR API bilgileri yapılandırılmamış.');
        }

        $amountKurus = (int) round(((float) $order['amount']) * 100);
        $basket = base64_encode(json_encode([[
            (string) $order['plan_name'],
            number_format((float) $order['amount'], 2, '.', ''),
            1
        ]], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        $userIp = (string) ($customer['ip'] ?? '');
        if (!filter_var($userIp, FILTER_VALIDATE_IP)) {
            $userIp = '127.0.0.1';
        }

        $noInstallment = (string) env('PAYTR_NO_INSTALLMENT', '1');
        $maxInstallment = (string) env('PAYTR_MAX_INSTALLMENT', '0');
        $testMode = filter_var(env('PAYTR_TEST_MODE', '1'), FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
        $currency = strtoupper((string) $order['currency']);
        $hashStr = $merchantId . $userIp . $order['merchant_order_id'] .
            $customer['email'] . $amountKurus . $basket .
            $noInstallment . $maxInstallment . $currency . $testMode;
        $token = base64_encode(hash_hmac('sha256', $hashStr . $merchantSalt, $merchantKey, true));

        $payload = [
            'merchant_id' => $merchantId,
            'user_ip' => $userIp,
            'merchant_oid' => $order['merchant_order_id'],
            'email' => $customer['email'],
            'payment_amount' => $amountKurus,
            'paytr_token' => $token,
            'user_basket' => $basket,
            'debug_on' => $testMode,
            'no_installment' => $noInstallment,
            'max_installment' => $maxInstallment,
            'user_name' => $customer['name'],
            'user_address' => $customer['address'],
            'user_phone' => $customer['phone'],
            'merchant_ok_url' => rtrim((string) env('APP_URL'), '/') . '/payments/return',
            'merchant_fail_url' => rtrim((string) env('APP_URL'), '/') . '/payments/return',
            'timeout_limit' => '30',
            'currency' => $currency,
            'test_mode' => $testMode,
            'lang' => 'tr'
        ];

        $response = $this->postForm('https://www.paytr.com/odeme/api/get-token', $payload);
        if (($response['status'] ?? '') !== 'success' || empty($response['token'])) {
            throw new RuntimeException('PayTR ödeme oturumu oluşturulamadı: ' . (string) ($response[' reason'] ?? $response['reason'] ?? 'Sağlayıcı hatası'));
        }

        return ['token' => (string) $response['token'], 'iframe_url' => 'https://www.paytr.com/odeme/guvenli/' . rawurlencode((string) $response['token'])];
    }

    public function verifyCallback(array $data): bool
    {
        $key = (string) env('PAYTR_MERCHANT_KEY', '');
        $salt = (string) env('PAYTR_MERCHANT_SALT', '');
        if ($key === '' || $salt === '') {
            return false;
        }
        $expected = base64_encode(hash_hmac(
            'sha256',
            (string) ($data['merchant_oid'] ?? '') . $salt .
            (string) ($data['status'] ?? '') . (string) ($data['total_amount'] ?? ''),
            $key,
            true
        ));
        return isset($data['hash']) && hash_equals($expected, (string) $data['hash']);
    }

    private function postForm(string $url, array $data): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($data),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded']
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($body === false || $status !== 200) {
            throw new RuntimeException('PayTR bağlantı hatası: ' . ($error ?: 'HTTP ' . $status));
        }
        $decoded = json_decode((string) $body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('PayTR geçersiz yanıt döndürdü.');
        }
        return $decoded;
    }
}
