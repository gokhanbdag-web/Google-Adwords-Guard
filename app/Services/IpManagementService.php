<?php
declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;
use Throwable;

final class IpManagementService
{
    public function __construct(private readonly PDO $db) {}

    public function evaluateIp(int $userId, int $websiteId, string $ipAddress): array
    {
        $packed = $this->packIp($ipAddress);
        $base = "SELECT r.id, r.rule_type, r.status, r.source, r.reason,
                        r.risk_score, r.evidence_count, r.expires_at
                 FROM ip_rules r
                 INNER JOIN websites w ON w.id = r.website_id
                 WHERE w.user_id = :user_id AND r.website_id = :website_id
                   AND r.ip_address = :ip_address AND r.status = 'active'
                   AND (r.expires_at IS NULL OR r.expires_at > NOW())";

        $allow = $this->db->prepare($base . " AND r.rule_type = 'allow' LIMIT 1");
        $allow->execute([
            'user_id' => $userId,
            'website_id' => $websiteId,
            'ip_address' => $packed
        ]);
        $rule = $allow->fetch(PDO::FETCH_ASSOC);
        if ($rule) {
            return ['decision' => 'allow', 'matched_rule' => $rule, 'is_allowed' => true, 'is_denied' => false];
        }

        $deny = $this->db->prepare($base . " AND r.rule_type = 'deny' LIMIT 1");
        $deny->execute([
            'user_id' => $userId,
            'website_id' => $websiteId,
            'ip_address' => $packed
        ]);
        $rule = $deny->fetch(PDO::FETCH_ASSOC);
        if ($rule) {
            return ['decision' => 'deny', 'matched_rule' => $rule, 'is_allowed' => false, 'is_denied' => true];
        }

        return ['decision' => 'monitor', 'matched_rule' => null, 'is_allowed' => false, 'is_denied' => false];
    }

    public function addManualRule(
        int $userId,
        int $websiteId,
        string $ipAddress,
        string $ruleType,
        string $reason,
        ?string $expiresAt = null
    ): int {
        if (!in_array($ruleType, ['allow', 'deny'], true)) {
            throw new RuntimeException('Invalid IP rule type.');
        }
        $this->assertWebsiteOwnership($userId, $websiteId);
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 500) {
            throw new RuntimeException('Reason must contain 1 to 500 characters.');
        }
        $this->validateExpiration($expiresAt);

