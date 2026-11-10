
<?php

declare(strict_types=1);

namespace App\Controllers;

use PDO;
use Throwable;

final class TrackingController
{
    private const MAX_BODY_SIZE = 16384;
    private const MAX_USER_AGENT_LENGTH = 1000;
    private const MAX_URL_LENGTH = 2048;

    public function __construct(
        private readonly PDO $db
    ) {
    }

    public function handle(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            $this->respond(405, [
                'success' => false,
                'message' => 'Method not allowed.'
            ]);
            return;
        }

        $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);

        if ($contentLength > self::MAX_BODY_SIZE) {
            $this->respond(413, [
                'success' => false,
                'message' => 'Request too large.'
            ]);
            return;
        }

        $rawBody = file_get_contents(
            'php://input',
            false,
            null,
            0,
            self::MAX_BODY_SIZE + 1
        );

        if ($rawBody === false || strlen($rawBody) > self::MAX_BODY_SIZE) {
            $this->respond(400, [
                'success' => false,
                'message' => 'Invalid request.'
            ]);
            return;
        }

        $data = json_decode($rawBody, true);

        if (!is_array($data)) {
            $data = [];
        }

        if (empty($data)) {
            parse_str($rawBody, $parsed);

            if (is_array($parsed)) {
                $data = $parsed;
            }
        }

        $siteKey = trim((string) ($data['site_key'] ?? ''));
        $eventId = trim((string) ($data['event_id'] ?? ''));
        $pageUrl = trim((string) ($data['page_url'] ?? ''));
        $referrer = trim((string) ($data['referrer'] ?? ''));

        if (!preg_match('/^[a-f0-9]{64}$/i', $siteKey)) {
            $this->respond(400, [
                'success' => false,
                'message' => 'Invalid site key.'
            ]);
            return;
        }

        if (!$this->isValidUuid($eventId)) {
            $this->respond(400, [
                'success' => false,
                'message' => 'Invalid event identifier.'
            ]);
            return;
        }

        if (!$this->isValidWebUrl($pageUrl)) {
            $this->respond(400, [
                'success' => false,
                'message' => 'Invalid page URL.'
            ]);
            return;
        }

        if ($referrer !== '' && !$this->isValidWebUrl($referrer)) {
            $referrer = '';
        }

        $clientIp = $_SERVER['REMOTE_ADDR'] ?? '';

        if (!filter_var($clientIp, FILTER_VALIDATE_IP)) {
            $this->respond(400, [
                'success' => false,
                'message' => 'Unable to determine client IP.'
            ]);
            return;
        }

        $packedIp = inet_pton($clientIp);

        if ($packedIp === false) {
            $this->respond(400, [
                'success' => false,
                'message' => 'Invalid client IP.'
            ]);
            return;
        }

        $userAgent = substr(
            (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
            0,
            self::MAX_USER_AGENT_LENGTH
        );

        try {
            $website = $this->findWebsite($siteKey);

            if (!$website || $website['status'] !== 'active') {
                $this->respond(403, [
                    'success' => false,
                    'message' => 'Website is not active.'
                ]);
                return;
            }

            $origin = $this->getRequestOrigin();

            if ($origin === null || !$this->originMatchesDomain(
                $origin,
                (string) $website['domain']
            )) {
                $this->respond(403, [
                    'success' => false,
                    'message' => 'Origin verification failed.'
                ]);
                return;
            }

            $this->setCorsHeaders($origin);

            $this->db->beginTransaction();

            $insert = $this->db->prepare(
                "INSERT INTO visits (
                    website_id,
                    event_id,
                    ip_address,
                    user_agent,
                    page_url,
                    referrer,
                    country_code,
                    risk_score,
                    risk_level,
                    detection_reasons,
                    is_suspicious,
                    created_at
                ) VALUES (
                    :website_id,
                    :event_id,
                    :ip_address,
                    :user_agent,
                    :page_url,
                    :referrer,
                    NULL,
                    0,
                    'low',
                    :detection_reasons,
                    0,
                    NOW()
                )"
            );

            try {
                $insert->execute([
                    'website_id' => (int) $website['id'],
                    'event_id' => $eventId,
                    'ip_address' => $packedIp,
                    'user_agent' => $userAgent,
                    'page_url' => $pageUrl,
                    'referrer' => $referrer !== '' ? $referrer : null,
                    'detection_reasons' => json_encode(
                        [],
                        JSON_THROW_ON_ERROR
                    )
                ]);
            } catch (Throwable $exception) {
                if (
                    $exception instanceof \PDOException &&
                    $exception->getCode() === '23000'
                ) {
                    $this->db->rollBack();

                    $this->respond(200, [
                        'success' => true,
                        'duplicate' => true
                    ]);

                    return;
                }

                throw $exception;
            }

            $usage = $this->db->prepare(
                "INSERT INTO daily_usage (
                    user_id,
                    usage_date,
                    visits_count
                ) VALUES (
                    :user_id,
                    CURDATE(),
                    1
                )
                ON DUPLICATE KEY UPDATE
                    visits_count = visits_count + 1"
            );

            $usage->execute([
                'user_id' => (int) $website['user_id']
            ]);

            $this->db->commit();

            $this->respond(200, [
                'success' => true,
                'duplicate' => false
            ]);
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            error_log(
                '[AdGuard Tracking] ' .
                $exception::class . ': ' .
                $exception->getMessage()
            );

            $this->respond(500, [
                'success' => false,
                'message' => 'Tracking service temporarily unavailable.'
            ]);
        }
    }

    private function findWebsite(string $siteKey): ?array
    {
        $statement = $this->db->prepare(
            "SELECT id, user_id, domain, site_key, status
             FROM websites
             WHERE site_key = :site_key
             LIMIT 1"
        );

        $statement->execute([
            'site_key' => $siteKey
        ]);

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

        if ($referer !== '') {
            $parts = parse_url($referer);

            if (
                is_array($parts) &&
                isset($parts['scheme'], $parts['host'])
            ) {
                return strtolower($parts['scheme']) .
                    '://' .
                    strtolower($parts['host']) .
                    (isset($parts['port']) ? ':' . $parts['port'] : '');
            }
        }

        return null;
    }

    private function originMatchesDomain(
        string $origin,
        string $registeredDomain
    ): bool {
        $originParts = parse_url($origin);

        if (
            !is_array($originParts) ||
            !isset($originParts['scheme'], $originParts['host'])
        ) {
            return false;
        }

        if (!in_array(
            strtolower($originParts['scheme']),
            ['http', 'https'],
            true
        )) {
            return false;
        }

        $host = strtolower(rtrim($originParts['host'], '.'));
        $domain = strtolower(trim($registeredDomain));

        if (
            $host === '' ||
            $domain === '' ||
            str_contains($host, '/') ||
            str_contains($domain, '/')
        ) {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return hash_equals($domain, $host);
        }

        return $host === $domain ||
            str_ends_with($host, '.' . $domain);
    }

    private function isValidUuid(string $value): bool
    {
        return (bool) preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $value
        );
    }

    private function isValidWebUrl(string $url): bool
    {
        if (
            $url === '' ||
            strlen($url) > self::MAX_URL_LENGTH ||
            filter_var($url, FILTER_VALIDATE_URL) === false
        ) {
            return false;
        }

        $parts = parse_url($url);

        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        return in_array(
            strtolower($parts['scheme']),
            ['http', 'https'],
            true
        );
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

        echo json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES |
            JSON_INVALID_UTF8_SUBSTITUTE
        );
    }
}
