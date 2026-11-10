<?php
declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;
use Throwable;

final class QuotaService
{
    public function __construct(private readonly PDO $db) {}

    public function consumeVisit(int $userId): array
    {
        if ($userId <= 0) {
            throw new RuntimeException('Invalid user identifier.');
        }

        $ownsTransaction = !$this->db->inTransaction();
        if ($ownsTransaction) {
            $this->db->beginTransaction();
        }

        try {
            $plan = $this->getActivePlan($userId);
            if ($plan === null) {
                throw new RuntimeException('No active subscription was found.');
            }

            $limit = (int) $plan['daily_visit_limit'];

            $insert = $this->db->prepare(
                "INSERT INTO daily_usage (user_id, usage_date, visits_count)
                 VALUES (:user_id, CURDATE(), 0)
                 ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)"
            );
            $insert->execute(['user_id' => $userId]);

            $lock = $this->db->prepare(
                "SELECT visits_count FROM daily_usage
                 WHERE user_id = :user_id AND usage_date = CURDATE()
                 FOR UPDATE"
            );
            $lock->execute(['user_id' => $userId]);
            $current = $lock->fetchColumn();

            if ($current === false) {
                throw new RuntimeException('Unable to lock daily usage.');
            }

            $used = (int) $current;
            if ($limit > 0 && $used >= $limit) {
                if ($ownsTransaction) {
                    $this->db->commit();
                }
                return [
                    'allowed' => false,
                    'reason' => 'daily_quota_exceeded',
                    'used' => $used,
                    'limit' => $limit,
                    'remaining' => 0
                ];
            }

            $update = $this->db->prepare(
                "UPDATE daily_usage SET visits_count = visits_count + 1
                 WHERE user_id = :user_id AND usage_date = CURDATE()"
            );
            $update->execute(['user_id' => $userId]);

            if ($ownsTransaction) {
                $this->db->commit();
            }

            $newUsed = $used + 1;
            return [
                'allowed' => true,
                'reason' => null,
                'used' => $newUsed,
                'limit' => $limit,
                'remaining' => $limit > 0 ? max(0, $limit - $newUsed) : null
            ];
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    public function getActivePlan(int $userId): ?array
    {
        $statement = $this->db->prepare(
            "SELECT p.id AS plan_id, p.name AS plan_name,
                    p.daily_visit_limit, p.website_limit
             FROM subscriptions s
             INNER JOIN plans p ON p.id = s.plan_id
             WHERE s.user_id = :user_id
               AND s.status = 'active'
               AND p.is_active = 1
               AND (s.starts_at IS NULL OR s.starts_at <= NOW())
               AND (s.ends_at IS NULL OR s.ends_at >= NOW())
             ORDER BY s.id DESC
             LIMIT 1
             FOR UPDATE"
        );
        $statement->execute(['user_id' => $userId]);
        $plan = $statement->fetch(PDO::FETCH_ASSOC);
        return $plan ?: null;
    }

    public function getUsage(int $userId): array
    {
        if ($userId <= 0) {
            throw new RuntimeException('Invalid user identifier.');
        }

        $plan = $this->getActivePlan($userId);
        if ($plan === null) {
            return [
                'has_subscription' => false,
                'used' => 0,
                'limit' => 0,
                'remaining' => 0
            ];
        }

        $statement = $this->db->prepare(
            "SELECT visits_count FROM daily_usage
             WHERE user_id = :user_id AND usage_date = CURDATE()
             LIMIT 1"
        );
        $statement->execute(['user_id' => $userId]);
        $used = (int) ($statement->fetchColumn() ?: 0);
        $limit = (int) $plan['daily_visit_limit'];

        return [
            'has_subscription' => true,
            'used' => $used,
            'limit' => $limit,
            'remaining' => $limit > 0 ? max(0, $limit - $used) : null
        ];
    }
}
