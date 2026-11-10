<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Services\BotDetectionService;
use App\Services\RiskScoringService;

$botDetector = new BotDetectionService();
$riskScorer = new RiskScoringService();

$recent = [];
for ($i = 0; $i < 30; $i++) {
    $recent[] = [
        'page_url' => 'https://example.test/page',
        'created_at' => date('Y-m-d H:i:s')
    ];
}

$detection = $botDetector->analyze([
    'user_agent' => 'Mozilla/5.0 (compatible; Googlebot/2.1)',
    'page_url' => 'https://example.test/page'
], $recent);

$risk = $riskScorer->calculate($detection);

if (($detection['classification'] ?? '') !== 'crawler_claimed') {
    throw new RuntimeException('Crawler user-agent must be treated as an unverified claim.');
}
if ((int) ($risk['score'] ?? -1) < 30) {
    throw new RuntimeException('High request frequency must still contribute risk for crawler-claiming user agents.');
}

$automation = $botDetector->analyze([
    'user_agent' => 'HeadlessChrome Selenium',
    'page_url' => 'https://example.test/page'
], $recent);
$automationRisk = $riskScorer->calculate($automation);

if ((int) ($automationRisk['score'] ?? 0) < 80) {
    throw new RuntimeException('Repeated automated traffic should reach the critical risk threshold.');
}
if (empty($automationRisk['is_block_candidate'])) {
    throw new RuntimeException('Critical automated traffic should be eligible for manual-review candidacy.');
}

echo "AdGuard unit checks passed.\n";
