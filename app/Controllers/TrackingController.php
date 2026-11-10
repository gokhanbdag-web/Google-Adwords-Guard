<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\BotDetectionService;
use App\Services\IpManagementService;
use App\Services\QuotaService;
use App\Services\RiskScoringService;
use PDO;
use PDOException;
use Throwable;

final class TrackingController
{
    private const MAX_BODY_SIZE = 16384;
    private const MAX_USER_AGENT_LENGTH = 1000;
    private const MAX_URL_LENGTH = 2048;

    public function __construct(private readonly PDO $db)
    {
    }

    public function handle(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            $this->respond(405, ['success' => false, 'message' => 'Method not allowed.']);
            return;
        }

        $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($contentLength > self::MAX_BODY_SIZE) {
            $this->respond(413, ['success' => false, 'message' => 'Request too large.']);
            return;
        }

        $rawBody = file_get_contents('php://input', false, null, 0, self::MAX_BODY_SIZE + 1);
        if ($rawBody === false || strlen($rawBody) > self::MAX_BODY_SIZE) {
            $this->respond(400, ['success' => false, 'message' => 'Invalid request.']);
            return;
        }

        $data = json_decode($rawBody, true);
        if (!is_array($data)) {
            $data = [];
            parse_str($rawBody, $data);
        }

        $siteKey = trim((string) ($data['site_key'] ?? ''));
        $eventId = trim((string) ($data['event_id'] ?? ''));
        $pageUrl = trim((string) ($data['page_url'] ?? ''));
        $referrer = trim((string) ($data['referrer'] ?? ''));

        if (!preg_match('/^[a-f0-9]{64}$/i', $siteKey)) {
            $this->respond(400, ['success' => false, 'message' => 'Invalid site key.']);
            return;
        }
        if (!$this->isValidUuid($eventId)) {
            $this->respond(400, ['success' => false, 'message' => 'Invalid event identifier.']);
            return;
        }
        if (!$this->isValidWebUrl($pageUrl)) {
            $this->respond(400, ['success' => false, 'message' => 'Invalid page URL.']);
            return;
        }
        if ($referrer !== '' && !$this->isValidWebUrl($referrer)) {
            $referrer = '';
        }
        // Query strings and fragments can contain emails, tokens, and other personal data.
        $pageUrl = $this->sanitizeTrackedUrl($pageUrl);
        $referrer = $referrer !== '' ? $this->sanitizeTrackedUrl($referrer) : '';

        $clientIp = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        if (!filter_var($clientIp, FILTER_VALIDATE_IP)) {
            $this->respond(400, ['success' => false, 'message' => 'Unable to determine client IP.']);
            return;
        }
        $packedIp = inet_pton($clientIp);
        if ($packedIp === false) {
            $this->respond(400, ['success' => false, 'message' => 'Invalid client IP.']);
            return;
        }

