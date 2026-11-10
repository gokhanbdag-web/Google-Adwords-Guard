<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Services\IyzicoGateway;
use App\Services\PaytrGateway;
use PDO;
use Throwable;

final class PaymentController
{
    public function checkout(): void
    {
        requireAuth();
        $planId = (int) ($_GET['plan'] ?? 0);
        $stmt = Database::connection()->prepare('SELECT * FROM plans WHERE id = :id AND is_active = 1 LIMIT 1');
        $stmt->execute(['id' => $planId]);
        $plan = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$plan) {
            http_response_code(404);
            exit('Paket bulunamadı.');
        }
        require BASE_PATH . '/resources/views/payments/checkout.php';
    }

    public function start(): void
    {
        requireAuth();
        $pdo = Database::connection();
        $planId = (int) ($_POST['plan_id'] ?? 0);
        $provider = (string) ($_POST['provider'] ?? '');
        $autoRenew = isset($_POST['auto_renew']) && $_POST['auto_renew'] === '1';
        $planStmt = $pdo->prepare('SELECT id,name,price,currency,billing_period FROM plans WHERE id = :id AND is_active = 1 LIMIT 1');
        $planStmt->execute(['id' => $planId]);
        $plan = $planStmt->fetch(PDO::FETCH_ASSOC);
        if (!$plan || !in_array($provider, ['paytr','iyzico'], true) || (float) $plan['price'] <= 0) {
            $_SESSION['error'] = 'Paket veya ödeme yöntemi geçersiz.';
            redirect('/pricing');
        }

        $name = trim((string) ($_POST['name'] ?? auth()['name']));
        $phone = preg_replace('/[^0-9+]/', '', (string) ($_POST['phone'] ?? ''));
        $address = trim((string) ($_POST['address'] ?? ''));
        $city = trim((string) ($_POST['city'] ?? ''));
        $zip = trim((string) ($_POST['zip'] ?? ''));
        if (mb_strlen($name) < 2 || strlen($phone) < 10 || mb_strlen($address) < 8 || mb_strlen($city) < 2 || strlen($zip) < 4) {
            $_SESSION['error'] = 'Fatura bilgilerini eksiksiz girin.';
            redirect('/payments/checkout?plan=' . $planId);
        }

        $orderId = 'AG' . date('ymdHis') . strtoupper(bin2hex(random_bytes(5)));
        $insert = $pdo->prepare(
            "INSERT INTO payments
             (user_id,plan_id,provider,merchant_order_id,amount,currency,status,auto_renew_requested)
             VALUES (:user,:plan,:provider,:order_id,:amount,:currency,'pending',:renew)"
        );
        $insert->execute([
            'user' => (int) auth()['id'], 'plan' => $planId, 'provider' => $provider,
            'order_id' => $orderId, 'amount' => $plan['price'], 'currency' => $plan['currency'],
            'renew' => $autoRenew ? 1 : 0
        ]);
        $order = [
            'user_id' => (int) auth()['id'], 'plan_id' => $planId, 'plan_name' => $plan['name'],
            'merchant_order_id' => $orderId, 'amount' => $plan['price'], 'currency' => $plan['currency']
        ];
        $customer = [
            'name' => $name, 'email' => auth()['email'], 'phone' => $phone,
            'address' => $address, 'city' => $city, 'zip' => $zip,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? ''
        ];

        try {
            if ($provider === 'paytr') {
                $result = (new PaytrGateway())->createIframeToken($order, $customer);
                $update = $pdo->prepare('UPDATE payments SET provider_token = :token WHERE merchant_order_id = :order_id');
                $update->execute(['token' => $result['token'], 'order_id' => $orderId]);
                $_SESSION['payment_iframe_url'] = $result['iframe_url'];
                redirect('/payments/paytr?order=' . rawurlencode($orderId));
            }

            $result = (new IyzicoGateway())->initializeCheckout($order, $customer);
            $update = $pdo->prepare('UPDATE payments SET provider_token = :token WHERE merchant_order_id = :order_id');
            $update->execute(['token' => $result['token'], 'order_id' => $orderId]);
            $_SESSION['iyzico_checkout_content'] = $result['content'];
            $_SESSION['iyzico_payment_url'] = $result['payment_page_url'];
            $_SESSION['iyzico_order_id'] = $orderId;
            redirect('/payments/iyzico');
        } catch (Throwable $e) {
            $fail = $pdo->prepare("UPDATE payments SET status = 'failed', failure_reason = :reason WHERE merchant_order_id = :order_id");
            $fail->execute(['reason' => mb_substr($e->getMessage(), 0, 500), 'order_id' => $orderId]);
            error_log('[AdGuard Payment] Checkout init failed: ' . $e::class . ': ' . $e->getMessage());
            $_SESSION['error'] = 'Ödeme başlatılamadı. Lütfen daha sonra tekrar deneyin.';
            redirect('/payments/checkout?plan=' . $planId);
        }
    }

    public function paytrPage(): void
    {
        requireAuth();
        $url = (string) ($_SESSION['payment_iframe_url'] ?? '');
        if ($url === '' || !str_starts_with($url, 'https://www.paytr.com/odeme/guvenli/')) {
            redirect('/pricing');
        }
        require BASE_PATH . '/resources/views/payments/paytr.php';
    }

    public function iyzicoPage(): void
    {
        requireAuth();
        $content = (string) ($_SESSION['iyzico_checkout_content'] ?? '');
        $paymentUrl = (string) ($_SESSION['iyzico_payment_url'] ?? '');
        if ($content === '' && !str_starts_with($paymentUrl, 'https://')) {
            redirect('/pricing');
        }
        require BASE_PATH . '/resources/views/payments/iyzico.php';
    }

    public function paytrCallback(): void
    {
        $data = $_POST;
        $gateway = new PaytrGateway();
        if (!$gateway->verifyCallback($data)) {
            http_response_code(400);
            exit('PAYTR notification failed: bad hash');
        }
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT * FROM payments WHERE merchant_order_id = :id FOR UPDATE');
            $stmt->execute(['id' => (string) ($data['merchant_oid'] ?? '')]);
            $payment = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$payment) {
                throw new \RuntimeException('Payment order not found.');
            }
            if ($payment['status'] === 'paid') {
                $pdo->commit();
                echo 'OK';
                return;
            }
            $expectedAmount = (int) round((float) $payment['amount'] * 100);
            if ((int) ($data['total_amount'] ?? -1) !== $expectedAmount) {
                throw new \RuntimeException('Payment amount mismatch.');
            }
            if (($data['status'] ?? '') !== 'success') {
                $update = $pdo->prepare("UPDATE payments SET status='failed', failure_reason=:reason WHERE id=:id");
                $update->execute(['reason' => mb_substr((string) ($data['failed_reason_msg'] ?? 'Payment failed'),0,500), 'id' => $payment['id']]);
                $pdo->commit();
                echo 'OK';
                return;
            }
            $this->activatePaidOrder($pdo, $payment, (string) ($data['merchant_oid'] ?? ''), (string) ($data['merchant_oid'] ?? ''));
            $pdo->commit();
            echo 'OK';
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('[AdGuard PayTR callback] ' . $e::class . ': ' . $e->getMessage());
            http_response_code(500);
            echo 'ERR';
        }
    }

    public function iyzicoCallback(): void
    {
        $token = trim((string) ($_POST['token'] ?? ''));
        if ($token === '') {
            http_response_code(400);
            exit('Missing token');
        }
        try {
            $detail = (new IyzicoGateway())->retrieveCheckout($token);
            if (($detail['paymentStatus'] ?? '') !== 'SUCCESS' || ($detail['status'] ?? '') !== 'success') {
                http_response_code(400);
                exit('Payment not successful');
            }
            $pdo = Database::connection();
            $stmt = $pdo->prepare('SELECT * FROM payments WHERE provider_token = :token AND provider = "iyzico" LIMIT 1');
            $stmt->execute(['token' => $token]);
            $payment = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$payment) {
                throw new \RuntimeException('iyzico payment order not found.');
            }
            if ((string) ($detail['basketId'] ?? '') !== $payment['merchant_order_id'] ||
                number_format((float) ($detail['paidPrice'] ?? 0), 2, '.', '') !== number_format((float) $payment['amount'], 2, '.', '') ||
                strtoupper((string) ($detail['currency'] ?? '')) !== strtoupper((string) $payment['currency'])) {
                throw new \RuntimeException('iyzico payment verification mismatch.');
            }
            $pdo->beginTransaction();
            $lock = $pdo->prepare('SELECT * FROM payments WHERE id = :id FOR UPDATE');
            $lock->execute(['id' => $payment['id']]);
            $payment = $lock->fetch(PDO::FETCH_ASSOC);
            if ($payment['status'] !== 'paid') {
                $this->activatePaidOrder($pdo, $payment, $token, (string) ($detail['paymentId'] ?? ''));
            }
            $pdo->commit();
            redirect('/payments/return');
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
            error_log('[AdGuard iyzico callback] ' . $e::class . ': ' . $e->getMessage());
            http_response_code(400);
            echo 'Payment verification failed';
        }
    }

    public function returnPage(): void
    {
        requireAuth();
        require BASE_PATH . '/resources/views/payments/return.php';
    }

    public function toggleRenewal(): void
    {
        requireAuth();
        $enabled = isset($_POST['auto_renew']) && $_POST['auto_renew'] === '1';
        $stmt = Database::connection()->prepare(
            "UPDATE subscriptions SET auto_renew = :enabled,
             renewal_consent_at = CASE WHEN :enabled = 1 THEN NOW() ELSE renewal_consent_at END,
             cancelled_at = CASE WHEN :enabled = 0 THEN NOW() ELSE NULL END
             WHERE user_id = :user_id AND status = 'active' AND ends_at > NOW()
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute(['enabled' => $enabled ? 1 : 0, 'user_id' => (int) auth()['id']]);
        $_SESSION['success'] = $enabled
            ? 'Otomatik yenileme tercihiniz kaydedildi. Sağlayıcı tarafında yenileme yetkisi bulunması gerekir.'
            : 'Otomatik yenileme kapatıldı. Mevcut dönem sonuna kadar erişiminiz devam eder.';
        redirect('/dashboard');
    }

    private function activatePaidOrder(PDO $pdo, array $payment, string $providerToken, string $providerPaymentId): void
    {
        $period = $pdo->prepare('SELECT billing_period FROM plans WHERE id = :id');
        $period->execute(['id' => $payment['plan_id']]);
        $billing = (string) $period->fetchColumn();
        $endExpression = $billing === 'yearly' ? 'DATE_ADD(NOW(), INTERVAL 1 YEAR)' : 'DATE_ADD(NOW(), INTERVAL 1 MONTH)';

        $expire = $pdo->prepare("UPDATE subscriptions SET status='expired', ends_at=NOW() WHERE user_id=:user AND status='active'");
        $expire->execute(['user' => $payment['user_id']]);
        $sub = $pdo->prepare(
            "INSERT INTO subscriptions
             (user_id,plan_id,status,starts_at,ends_at,payment_provider,provider_subscription_id,auto_renew,renewal_consent_at)
             VALUES (:user,:plan,'active',NOW(),{$endExpression},:provider,:provider_id,:renew,:consent)"
        );
        $sub->execute([
            'user' => $payment['user_id'], 'plan' => $payment['plan_id'],
            'provider' => $payment['provider'], 'provider_id' => $providerPaymentId ?: null,
            'renew' => (int) $payment['auto_renew_requested'],
            'consent' => (int) $payment['auto_renew_requested'] === 1 ? date('Y-m-d H:i:s') : null
        ]);
        $update = $pdo->prepare(
            "UPDATE payments SET status='paid', provider_payment_id=:provider_id,
             provider_response=:response, paid_at=NOW(), subscription_id=:subscription
             WHERE id=:id"
        );
        $update->execute([
            'provider_id' => $providerToken ?: null,
            'response' => json_encode(['verified' => true], JSON_THROW_ON_ERROR),
            'subscription' => (int) $pdo->lastInsertId(),
            'id' => $payment['id']
        ]);
    }
}