        $statement = $this->db->prepare(
            "INSERT INTO ip_rules
                (website_id, ip_address, rule_type, reason, source, status, expires_at)
             VALUES
                (:website_id, :ip_address, :rule_type, :reason, 'manual', 'active', :expires_at)"
        );
        $statement->execute([
            'website_id' => $websiteId,
            'ip_address' => $this->packIp($ipAddress),
            'rule_type' => $ruleType,
            'reason' => $reason,
            'expires_at' => $expiresAt
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function createCandidate(
        int $userId,
        int $websiteId,
        string $ipAddress,
        int $riskScore,
        int $evidenceCount,
        array $reasons,
        ?int $sourceVisitId = null
    ): ?int {
        $this->assertWebsiteOwnership($userId, $websiteId);
        $riskScore = max(0, min(100, $riskScore));
        if ($riskScore < 80 || $evidenceCount < 3) {
            return null;
        }

        $packed = $this->packIp($ipAddress);
        $allow = $this->db->prepare(
            "SELECT r.id FROM ip_rules r
             INNER JOIN websites w ON w.id = r.website_id
             WHERE w.user_id = :user_id AND r.website_id = :website_id
               AND r.ip_address = :ip_address AND r.rule_type = 'allow'
               AND r.status = 'active'
               AND (r.expires_at IS NULL OR r.expires_at > NOW())
             LIMIT 1"
        );
        $allow->execute([
            'user_id' => $userId,
            'website_id' => $websiteId,
            'ip_address' => $packed
        ]);
        if ($allow->fetchColumn()) {
            return null;
        }

        $reason = json_encode(
            array_values($reasons),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        if (mb_strlen($reason) > 500) {
            $reason = mb_substr($reason, 0, 497) . '...';
        }

        $owns = !$this->db->inTransaction();
        if ($owns) {
            $this->db->beginTransaction();
        }

        try {
            $find = $this->db->prepare(
                "SELECT id FROM ip_rules
                 WHERE website_id = :website_id AND ip_address = :ip_address
                   AND rule_type = 'candidate' AND source = 'automatic'
                   AND status = 'pending'
                 LIMIT 1 FOR UPDATE"
            );
            $find->execute(['website_id' => $websiteId, 'ip_address' => $packed]);
            $id = $find->fetchColumn();

            if ($id) {
                $update = $this->db->prepare(
                    "UPDATE ip_rules SET risk_score = GREATEST(risk_score, :score),
                     evidence_count = GREATEST(evidence_count, :evidence),
                     reason = :reason, source_visit_id = COALESCE(:visit_id, source_visit_id)
                     WHERE id = :id"
                );
                $update->execute([
                    'score' => $riskScore,
                    'evidence' => $evidenceCount,
                    'reason' => $reason,
                    'visit_id' => $sourceVisitId,
                    'id' => (int) $id
                ]);
                if ($owns) {
                    $this->db->commit();
                }
                return (int) $id;
            }

            $insert = $this->db->prepare(
                "INSERT INTO ip_rules
                 (website_id, source_visit_id, ip_address, rule_type, reason,
                  source, status, risk_score, evidence_count, expires_at)
                 VALUES
                 (:website_id, :visit_id, :ip_address, 'candidate', :reason,
                  'automatic', 'pending', :score, :evidence, NULL)"
            );
            $insert->execute([
                'website_id' => $websiteId,
                'visit_id' => $sourceVisitId,
                'ip_address' => $packed,
                'reason' => $reason,
                'score' => $riskScore,
                'evidence' => $evidenceCount
            ]);
            $newId = (int) $this->db->lastInsertId();
            if ($owns) {
                $this->db->commit();
            }
            return $newId;
        } catch (Throwable $e) {
            if ($owns && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function approveCandidate(int $userId, int $candidateId): bool
    {
        $statement = $this->db->prepare(
            "UPDATE ip_rules r
             INNER JOIN websites w ON w.id = r.website_id
             SET r.rule_type = 'deny', r.status = 'active', r.source = 'manual'
             WHERE r.id = :id AND w.user_id = :user_id
               AND r.rule_type = 'candidate' AND r.status = 'pending'"
        );
        $statement->execute(['id' => $candidateId, 'user_id' => $userId]);
        return $statement->rowCount() === 1;
    }

    public function revokeRule(int $userId, int $ruleId): bool
    {
        $statement = $this->db->prepare(
            "UPDATE ip_rules r
             INNER JOIN websites w ON w.id = r.website_id
             SET r.status = 'revoked'
             WHERE r.id = :id AND w.user_id = :user_id
               AND r.status IN ('active', 'pending')"
        );
        $statement->execute(['id' => $ruleId, 'user_id' => $userId]);
        return $statement->rowCount() === 1;
    }

    private function assertWebsiteOwnership(int $userId, int $websiteId): void
    {
        $statement = $this->db->prepare(
            "SELECT id FROM websites WHERE id = :website_id AND user_id = :user_id LIMIT 1"
        );
        $statement->execute(['website_id' => $websiteId, 'user_id' => $userId]);
        if (!$statement->fetchColumn()) {
            throw new RuntimeException('Website does not belong to this user.');
        }
    }

    private function packIp(string $ip): string
    {
        $packed = inet_pton(trim($ip));
        if ($packed === false) {
            throw new RuntimeException('Invalid IP address.');
        }
        return $packed;
    }

    private function validateExpiration(?string $expiresAt): void
    {
        if ($expiresAt === null || $expiresAt === '') {
            return;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $expiresAt);
        if (!$date || $date->format('Y-m-d H:i:s') !== $expiresAt || $date <= new \DateTimeImmutable()) {
            throw new RuntimeException('Invalid expiration date.');
        }
    }
}
