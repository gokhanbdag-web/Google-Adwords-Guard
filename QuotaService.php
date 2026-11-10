
<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;
use Throwable;

final class QuotaService
{
    public function __construct(
        private readonly PDO $db
    ) {
    }

    /**
     * Checks the subscription and consumes one daily visit.
     *
     * Must be called inside the visit recording transaction whenever
     * possible, before inserting the visit.
     */
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
                throw new RuntimeException(
                    'No active subscription was found.'
                );
            }

            $limit = $plan['daily_visit_limit'] === null
                ? null
                : (int) $plan['daily_visit_limit'];

            $usageInsert = $this->db->prepare(
                "INSERT INTO daily_usage (
                    user_id,
                    usage_date,
                    visits_count
                ) VALUES (
                    :user_id,
                    CURDATE(),
                    0
                )
                ON DUPLICATE KEY UPDATE
                    user_id = VALUES(user_id)"
            );

            $usageInsert->execute([
                'user_id' => $userId
            ]);

            $usageQuery = $this->db->prepare(
                "SELECT visits_count
                 FROM daily_usage
                 WHERE user_id = :user_id
                   AND usage_date = CURDATE()
                 FOR UPDATE"
            );

            $usageQuery->execute([
                'user_id' => $userId
            ]);

            $usage = $usageQuery->fetch(PDO::FETCH_ASSOC);

            if (!$usage) {
                throw new RuntimeException(
                    'Unable to retrieve daily usage.'
                );
            }

            $current = (int) $usage['visits_count'];

            if ($limit !== null && $limit > 0 && $current >= $limit) {
                if ($ownsTransaction) {
                    $this->db->commit();
                }

                return [
                    'allowed' => false,
                    'reason' => 'daily_quota_exceeded',
                    'used' => $current,
                    'limit' => $limit,
                    'remaining' => 0
                ];
            }

            $increment = $this->db->prepare(
                "UPDATE daily_usage
                 SET visits_count = visits_count + 1
                 WHERE user_id = :user_id
                   AND usage_date = CURDATE()"
            );

            $increment->execute([
                'user_id' => $userId
            ]);

            $newUsage = $current + 1;

            if ($ownsTransaction) {
                $this->db->commit();
            }

            return [
                'allowed' => true,
                'reason' => null,
                'used' => $newUsage,
                'limit' => $limit,
                'remaining' => $limit === null || $limit <= 0
                    ? null
                    : max(0, $limit - $newUsage)
            ];
        } catch (Throwable $exception) {
            if (
                $ownsTransaction &&
                $this->db->inTransaction()
            ) {
                $this->db->rollBack();
            }

            throw $exception;
        }
    }

    /**
     * Reads the active subscription plan without consuming quota.
     */
    public function getActivePlan(int $userId): ?array
    {
        $statement = $this->db->prepare(
            "SELECT
                p.id AS plan_id,
                p.name AS plan_name,
                p.daily_visit_limit
             FROM subscriptions s
             INNER JOIN plans p ON p.id = s.plan_id
             WHERE s.user_id = :user_id
               AND s.status = 'active'
               AND (
                    s.current_period_end IS NULL
                    OR s.current_period_end >= NOW()
               )
             ORDER BY s.id DESC
             LIMIT 1
             FOR UPDATE"
        );

        $statement->execute([
            'user_id' => $userId
        ]);

        $plan = $statement->fetch(PDO::FETCH_ASSOC);

        return $plan ?: null;
    }

    /**
     * Returns current usage without changing it.
     */
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
            "SELECT visits_count
             FROM daily_usage
             WHERE user_id = :user_id
               AND usage_date = CURDATE()
             LIMIT 1"
        );

        $statement->execute([
            'user_id' => $userId
        ]);

        $usage = $statement->fetch(PDO::FETCH_ASSOC);

        $used = (int) ($usage['visits_count'] ?? 0);

        $limit = $plan['daily_visit_limit'] === null
            ? null
            : (int) $plan['daily_visit_limit'];

        return [
            'has_subscription' => true,
            'used' => $used,
            'limit' => $limit,
            'remaining' => $limit === null || $limit <= 0
                ? null
                : max(0, $limit - $used)
        ];
    }
}
