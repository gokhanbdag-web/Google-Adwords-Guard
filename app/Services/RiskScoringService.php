<?php
declare(strict_types=1);

namespace App\Services;

final class RiskScoringService
{
    private const LEVELS = [
        'low' => [0, 29],
        'medium' => [30, 59],
        'high' => [60, 79],
        'critical' => [80, 100]
    ];

    public function calculate(array $detection, array $additionalSignals = []): array
    {
        $signals = is_array($detection['signals'] ?? null) ? $detection['signals'] : [];
        $score = 0;
        $reasons = [];

        foreach (array_merge($signals, $additionalSignals) as $signal) {
            if (!is_array($signal)) {
                continue;
            }
            $weight = max(0, min(100, (int) ($signal['weight'] ?? 0)));
            $score += $weight;
            $reasons[] = [
                'code' => (string) ($signal['code'] ?? 'unknown'),
                'description' => (string) ($signal['description'] ?? 'Belirsiz risk sinyali.'),
                'weight' => $weight,
                'details' => $signal['details'] ?? null
            ];
        }

        $score = min(100, $score);
        $classification = (string) ($detection['classification'] ?? 'unknown');
        if ($classification === 'crawler_claimed') {
            $reasons[] = [
                'code' => 'unverified_crawler_claim',
                'description' => 'User-Agent bir bot olduğunu iddia ediyor; kimlik doğrulanmadı.',
                'weight' => 0,
                'details' => null
            ];
        }

        return [
            'score' => $score,
            'level' => $this->getLevel($score),
            'is_suspicious' => $score >= 60,
            'is_block_candidate' => $score >= max(1, min(100, (int) env('RISK_CANDIDATE_THRESHOLD', 80))),
            'reasons' => $reasons,
            'classification' => $classification,
            'requires_manual_review' => $score >= 60
        ];
    }

    public function getLevel(int $score): string
    {
        $score = max(0, min(100, $score));
        foreach (self::LEVELS as $level => [$minimum, $maximum]) {
            if ($score >= $minimum && $score <= $maximum) {
                return $level;
            }
        }
        return 'low';
    }

    public function shouldCreateCandidate(array $risk, int $evidenceCount, int $minimumEvidence = 3): bool
    {
        $threshold = max(1, min(100, (int) env('RISK_CANDIDATE_THRESHOLD', 80)));
        return (int) ($risk['score'] ?? 0) >= $threshold
            && $evidenceCount >= max(1, $minimumEvidence)
            && !in_array(($risk['classification'] ?? ''), ['known_crawler', 'verified_crawler'], true);
    }
}
