<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Services\BotDetectionService;
use App\Services\RiskScoringService;

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

echo "AdGuard risk regression checks passed.\n";
