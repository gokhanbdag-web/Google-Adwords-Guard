<?php
declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/vendor/autoload.php';

use App\Core\Env;
use App\Core\Database;
use App\Services\GoogleAdsApiService;
use PDO;
use Throwable;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

Env::load(BASE_PATH . '/.env');
date_default_timezone_set('Europe/Istanbul');
$pdo = Database::connection();

$pdo->beginTransaction();
try {
    $select = $pdo->query(
        "SELECT id, user_id, website_id, job_type, payload, attempts
         FROM queue_jobs
         WHERE status = 'pending' AND available_at <= NOW() AND attempts < 5
         ORDER BY id ASC
         LIMIT 1
         FOR UPDATE SKIP LOCKED"
    );
    $job = $select->fetch(PDO::FETCH_ASSOC);
    if (!$job) {
        $pdo->commit();
        exit(0);
    }

    $mark = $pdo->prepare(
        "UPDATE queue_jobs SET status = 'processing', attempts = attempts + 1
         WHERE id = :id AND status = 'pending'"
    );
    $mark->execute(['id' => (int) $job['id']]);
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[AdGuard Queue] Unable to claim job: ' . $e::class);
    exit(1);
}

$attempt = (int) $job['attempts'] + 1;

try {
    $payload = json_decode((string) $job['payload'], true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($payload)) {
        throw new RuntimeException('Queue payload must be an object.');
    }

    if ($job['job_type'] === 'google_ip_exclusion') {
        $connectionId = (int) ($payload['connection_id'] ?? 0);
        $campaignId = (int) ($payload['campaign_id'] ?? 0);
        $ruleId = (int) ($payload['ip_rule_id'] ?? 0);

        $ruleQuery = $pdo->prepare(
            "SELECT r.id, INET6_NTOA(r.ip_address) AS ip_text
             FROM ip_rules r
             INNER JOIN websites w ON w.id = r.website_id
             WHERE r.id = :rule_id AND r.website_id = :website_id
               AND w.user_id = :user_id
               AND r.rule_type = 'deny' AND r.status = 'active'
             LIMIT 1"
        );
        $ruleQuery->execute([
            'rule_id' => $ruleId,
            'website_id' => (int) $job['website_id'],
            'user_id' => (int) $job['user_id']
        ]);
        $rule = $ruleQuery->fetch(PDO::FETCH_ASSOC);
        if (!$rule) {
            throw new RuntimeException('Approved IP rule not found.');
        }

        $connectionQuery = $pdo->prepare(
            "SELECT customer_id FROM google_connections
             WHERE id = :id AND user_id = :user_id AND status = 'active' LIMIT 1"
        );
        $connectionQuery->execute([
            'id' => $connectionId,
            'user_id' => (int) $job['user_id']
        ]);
        $connection = $connectionQuery->fetch(PDO::FETCH_ASSOC);
        if (!$connection) {
            throw new RuntimeException('Active Google Ads connection not found.');
        }

        $audit = $pdo->prepare(
            "INSERT INTO google_ads_actions
             (user_id, google_connection_id, ip_rule_id, customer_id, campaign_id,
              ip_address, action_type, status)
             VALUES (:user_id, :connection_id, :rule_id, :customer_id, :campaign_id,
                     :ip_address, 'ip_exclusion', 'processing')"
        );
        $audit->execute([
            'user_id' => (int) $job['user_id'],
            'connection_id' => $connectionId,
            'rule_id' => $ruleId,
            'customer_id' => $connection['customer_id'],
            'campaign_id' => $campaignId,
            'ip_address' => $rule['ip_text']
        ]);
        $auditId = (int) $pdo->lastInsertId();

        try {
            $result = (new GoogleAdsApiService($pdo))->excludeIpForCampaign(
                (int) $job['user_id'],
                $connectionId,
                $campaignId,
                (string) $rule['ip_text']
            );
            $status = $result['already_exists'] ? 'already_exists' : 'succeeded';
            $updateAudit = $pdo->prepare('UPDATE google_ads_actions SET status = :status WHERE id = :id');
            $updateAudit->execute(['status' => $status, 'id' => $auditId]);
            $updateRule = $pdo->prepare(
                "UPDATE ip_rules SET source = 'google_ads'
                 WHERE id = :id AND website_id = :website_id"
            );
            $updateRule->execute(['id' => $ruleId, 'website_id' => (int) $job['website_id']]);
        } catch (Throwable $e) {
            $updateAudit = $pdo->prepare(
                "UPDATE google_ads_actions SET status = 'failed', error_message = :error WHERE id = :id"
            );
            $updateAudit->execute([
                'error' => mb_substr($e->getMessage(), 0, 1000),
                'id' => $auditId
            ]);
            throw $e;
        }
    } else {
        throw new RuntimeException('Unsupported queue job type: ' . (string) $job['job_type']);
    }

    $done = $pdo->prepare(
        "UPDATE queue_jobs SET status = 'completed', processed_at = NOW(), last_error = NULL
         WHERE id = :id"
    );
    $done->execute(['id' => (int) $job['id']]);
    echo 'Job ' . (int) $job['id'] . " completed\n";
} catch (Throwable $e) {
    error_log('[AdGuard Queue] Job ' . (int) $job['id'] . ' failed: ' . $e::class . ': ' . $e->getMessage());
    $status = $attempt >= 5 ? 'failed' : 'pending';
    $delay = min(3600, 30 * (2 ** min(6, $attempt - 1)));
    $fail = $pdo->prepare(
        "UPDATE queue_jobs
         SET status = :status,
             available_at = DATE_ADD(NOW(), INTERVAL :delay SECOND),
             last_error = :error
         WHERE id = :id"
    );
    $fail->execute([
        'status' => $status,
        'delay' => $delay,
        'error' => mb_substr($e->getMessage(), 0, 2000),
        'id' => (int) $job['id']
    ]);
    exit(1);
}
