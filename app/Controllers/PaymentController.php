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
        $stmt = Database::connection()->prepare('SELECT * FROM plans WHERE id = :id AND is_active = 1 LIMIT 1');
        $stmt->execute(['id' => (int) ($_GET['plan'] ?? 0)]);
        $plan = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$plan) { http_response_code(404); exit('Paket bulunamadı.'); }
        require BASE_PATH . '/resources/views/payments/checkout.php';
    }

    public function start(): void
    {
        requireAuth();
        $pdo = Database::connection();
        $planId = (int) ($_POST['plan_id'] ?? 0);
        $provider = (string) ($_POST['provider'] ?? '');
        $autoRenew = isset($_POST['auto_renew']) && $_POST['auto_renew'] === '1';
        $planStmt = $pdo->prepare('SELECT id,name,price,currency,billing_period FROM plans WHERE id=:id AND is_active=1 LIMIT 1');
        $planStmt->execute(['id' => $planId]);
        $plan = $planStmt->fetch(PDO::FETCH_ASSOC);
        if (!$plan || !in_array($provider, ['paytr','iyzico'], true) || (float) $plan['price'] <= 0) {
            $_SESSION['error'] = 'Paket veya ödeme yöntemi geçersiz.'; redirect('/pricing');
        }
        $name = trim((string) ($_POST['name'] ?? auth()['name']));
        $phone = preg_replace('/[^0-9+]/', '', (string) ($_POST['phone'] ?? ''));
        $address = trim((string) ($_POST['address'] ?? ''));
        $city = trim((string) ($_POST['city'] ?? ''));
        $zip = trim((string) ($_POST['zip'] ?? ''));
        $identity = preg_replace('/\D/', '', (string) ($_POST['identity_number'] ?? ''));
        if (mb_strlen($name) < 2 || strlen($phone) < 10 || mb_strlen($address) < 8 ||
            mb_strlen($city) < 2 || strlen($zip) < 4 || ($provider === 'iyzico' && strlen($identity) !== 11)) {
            $_SESSION['error'] = 'Fatura bilgilerini eksiksiz ve doğru girin. iyzico için 11 haneli T.C. kimlik numarası gerekir.';
            redirect('/payments/checkout?plan=' . $planId);
        }
        if ($autoRenew && $provider === 'paytr') {
            $_SESSION['error'] = 'PayTR otomatik yenileme, mağazanızda kart saklama ve tekrarlayan ödeme yetkileri açılıp kayıtlı kart akışı tamamlandıktan sonra sunulacaktır. Şimdilik tek çekim seçebilirsiniz.';
            redirect('/payments/checkout?plan=' . $planId);
        }

        $orderId = 'AG' . date('ymdHis') . strtoupper(bin2hex(random_bytes(5)));
        $insert = $pdo->prepare(
            "INSERT INTO payments (user_id,plan_id,provider,merchant_order_id,amount,currency,status,auto_renew_requested,auto_renew_consent_at)
             VALUES (:user,:plan,:provider,:order_id,:amount,:currency,'pending',:renew,:consent)"
        );
        $insert->execute([
            'user' => (int) auth()['id'], 'plan' => $planId, 'provider' => $provider,
            'order_id' => $orderId, 'amount' => $plan['price'], 'currency' => $plan['currency'],
            'renew' => $autoRenew ? 1 : 0, 'consent' => $autoRenew ? date('Y-m-d H:i:s') : null
        ]);
        $order = [
            'user_id' => (int) auth()['id'], 'plan_id' => $planId, 'plan_name' => $plan['name'],
            'merchant_order_id' => $orderId, 'amount' => $plan['price'], 'currency' => $plan['currency']
        ];
        $customer = [
            'name' => $name, 'email' => auth()['email'], 'phone' => $phone,
            'address' => $address, 'city' => $city, 'zip' => $zip,
            'identity_number' => $identity,
            'ip' => filter_var($_SERVER['REMOTE_ADDR'] ?? '', FILTER_VALIDATE_IP) ? $_SERVER['REMOTE_ADDR'] : '127.0.0.1'
        ];
        try {
            if ($provider === 'paytr') {
                $result = (new PaytrGateway())->createIframeToken($order, $customer);
                $pdo->prepare('UPDATE payments SET provider_token=:token WHERE merchant_order_id=:order_id')
                    ->execute(['token' => $result['token'], 'order_id' => $orderId]);
                $_SESSION['payment_iframe_url'] = $result['iframe_url'];
                redirect('/payments/paytr?order=' . rawurlencode($orderId));
            }
            if ($autoRenew) {
                $mapping = $pdo->prepare("SELECT provider_plan_reference FROM payment_provider_plans WHERE plan_id=:plan AND provider='iyzico' AND is_active=1 LIMIT 1");
                $mapping->execute(['plan' => $planId]);
                $providerPlan = (string) $mapping->fetchColumn();
                if ($providerPlan === '') {
                    throw new \RuntimeException('Bu paket için iyzico abonelik planı tanımlanmamış. Yönetici panelinde iyzico plan referans kodu tanımlanmalıdır.');
                }
                $result = (new IyzicoGateway())->initializeSubscription($order, $customer, $providerPlan);
            } else {
                $result = (new IyzicoGateway())->initializeCheckout($order, $customer);
            }
            $pdo->prepare('UPDATE payments SET provider_token=:token WHERE merchant_order_id=:order_id')
                ->execute(['token' => $result['token'], 'order_id' => $orderId]);
            $_SESSION['iyzico_checkout_content'] = $result['content'];
            $_SESSION['iyzico_payment_url'] = '';
            redirect('/payments/iyzico');
        } catch (Throwable $e) {
            $pdo->prepare("UPDATE payments SET status='failed',failure_reason=:reason WHERE merchant_order_id=:order_id")
                ->execute(['reason' => mb_substr($e->getMessage(),0,500),'order_id'=>$orderId]);
            error_log('[AdGuard Payment] Checkout init failed: ' . $e::class . ': ' . $e->getMessage());
            $_SESSION['error'] = $e instanceof \RuntimeException && str_contains($e->getMessage(), 'iyzico abonelik planı')
                ? $e->getMessage() : 'Ödeme başlatılamadı. Lütfen daha sonra tekrar deneyin.';
            redirect('/payments/checkout?plan=' . $planId);
        }
    }

    public function paytrPage(): void
    {
        requireAuth();
        $url = (string) ($_SESSION['payment_iframe_url'] ?? '');
        if ($url === '' || !str_starts_with($url, 'https://www.paytr.com/odeme/guvenli/')) redirect('/pricing');
        require BASE_PATH . '/resources/views/payments/paytr.php';
    }

    public function iyzicoPage(): void
    {
        requireAuth();
        $content = (string) ($_SESSION['iyzico_checkout_content'] ?? '');
        if ($content === '') redirect('/pricing');
        require BASE_PATH . '/resources/views/payments/iyzico.php';
    }

    public function paytrCallback(): void
    {
        $data = $_POST;
        if (!(new PaytrGateway())->verifyCallback($data)) { http_response_code(400); exit('PAYTR notification failed: bad hash'); }
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT * FROM payments WHERE merchant_order_id=:id FOR UPDATE');
            $stmt->execute(['id'=>(string)($data['merchant_oid']??'')]);
            $payment = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$payment) throw new \RuntimeException('Payment order not found.');
            if ($payment['status'] === 'paid') { $pdo->commit(); echo 'OK'; return; }
            if ((int)($data['total_amount']??-1) !== (int)round((float)$payment['amount']*100)) throw new \RuntimeException('Payment amount mismatch.');
            if (($data['status']??'') !== 'success') {
                $pdo->prepare("UPDATE payments SET status='failed',failure_reason=:reason WHERE id=:id")
                    ->execute(['reason'=>mb_substr((string)($data['failed_reason_msg']??'Payment failed'),0,500),'id'=>$payment['id']]);
            } else {
                $this->activatePaidOrder($pdo,$payment,(string)$data['merchant_oid'],false);
            }
            $pdo->commit(); echo 'OK';
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('[AdGuard PayTR callback] '.$e::class.': '.$e->getMessage());
            http_response_code(500); echo 'ERR';
        }
    }

    public function iyzicoCallback(): void
    {
        $token = trim((string)($_POST['token']??''));
        if ($token==='') { http_response_code(400); exit('Missing token'); }
        $pdo = Database::connection();
        try {
            $stmt = $pdo->prepare("SELECT * FROM payments WHERE provider_token=:token AND provider='iyzico' LIMIT 1");
            $stmt->execute(['token'=>$token]);
            $payment = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$payment) throw new \RuntimeException('iyzico payment order not found.');
            if ($payment['status'] === 'paid') { redirect('/payments/return'); }
            $gateway = new IyzicoGateway();
            if ((int)$payment['auto_renew_requested'] === 1) {
                $detail = $gateway->retrieveSubscription($token);
                $data = $detail['data'] ?? [];
                if (($detail['status']??'')!=='success' || ($data['subscriptionStatus']??'')!=='ACTIVE' || empty($data['referenceCode'])) {
                    throw new \RuntimeException('iyzico recurring subscription could not be verified.');
                }
                $pdo->beginTransaction();
                $lock=$pdo->prepare('SELECT * FROM payments WHERE id=:id FOR UPDATE'); $lock->execute(['id'=>$payment['id']]); $payment=$lock->fetch(PDO::FETCH_ASSOC);
                if ($payment['status']!=='paid') $this->activatePaidOrder($pdo,$payment,(string)$data['referenceCode'],true);
                $pdo->commit();
            } else {
                $detail = $gateway->retrieveCheckout($token);
                if (($detail['paymentStatus']??'')!=='SUCCESS' || ($detail['status']??'')!=='success' ||
                    (string)($detail['basketId']??'') !== (string)$payment['merchant_order_id'] ||
                    number_format((float)($detail['paidPrice']??0),2,'.','') !== number_format((float)$payment['amount'],2,'.','') ||
                    strtoupper((string)($detail['currency']??'')) !== strtoupper((string)$payment['currency'])) {
                    throw new \RuntimeException('iyzico payment verification mismatch.');
                }
                $pdo->beginTransaction();
                $lock=$pdo->prepare('SELECT * FROM payments WHERE id=:id FOR UPDATE'); $lock->execute(['id'=>$payment['id']]); $payment=$lock->fetch(PDO::FETCH_ASSOC);
                if ($payment['status']!=='paid') $this->activatePaidOrder($pdo,$payment,(string)($detail['paymentId']??''),false);
                $pdo->commit();
            }
            redirect('/payments/return');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('[AdGuard iyzico callback] '.$e::class.': '.$e->getMessage());
            http_response_code(400); echo 'Payment verification failed';
        }
    }

    public function iyzicoSubscriptionWebhook(): void
    {
        $payload = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($payload)) $payload = $_POST;
        $signature = (string)($_SERVER['HTTP_X_IYZ_SIGNATURE_V3'] ?? '');
        $gateway = new IyzicoGateway();
        if (!$gateway->verifySubscriptionWebhook($payload, $signature)) {
            http_response_code(401);
            exit('Invalid signature');
        }
        $eventType = (string)($payload['iyziEventType'] ?? '');
        $subscriptionRef = (string)($payload['subscriptionReferenceCode'] ?? '');
        $orderRef = (string)($payload['orderReferenceCode'] ?? '');
        $customerRef = (string)($payload['customerReferenceCode'] ?? '');
        if (!in_array($eventType, ['subscription.order.success','subscription.order.failure'], true) ||
            $subscriptionRef === '' || $orderRef === '' || $customerRef === '') {
            http_response_code(400);
            exit('Invalid event');
        }
        $pdo = Database::connection();
        $eventKey = (string)($payload['iyziReferenceCode'] ?? $orderRef);
        $hash = hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        try {
            $pdo->beginTransaction();
            $event = $pdo->prepare("INSERT INTO payment_webhook_events (provider,event_key,payload_hash,processing_status)
                VALUES ('iyzico',:event,:hash,'received')");
            try {
                $event->execute(['event'=>$eventKey,'hash'=>$hash]);
            } catch (\\PDOException $e) {
                if ((int)($e->errorInfo[1] ?? 0) === 1062) {
                    $pdo->rollBack();
                    http_response_code(200);
                    exit('OK');
                }
                throw $e;
            }
            $query = $pdo->prepare("SELECT s.id,s.user_id,s.plan_id,s.ends_at,p.billing_period,p.price,p.currency
                FROM subscriptions s INNER JOIN plans p ON p.id=s.plan_id
                WHERE s.provider_subscription_id=:reference AND s.payment_provider='iyzico'
                ORDER BY s.id DESC LIMIT 1 FOR UPDATE");
            $query->execute(['reference'=>$subscriptionRef]);
            $subscription = $query->fetch(PDO::FETCH_ASSOC);
            if (!$subscription) throw new \\RuntimeException('Local subscription not found for iyzico webhook.');
            $status = $eventType === 'subscription.order.success' ? 'paid' : 'failed';
            $payment = $pdo->prepare("INSERT INTO payments
                (user_id,plan_id,subscription_id,provider,merchant_order_id,provider_payment_id,amount,currency,status,paid_at,provider_response)
                VALUES (:user,:plan,:sub,'iyzico',:order,:order,:amount,:currency,:status,
                    CASE WHEN :status2='paid' THEN NOW() ELSE NULL END,:response)
                ON DUPLICATE KEY UPDATE status=VALUES(status),provider_response=VALUES(provider_response),paid_at=VALUES(paid_at)");
            $payment->execute([
                'user'=>$subscription['user_id'],'plan'=>$subscription['plan_id'],'sub'=>$subscription['id'],
                'order'=>$orderRef,'amount'=>$subscription['price'],'currency'=>$subscription['currency'],
                'status'=>$status,'status2'=>$status,'response'=>json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)
            ]);
            if ($status === 'paid') {
                $interval = $subscription['billing_period'] === 'yearly' ? 'YEAR' : 'MONTH';
                $pdo->exec("UPDATE subscriptions SET status='active',
                    ends_at=DATE_ADD(GREATEST(COALESCE(ends_at,NOW()),NOW()),INTERVAL 1 {$interval})
                    WHERE id=" . (int)$subscription['id']);
            } else {
                $pdo->prepare("UPDATE subscriptions SET status='suspended' WHERE id=:id AND ends_at<NOW()")
                    ->execute(['id'=>$subscription['id']]);
            }
            $pdo->prepare("UPDATE payment_webhook_events SET processing_status='processed',processed_at=NOW() WHERE provider='iyzico' AND event_key=:event")
                ->execute(['event'=>$eventKey]);
            $pdo->commit();
            http_response_code(200);
            echo 'OK';
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('[AdGuard iyzico subscription webhook] '.$e::class.': '.$e->getMessage());
            http_response_code(500);
            echo 'Webhook processing failed';
        }
    }

    public function cancelRenewal(): void
    {
        requireAuth();
        $pdo = Database::connection();
        $stmt = $pdo->prepare("SELECT id,payment_provider,provider_subscription_id FROM subscriptions
            WHERE user_id=:user AND status='active' AND auto_renew=1 AND ends_at>NOW()
            ORDER BY id DESC LIMIT 1");
        $stmt->execute(['user'=>(int)auth()['id']]);
        $subscription = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$subscription) {
            $_SESSION['error'] = 'Otomatik yenilenen aktif bir abonelik bulunamadı.';
            redirect('/dashboard');
        }
        try {
            if ($subscription['payment_provider'] !== 'iyzico' || empty($subscription['provider_subscription_id'])) {
                throw new \\RuntimeException('Bu sağlayıcı için otomatik yenileme iptal API akışı henüz etkin değil.');
            }
            $result = (new IyzicoGateway())->cancelSubscription((string)$subscription['provider_subscription_id']);
            if (($result['status'] ?? '') !== 'success') {
                throw new \\RuntimeException('iyzico abonelik iptalini onaylamadı.');
            }
            $pdo->prepare("UPDATE subscriptions SET auto_renew=0,cancelled_at=NOW(),cancellation_reason='customer_request'
                WHERE id=:id AND user_id=:user")
                ->execute(['id'=>$subscription['id'],'user'=>(int)auth()['id']]);
            $_SESSION['success'] = 'Otomatik yenileme iptal edildi. Mevcut dönem sonuna kadar hizmetiniz devam eder.';
        } catch (Throwable $e) {
            error_log('[AdGuard Renewal Cancellation] '.$e::class.': '.$e->getMessage());
            $_SESSION['error'] = 'Otomatik yenileme iptal edilemedi. Lütfen destek ile iletişime geçin.';
        }
        redirect('/dashboard');
    }

    public function returnPage(): void
    {
        requireAuth();
        require BASE_PATH . '/resources/views/payments/return.php';
    }

    private function activatePaidOrder(PDO $pdo,array $payment,string $providerReference,bool $autoRenew): void
    {
        $period=$pdo->prepare('SELECT billing_period FROM plans WHERE id=:id'); $period->execute(['id'=>$payment['plan_id']]);
        $billing=(string)$period->fetchColumn();
        $end=$billing==='yearly'?'DATE_ADD(NOW(), INTERVAL 1 YEAR)':'DATE_ADD(NOW(), INTERVAL 1 MONTH)';
        $pdo->prepare("UPDATE subscriptions SET status='expired',ends_at=NOW() WHERE user_id=:user AND status='active'")
            ->execute(['user'=>$payment['user_id']]);
        $sub=$pdo->prepare("INSERT INTO subscriptions
            (user_id,plan_id,status,starts_at,ends_at,payment_provider,provider_subscription_id,auto_renew,renewal_consent_at)
            VALUES (:user,:plan,'active',NOW(),{$end},:provider,:provider_id,:renew,:consent)");
        $sub->execute([
            'user'=>$payment['user_id'],'plan'=>$payment['plan_id'],'provider'=>$payment['provider'],
            'provider_id'=>$autoRenew?$providerReference:null,'renew'=>$autoRenew?1:0,
            'consent'=>$autoRenew?($payment['auto_renew_consent_at']??date('Y-m-d H:i:s')):null
        ]);
        $pdo->prepare("UPDATE payments SET status='paid',provider_payment_id=:provider_id,provider_response=:response,paid_at=NOW(),subscription_id=:subscription WHERE id=:id")
            ->execute([
                'provider_id'=>$providerReference?:null,'response'=>json_encode(['verified'=>true],JSON_THROW_ON_ERROR),
                'subscription'=>(int)$pdo->lastInsertId(),'id'=>$payment['id']
            ]);
    }
}
