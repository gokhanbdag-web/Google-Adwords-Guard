
<?php

declare(strict_types=1);

namespace App\Services;

final class BotDetectionService
{
    private const KNOWN_BOT_PATTERNS = [
        'googlebot',
        'bingbot',
        'yandexbot',
        'duckduckbot',
        'baiduspider',
        'facebookexternalhit',
        'twitterbot',
        'linkedinbot',
        'slackbot',
        'telegrambot',
        'whatsapp',
        'applebot',
        'ahrefsbot',
        'semrushbot',
        'mj12bot',
        'dotbot'
    ];

    private const AUTOMATION_PATTERNS = [
        'headlesschrome',
        'phantomjs',
        'selenium',
        'playwright',
        'puppeteer',
        'webdriver',
        'cypress',
        'nightmare'
    ];

    private const SUSPICIOUS_PATTERNS = [
        'curl/',
        'wget/',
        'python-requests',
        'python-urllib',
        'httpclient',
        'go-http-client',
        'scrapy',
        'aiohttp',
        'libwww-perl'
    ];

    public function analyze(
        array $visit,
        array $recentVisits = []
    ): array {
        $userAgent = strtolower(
            trim((string) ($visit['user_agent'] ?? ''))
        );

        $signals = [];
        $score = 0;

        if ($userAgent === '') {
            $signals[] = [
                'code' => 'missing_user_agent',
                'description' => 'User-Agent bilgisi bulunamadı.',
                'weight' => 15
            ];

            $score += 15;
        }

        foreach (self::KNOWN_BOT_PATTERNS as $pattern) {
            if ($userAgent !== '' && str_contains($userAgent, $pattern)) {
                $signals[] = [
                    'code' => 'known_crawler',
                    'description' => 'Bilinen arama motoru veya sosyal medya botu.',
                    'weight' => 0,
                    'classification' => 'crawler'
                ];

                break;
            }
        }

        foreach (self::AUTOMATION_PATTERNS as $pattern) {
            if ($userAgent !== '' && str_contains($userAgent, $pattern)) {
                $signals[] = [
                    'code' => 'automation_browser',
                    'description' => 'Otomasyon veya headless tarayıcı göstergesi.',
                    'weight' => 35
                ];

                $score += 35;
            }
        }

        foreach (self::SUSPICIOUS_PATTERNS as $pattern) {
            if ($userAgent !== '' && str_contains($userAgent, $pattern)) {
                $signals[] = [
                    'code' => 'non_browser_client',
                    'description' => 'Standart tarayıcı olmayan HTTP istemcisi.',
                    'weight' => 20
                ];

                $score += 20;
                break;
            }
        }

        $pageUrl = (string) ($visit['page_url'] ?? '');

        if ($pageUrl !== '' && strlen($pageUrl) > 1800) {
            $signals[] = [
                'code' => 'unusually_long_url',
                'description' => 'Olağandışı uzun sayfa adresi.',
                'weight' => 5
            ];

            $score += 5;
        }

        $frequency = $this->analyzeFrequency($recentVisits);

        if ($frequency['count'] >= 30) {
            $signals[] = [
                'code' => 'high_visit_frequency',
                'description' => 'Kısa sürede yüksek ziyaret sıklığı.',
                'weight' => 30,
                'details' => $frequency
            ];

            $score += 30;
        } elseif ($frequency['count'] >= 15) {
            $signals[] = [
                'code' => 'elevated_visit_frequency',
                'description' => 'Normalin üzerinde ziyaret sıklığı.',
                'weight' => 15,
                'details' => $frequency
            ];

            $score += 15;
        }

        $uniquePages = [];

        foreach ($recentVisits as $recentVisit) {
            $url = (string) ($recentVisit['page_url'] ?? '');

            if ($url !== '') {
                $uniquePages[$url] = true;
            }
        }

        if (
            count($recentVisits) >= 10 &&
            count($uniquePages) === 1
        ) {
            $signals[] = [
                'code' => 'repeated_same_page',
                'description' => 'Kısa sürede aynı sayfaya tekrar tekrar erişim.',
                'weight' => 10
            ];

            $score += 10;
        }

        return [
            'score' => min(100, $score),
            'signals' => $signals,
            'classification' => $this->classify($signals),
            'frequency' => $frequency
        ];
    }

    private function analyzeFrequency(array $recentVisits): array
    {
        $now = time();
        $count = 0;

        foreach ($recentVisits as $visit) {
            $createdAt = $visit['created_at'] ?? null;

            if (!$createdAt) {
                continue;
            }

            $timestamp = strtotime((string) $createdAt);

            if (
                $timestamp !== false &&
                $timestamp >= $now - 60 &&
                $timestamp <= $now + 5
            ) {
                $count++;
            }
        }

        return [
            'count' => $count,
            'window_seconds' => 60
        ];
    }

    private function classify(array $signals): string
    {
        foreach ($signals as $signal) {
            if (($signal['classification'] ?? '') === 'crawler') {
                return 'known_crawler';
            }
        }

        foreach ($signals as $signal) {
            if (($signal['code'] ?? '') === 'automation_browser') {
                return 'automation_suspected';
            }
        }

        return 'unknown';
    }
}
