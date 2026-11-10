<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Services\BotDetectionService;
use App\Services\RiskScoringService;
use App\Services\IyzicoGateway;
use App\Services\PaytrGateway;

require dirname(__DIR__) . '/app/Helpers/functions.php';

$detector = new BotDetectionService();
$scorer = new RiskScoringService();
$recent = [];

for ($i = 0; $i < 30; $i++) {
    $recent[] = [
        'page_url' => 'https://example.test/same-page',
        'created_at_epoch' => time()
    ];
}

$claimedCrawler = $detector->analyze([
    'user_agent' => 'Mozilla/5.0 (compatible; Googlebot/2.1)',
    'page_url' => 'https://example.test/same-page'
], $recent);
$crawlerRisk = $scorer->calculate($claimedCrawler);

if (($crawlerRisk['classification'] ?? '') !== 'crawler_claimed') {
    throw new RuntimeException('A crawler user-agent must be treated as an unverified claim.');
}
if ((int) ($crawlerRisk['score'] ?? 0) < 30) {
    throw new RuntimeException('Crawler claims must not erase independent frequency risk.');
}

$spoofedAutomation = $detector->analyze([
    'user_agent' => 'Googlebot HeadlessChrome python-requests',
    'page_url' => 'https://example.test/same-page'
], $recent);
$automationRisk = $scorer->calculate($spoofedAutomation);

if ((int) ($automationRisk['score'] ?? 0) < 80) {
    throw new RuntimeException('Automation and frequency signals should reach the configured review threshold.');
}
if (!$scorer->shouldCreateCandidate($automationRisk, 3)) {
    throw new RuntimeException('High-risk traffic with sufficient evidence should be eligible for manual review.');
}

putenv('IYZICO_SECRET_KEY=test-iyzico-secret');
putenv('IYZICO_MERCHANT_ID=123456');
$_ENV['IYZICO_SECRET_KEY'] = 'test-iyzico-secret';
$_ENV['IYZICO_MERCHANT_ID'] = '123456';
$subscriptionEvent = [
    'iyziEventType' => 'subscription.order.success',
    'subscriptionReferenceCode' => 'sub-001',
    'orderReferenceCode' => 'order-001',
    'customerReferenceCode' => 'customer-001'
];
$subscriptionMessage = '123456test-iyzico-secretsubscription.order.success'
    . 'sub-001order-001customer-001';
$subscriptionSignature = hash_hmac('sha256', $subscriptionMessage, 'test-iyzico-secret');
$iyzico = new IyzicoGateway();
if (!$iyzico->verifySubscriptionWebhook($subscriptionEvent, $subscriptionSignature)) {
    throw new RuntimeException('Valid iyzico subscription V3 signatures must be accepted.');
}
if ($iyzico->verifySubscriptionWebhook($subscriptionEvent, str_repeat('0', 64))) {
    throw new RuntimeException('Invalid iyzico subscription V3 signatures must be rejected.');
}

putenv('PAYTR_MERCHANT_KEY=test-paytr-key');
putenv('PAYTR_MERCHANT_SALT=test-paytr-salt');
$_ENV['PAYTR_MERCHANT_KEY'] = 'test-paytr-key';
$_ENV['PAYTR_MERCHANT_SALT'] = 'test-paytr-salt';
$paytrPayload = ['merchant_oid'=>'AGTEST001','status'=>'success','total_amount'=>'12500'];
$paytrSignature = base64_encode(hash_hmac(
    'sha256',
    'AGTEST001test-paytr-saltsuccess12500',
    'test-paytr-key',
    true
));
if (!(new PaytrGateway())->verifyCallback($paytrPayload + ['hash'=>$paytrSignature])) {
    throw new RuntimeException('Valid PayTR callback signatures must be accepted.');
}
if ((new PaytrGateway())->verifyCallback($paytrPayload + ['hash'=>'invalid'])) {
    throw new RuntimeException('Invalid PayTR callback signatures must be rejected.');
}

echo "AdGuard risk and payment signature regression checks passed.\n";