        $userAgent = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, self::MAX_USER_AGENT_LENGTH);

        try {
            $website = $this->findWebsite($siteKey);
            if (!$website || $website['status'] !== 'active') {
                $this->respond(403, ['success' => false, 'message' => 'Website is not active.']);
                return;
            }

            $origin = $this->getRequestOrigin();
            if ($origin === null || !$this->originMatchesDomain($origin, (string) $website['domain'])) {
                $this->respond(403, ['success' => false, 'message' => 'Origin verification failed.']);
                return;
            }
            $this->setCorsHeaders($origin);

            $this->db->beginTransaction();

            // Duplicate events do not consume the account's daily quota.
            $duplicate = $this->db->prepare(
                'SELECT id FROM visits WHERE website_id = :website_id AND event_id = :event_id LIMIT 1'
            );
            $duplicate->execute([
                'website_id' => (int) $website['id'],
                'event_id' => $eventId
            ]);
            if ($duplicate->fetchColumn()) {
                $this->db->rollBack();
                $this->respond(200, ['success' => true, 'duplicate' => true]);
                return;
            }

            $quota = (new QuotaService($this->db))->consumeVisit((int) $website['user_id']);
            if (!$quota['allowed']) {
                $this->db->rollBack();
                $this->respond(429, [
                    'success' => false,
                    'message' => 'Daily visit quota exceeded.',
                    'quota' => $quota
                ]);
                return;
            }

            $recentStatement = $this->db->prepare(
                'SELECT page_url, created_at
                 FROM visits
                 WHERE website_id = :website_id
                   AND ip_address = :ip_address
                   AND created_at >= NOW() - INTERVAL 60 SECOND
                 ORDER BY created_at DESC
                 LIMIT 100'
            );
            $recentStatement->bindValue(':website_id', (int) $website['id'], PDO::PARAM_INT);
            $recentStatement->bindValue(':ip_address', $packedIp, PDO::PARAM_LOB);
            $recentStatement->execute();
            $recentVisits = $recentStatement->fetchAll(PDO::FETCH_ASSOC);

            $detection = (new BotDetectionService())->analyze([
                'user_agent' => $userAgent,
                'page_url' => $pageUrl
            ], $recentVisits);
            $risk = (new RiskScoringService())->calculate($detection);

            $insert = $this->db->prepare(
                "INSERT INTO visits (
                    website_id, event_id, ip_address, user_agent, page_url,
                    referrer, country_code, risk_score, risk_level,
                    detection_reasons, is_suspicious, created_at
                ) VALUES (
                    :website_id, :event_id, :ip_address, :user_agent, :page_url,
                    :referrer, NULL, :risk_score, :risk_level,
                    :detection_reasons, :is_suspicious, NOW()
                )"
            );
            $insert->bindValue(':website_id', (int) $website['id'], PDO::PARAM_INT);
            $insert->bindValue(':event_id', $eventId);
            $insert->bindValue(':ip_address', $packedIp, PDO::PARAM_LOB);
            $insert->bindValue(':user_agent', $userAgent);
            $insert->bindValue(':page_url', $pageUrl);
            $insert->bindValue(':referrer', $referrer !== '' ? $referrer : null);
            $insert->bindValue(':risk_score', (int) $risk['score'], PDO::PARAM_INT);
            $insert->bindValue(':risk_level', $risk['level']);
            $insert->bindValue(
                ':detection_reasons',
                json_encode($risk['reasons'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
            $insert->bindValue(':is_suspicious', (int) $risk['is_suspicious'], PDO::PARAM_INT);
            $insert->execute();

            // High-risk IPs become review candidates only after repeated evidence.
            // This does not block traffic or modify Google Ads.
            if ($risk['is_block_candidate'] && $risk['classification'] !== 'known_crawler') {
                $evidence = $this->db->prepare(
                    'SELECT COUNT(*) FROM visits
                     WHERE website_id = :website_id
                       AND ip_address = :ip_address
                       AND created_at >= NOW() - INTERVAL 24 HOUR'
                );
                $evidence->bindValue(':website_id', (int) $website['id'], PDO::PARAM_INT);
                $evidence->bindValue(':ip_address', $packedIp, PDO::PARAM_LOB);
                $evidence->execute();
                $evidenceCount = (int) $evidence->fetchColumn();

                if ($evidenceCount >= 3) {
                    (new IpManagementService($this->db))->createCandidate(
                        (int) $website['user_id'],
                        (int) $website['id'],
                        $clientIp,
                        (int) $risk['score'],
                        $evidenceCount,
                        $risk['reasons']
                    );
                }
            }

            $this->db->commit();
            $this->respond(200, [
                'success' => true,
                'duplicate' => false,
                'risk_score' => (int) $risk['score'],
                'risk_level' => $risk['level']
            ]);
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            if ($exception instanceof PDOException && $exception->getCode() === '23000') {
                $this->respond(200, ['success' => true, 'duplicate' => true]);
                return;
            }
            error_log('[AdGuard Tracking] ' . $exception::class . ': ' . $exception->getMessage());
            $this->respond(500, ['success' => false, 'message' => 'Tracking service temporarily unavailable.']);
        }
    }

    private function findWebsite(string $siteKey): ?array
    {
        $statement = $this->db->prepare(
            'SELECT id, user_id, domain, site_key, status FROM websites WHERE site_key = :site_key LIMIT 1'
        );
        $statement->execute(['site_key' => $siteKey]);
        $website = $statement->fetch(PDO::FETCH_ASSOC);
        return $website ?: null;
    }

    private function getRequestOrigin(): ?string
    {
        $origin = trim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));
        if ($origin !== '') {
            return $origin;
        }
        $referer = trim((string) ($_SERVER['HTTP_REFERER'] ?? ''));
        if ($referer === '') {
            return null;
        }
        $parts = parse_url($referer);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }
        return strtolower($parts['scheme']) . '://' . strtolower($parts['host'])
            . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    private function originMatchesDomain(string $origin, string $registeredDomain): bool
    {
        $parts = parse_url($origin);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return false;
        }
        if (!in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return false;
        }
        $host = strtolower(rtrim($parts['host'], '.'));
        $domain = strtolower(trim($registeredDomain));
        if ($host === '' || $domain === '' || str_contains($host, '/') || str_contains($domain, '/')) {
            return false;
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return hash_equals($domain, $host);
        }
        return $host === $domain || str_ends_with($host, '.' . $domain);
    }

    private function isValidUuid(string $value): bool
    {
        return (bool) preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $value
        );
    }


    private function sanitizeTrackedUrl(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return '';
        }

        $scheme = strtolower((string) $parts['scheme']);
        $host = strtolower((string) $parts['host']);
        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
        $path = (string) ($parts['path'] ?? '/');

        return $scheme . '://' . $host . $port . $path;
    }

    private function isValidWebUrl(string $url): bool
    {
        if ($url === '' || strlen($url) > self::MAX_URL_LENGTH || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $parts = parse_url($url);
        return is_array($parts)
            && isset($parts['scheme'], $parts['host'])
            && !isset($parts['user'])
            && !isset($parts['pass'])
            && in_array(strtolower($parts['scheme']), ['http', 'https'], true);
    }

    private function setCorsHeaders(string $origin): void
    {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
        header('Access-Control-Allow-Methods: POST');
        header('Access-Control-Allow-Headers: Content-Type');
        header('Access-Control-Max-Age: 600');
    }

    private function respond(int $status, array $payload): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('X-Content-Type-Options: nosniff');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
