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

    public function __construct(private readonly PDO $db) {}

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

        $contentType = strtolower(trim(explode(';', (string) ($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
        if (in_array($contentType, ['application/json', 'text/plain'], true)) {
            try {
                $data = json_decode($rawBody, true, 32, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $this->respond(400, ['success' => false, 'message' => 'Invalid JSON.']);
                return;
            }
        } elseif ($contentType === 'application/x-www-form-urlencoded') {
            parse_str($rawBody, $data);
        } else {
            $this->respond(415, ['success' => false, 'message' => 'Unsupported content type.']);
            return;
        }

        if (!is_array($data)) {
            $this->respond(400, ['success' => false, 'message' => 'Invalid payload.']);
            return;
        }

        $siteKey = trim((string) ($data['site_key'] ?? ''));
        $eventId = trim((string) ($data['event_id'] ?? ''));
        $pageUrl = trim((string) ($data['page_url'] ?? ''));
        $referrer = trim((string) ($data['referrer'] ?? ''));

        if (
            !preg_match('/^[a-f0-9]{64}$/i', $siteKey) ||
            !preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i', $eventId) ||
            !$this->isValidWebUrl($pageUrl)
        ) {
            $this->respond(400, ['success' => false, 'message' => 'Invalid tracking data.']);
            return;
        }

        if ($referrer !== '' && !$this->isValidWebUrl($referrer)) {
            $referrer = '';
        }

        // Do not retain query strings or fragments: they can contain emails, tokens or other personal data.
        $pageUrl = $this->sanitizeTrackedUrl($pageUrl);
        $referrer = $referrer !== '' ? $this->sanitizeTrackedUrl($referrer) : '';

        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $packedIp = filter_var($ip, FILTER_VALIDATE_IP) ? inet_pton($ip) : false;
        if ($packedIp === false) {
            $this->respond(400, ['success' => false, 'message' => 'Invalid client IP.']);
            return;
        }

        $origin = $this->getRequestOrigin();
        if ($origin === null) {
            $this->respond(403, ['success' => false, 'message' => 'Origin verification failed.']);
            return;
        }

        try {
            $website = $this->findWebsite($siteKey);
            if (!$website || $website['status'] !== 'active') {
                $this->respond(403, ['success' => false, 'message' => 'Website is not active.']);
                return;
            }

            if (!$this->originMatchesDomain($origin, (string) $website['domain'])) {
                $this->respond(403, ['success' => false, 'message' => 'Origin verification failed.']);
                return;
            }

            $this->setCorsHeaders($origin);
            $this->db->beginTransaction();

            // Duplicate events must not consume quota.
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
                    'reason' => $quota['reason'],
                    'message' => 'Daily visit quota exceeded.'
                ]);
                return;
            }

            $userAgent = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, self::MAX_USER_AGENT_LENGTH);
            $recentQuery = $this->db->prepare(
                'SELECT page_url, created_at, UNIX_TIMESTAMP(created_at) AS created_at_epoch
                 FROM visits
                 WHERE website_id = :website_id
                   AND ip_address = :ip_address
                   AND created_at >= DATE_SUB(NOW(), INTERVAL 60 SECOND)
                 ORDER BY created_at DESC
                 LIMIT 100'
            );
            $recentQuery->execute([
                'website_id' => (int) $website['id'],
                'ip_address' => $packedIp
            ]);
            $recent = $recentQuery->fetchAll(PDO::FETCH_ASSOC);

            $detection = (new BotDetectionService())->analyze([
                'user_agent' => $userAgent,
                'page_url' => $pageUrl
            ], $recent);

            $riskScorer = new RiskScoringService();
            $risk = $riskScorer->calculate($detection);

            $insert = $this->db->prepare(
                'INSERT INTO visits
                 (website_id, event_id, ip_address, user_agent, page_url, referrer,
                  risk_score, risk_level, detection_reasons, is_suspicious, created_at)
                 VALUES
                 (:website_id, :event_id, :ip_address, :user_agent, :page_url, :referrer,
                  :risk_score, :risk_level, :reasons, :is_suspicious, NOW())'
            );
            $insert->execute([
                'website_id' => (int) $website['id'],
                'event_id' => $eventId,
                'ip_address' => $packedIp,
                'user_agent' => $userAgent,
                'page_url' => $pageUrl,
                'referrer' => $referrer !== '' ? $referrer : null,
                'risk_score' => $risk['score'],
                'risk_level' => $risk['level'],
                'reasons' => json_encode($risk['reasons'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'is_suspicious' => $risk['is_suspicious'] ? 1 : 0
            ]);
            $visitId = (int) $this->db->lastInsertId();

            $evidenceQuery = $this->db->prepare(
                'SELECT COUNT(*)
                 FROM visits
                 WHERE website_id = :website_id
                   AND ip_address = :ip_address
                   AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)'
            );
            $evidenceQuery->execute([
                'website_id' => (int) $website['id'],
                'ip_address' => $packedIp
            ]);
            $evidenceCount = (int) $evidenceQuery->fetchColumn();

            $minimumEvents = max(1, min(10000, (int) env('RISK_CANDIDATE_MIN_EVENTS', 3)));
            if ($riskScorer->shouldCreateCandidate($risk, $evidenceCount, $minimumEvents)) {
                (new IpManagementService($this->db))->createCandidate(
                    (int) $website['user_id'],
                    (int) $website['id'],
                    $ip,
                    (int) $risk['score'],
                    $evidenceCount,
                    $risk['reasons'],
                    $visitId
                );
            }

            $this->db->commit();
            $this->respond(200, [
                'success' => true,
                'duplicate' => false,
                'risk_score' => $risk['score'],
                'risk_level' => $risk['level']
            ]);
        } catch (PDOException $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            if ($exception->getCode() === '23000') {
                $this->respond(200, ['success' => true, 'duplicate' => true]);
                return;
            }
            error_log('[AdGuard Tracking] Database error: ' . $exception->getCode());
            $this->respond(500, ['success' => false, 'message' => 'Tracking service temporarily unavailable.']);
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log('[AdGuard Tracking] ' . $exception::class . ': ' . $exception->getMessage());
            $this->respond(500, ['success' => false, 'message' => 'Tracking service temporarily unavailable.']);
        }
    }

    private function findWebsite(string $siteKey): ?array
    {
        $statement = $this->db->prepare(
            'SELECT id, user_id, domain, status FROM websites WHERE site_key = :site_key LIMIT 1'
        );
        $statement->execute(['site_key' => $siteKey]);
        return $statement->fetch(PDO::FETCH_ASSOC) ?: null;
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
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }

        return strtolower($parts['scheme']) . '://' . strtolower($parts['host'])
            . (isset($parts['port']) ? ':' . (int) $parts['port'] : '');
    }

    private function originMatchesDomain(string $origin, string $domain): bool
    {
        $parts = parse_url($origin);
        if (
            !is_array($parts) ||
            empty($parts['scheme']) ||
            empty($parts['host']) ||
            !in_array(strtolower($parts['scheme']), ['http', 'https'], true)
        ) {
            return false;
        }

        $host = strtolower(rtrim((string) $parts['host'], '.'));
        $domain = strtolower(trim($domain));

        if ($host === '' || $domain === '') {
            return false;
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return hash_equals($domain, $host);
        }

        return $host === $domain || str_ends_with($host, '.' . $domain);
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

    private function sanitizeTrackedUrl(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return '';
        }

        $scheme = strtolower((string) $parts['scheme']);
        $host = strtolower((string) $parts['host']);
        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
        $path = (string) ($parts['path'] ?? '/');

        return $scheme . '://' . $host . $port . $path;
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
