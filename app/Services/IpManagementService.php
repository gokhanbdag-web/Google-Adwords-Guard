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
        $packed = $this->packIp($ipAddress);

        $owns = !$this->db->inTransaction();
        if ($owns) {
            $this->db->beginTransaction();
        }

        try {
            $find = $this->db->prepare(
                'SELECT id, status FROM ip_rules
                 WHERE website_id = :website_id AND ip_address = :ip_address AND rule_type = :rule_type
                 LIMIT 1 FOR UPDATE'
            );
            $find->execute([
                'website_id' => $websiteId,
                'ip_address' => $packed,
                'rule_type' => $ruleType
            ]);
            $existing = $find->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                $id = (int) $existing['id'];
                $update = $this->db->prepare(
                    "UPDATE ip_rules
                     SET status = 'active', source = 'manual', reason = :reason, expires_at = :expires_at
                     WHERE id = :id"
                );
                $update->execute(['reason' => $reason, 'expires_at' => $expiresAt, 'id' => $id]);
                $this->recordHistory(
                    $id, $websiteId, $userId, $packed, 'updated',
                    $ruleType, $ruleType, (string) $existing['status'], 'active', $reason
                );
            } else {
                $insert = $this->db->prepare(
                    "INSERT INTO ip_rules
                        (website_id, ip_address, rule_type, reason, source, status, expires_at)
                     VALUES
                        (:website_id, :ip_address, :rule_type, :reason, 'manual', 'active', :expires_at)"
                );
                $insert->execute([
                    'website_id' => $websiteId,
                    'ip_address' => $packed,
                    'rule_type' => $ruleType,
                    'reason' => $reason,
                    'expires_at' => $expiresAt
                ]);
                $id = (int) $this->db->lastInsertId();
                $this->recordHistory(
                    $id, $websiteId, $userId, $packed, 'created',
                    null, $ruleType, null, 'active', $reason
                );
            }

            if ($owns) {
                $this->db->commit();
            }
            return $id;
        } catch (Throwable $exception) {
            if ($owns && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
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
        $threshold = max(1, min(100, (int) env('RISK_CANDIDATE_THRESHOLD', 80)));
        $minimumEvents = max(1, min(10000, (int) env('RISK_CANDIDATE_MIN_EVENTS', 3)));

        if ($riskScore < $threshold || $evidenceCount < $minimumEvents) {
            return null;
        }

        $packed = $this->packIp($ipAddress);
        $owns = !$this->db->inTransaction();
        if ($owns) {
            $this->db->beginTransaction();
        }

        try {
            $allow = $this->db->prepare(
                "SELECT r.id FROM ip_rules r
                 WHERE r.website_id = :website_id AND r.ip_address = :ip_address
                   AND r.rule_type = 'allow' AND r.status = 'active'
                   AND (r.expires_at IS NULL OR r.expires_at > NOW())
                 LIMIT 1 FOR UPDATE"
            );
            $allow->execute(['website_id' => $websiteId, 'ip_address' => $packed]);
            if ($allow->fetchColumn()) {
                if ($owns) $this->db->commit();
                return null;
            }

            $activeDeny = $this->db->prepare(
                "SELECT id FROM ip_rules
                 WHERE website_id = :website_id AND ip_address = :ip_address
                   AND rule_type = 'deny' AND status = 'active'
                 LIMIT 1 FOR UPDATE"
            );
            $activeDeny->execute(['website_id' => $websiteId, 'ip_address' => $packed]);
            if ($activeDeny->fetchColumn()) {
                if ($owns) $this->db->commit();
                return null;
            }

            $reason = json_encode(
                array_values($reasons),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
            if (mb_strlen($reason) > 500) {
                $reason = mb_substr($reason, 0, 497) . '...';
            }

            $find = $this->db->prepare(
                "SELECT id, source, status
                 FROM ip_rules
                 WHERE website_id = :website_id AND ip_address = :ip_address
                   AND rule_type = 'candidate'
                 LIMIT 1 FOR UPDATE"
            );
            $find->execute(['website_id' => $websiteId, 'ip_address' => $packed]);
            $existing = $find->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                if ($existing['source'] !== 'automatic' || $existing['status'] !== 'pending') {
                    if ($owns) $this->db->commit();
                    return null;
                }

                $id = (int) $existing['id'];
                $update = $this->db->prepare(
                    "UPDATE ip_rules
                     SET risk_score = GREATEST(risk_score, :score),
                         evidence_count = GREATEST(evidence_count, :evidence),
                         reason = :reason,
                         source_visit_id = COALESCE(:visit_id, source_visit_id)
                     WHERE id = :id"
                );
                $update->execute([
                    'score' => $riskScore,
                    'evidence' => $evidenceCount,
                    'reason' => $reason,
                    'visit_id' => $sourceVisitId,
                    'id' => $id
                ]);
                $this->recordHistory(
                    $id, $websiteId, null, $packed, 'updated',
                    'candidate', 'candidate', 'pending', 'pending', $reason
                );
            } else {
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
                $id = (int) $this->db->lastInsertId();
                $this->recordHistory(
                    $id, $websiteId, null, $packed, 'created',
                    null, 'candidate', null, 'pending', $reason
                );
            }

            if ($owns) {
                $this->db->commit();
            }
            return $id;
        } catch (Throwable $exception) {
            if ($owns && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    public function approveCandidate(int $userId, int $candidateId): bool
    {
        $owns = !$this->db->inTransaction();
        if ($owns) {
            $this->db->beginTransaction();
        }

        try {
            $find = $this->db->prepare(
                "SELECT r.website_id, r.ip_address, r.rule_type, r.status, r.reason
                 FROM ip_rules r
                 INNER JOIN websites w ON w.id = r.website_id
                 WHERE r.id = :id AND w.user_id = :user_id
                   AND r.rule_type = 'candidate' AND r.source = 'automatic' AND r.status = 'pending'
                 LIMIT 1 FOR UPDATE"
            );
            $find->execute(['id' => $candidateId, 'user_id' => $userId]);
            $candidate = $find->fetch(PDO::FETCH_ASSOC);

            if (!$candidate) {
                if ($owns) $this->db->commit();
                return false;
            }

            $packed = (string) $candidate['ip_address'];
            $websiteId = (int) $candidate['website_id'];

            $existingDeny = $this->db->prepare(
                "SELECT id, status, reason FROM ip_rules
                 WHERE website_id = :website_id AND ip_address = :ip_address
                   AND rule_type = 'deny' LIMIT 1 FOR UPDATE"
            );
            $existingDeny->execute(['website_id' => $websiteId, 'ip_address' => $packed]);
            $deny = $existingDeny->fetch(PDO::FETCH_ASSOC);

            if ($deny && $deny['status'] === 'active') {
                $updateCandidate = $this->db->prepare(
                    "UPDATE ip_rules SET status = 'revoked' WHERE id = :id"
                );
                $updateCandidate->execute(['id' => $candidateId]);
                $this->recordHistory(
                    $candidateId, $websiteId, $userId, $packed, 'revoked',
                    'candidate', 'candidate', 'pending', 'revoked',
                    'Existing active deny rule already covers this IP.'
                );
            } elseif ($deny) {
                $updateDeny = $this->db->prepare(
                    "UPDATE ip_rules SET status = 'active', source = 'manual',
                     reason = :reason, risk_score = :score, evidence_count = :evidence
                     WHERE id = :id"
                );
                $updateDeny->execute([
                    'reason' => (string) $candidate['reason'],
                    'score' => 0,
                    'evidence' => 0,
                    'id' => (int) $deny['id']
                ]);
                $updateCandidate = $this->db->prepare(
                    "UPDATE ip_rules SET status = 'revoked' WHERE id = :id"
                );
                $updateCandidate->execute(['id' => $candidateId]);
                $this->recordHistory(
                    (int) $deny['id'], $websiteId, $userId, $packed, 'approved',
                    'deny', 'deny', (string) $deny['status'], 'active', (string) $candidate['reason']
                );
                $this->recordHistory(
                    $candidateId, $websiteId, $userId, $packed, 'revoked',
                    'candidate', 'candidate', 'pending', 'revoked',
                    'Merged into existing deny rule.'
                );
            } else {
                $update = $this->db->prepare(
                    "UPDATE ip_rules
                     SET rule_type = 'deny', status = 'active', source = 'manual'
                     WHERE id = :id"
                );
                $update->execute(['id' => $candidateId]);
                $this->recordHistory(
                    $candidateId, $websiteId, $userId, $packed, 'approved',
                    'candidate', 'deny', 'pending', 'active', (string) $candidate['reason']
                );
            }

            if ($owns) {
                $this->db->commit();
            }
            return true;
        } catch (Throwable $exception) {
            if ($owns && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    public function revokeRule(int $userId, int $ruleId): bool
    {
        $owns = !$this->db->inTransaction();
        if ($owns) {
            $this->db->beginTransaction();
        }

        try {
            $find = $this->db->prepare(
                "SELECT r.website_id, r.ip_address, r.rule_type, r.status, r.reason
                 FROM ip_rules r
                 INNER JOIN websites w ON w.id = r.website_id
                 WHERE r.id = :id AND w.user_id = :user_id
                   AND r.status IN ('active', 'pending')
                 LIMIT 1 FOR UPDATE"
            );
            $find->execute(['id' => $ruleId, 'user_id' => $userId]);
            $rule = $find->fetch(PDO::FETCH_ASSOC);

            if (!$rule) {
                if ($owns) $this->db->commit();
                return false;
            }

            $update = $this->db->prepare("UPDATE ip_rules SET status = 'revoked' WHERE id = :id");
            $update->execute(['id' => $ruleId]);
            $this->recordHistory(
                $ruleId,
                (int) $rule['website_id'],
                $userId,
                (string) $rule['ip_address'],
                'revoked',
                (string) $rule['rule_type'],
                (string) $rule['rule_type'],
                (string) $rule['status'],
                'revoked',
                (string) $rule['reason']
            );

            if ($owns) {
                $this->db->commit();
            }
            return true;
        } catch (Throwable $exception) {
            if ($owns && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    private function recordHistory(
        int $ruleId,
        int $websiteId,
        ?int $actorUserId,
        string $packedIp,
        string $action,
        ?string $oldType,
        ?string $newType,
        ?string $oldStatus,
        ?string $newStatus,
        ?string $reason
    ): void {
        $statement = $this->db->prepare(
            'INSERT INTO ip_rule_history
             (ip_rule_id, website_id, actor_user_id, ip_address, action,
              old_rule_type, new_rule_type, old_status, new_status, reason, created_at)
             VALUES
             (:rule_id, :website_id, :actor_id, :ip_address, :action,
              :old_type, :new_type, :old_status, :new_status, :reason, NOW())'
        );
        $statement->bindValue(':rule_id', $ruleId, PDO::PARAM_INT);
        $statement->bindValue(':website_id', $websiteId, PDO::PARAM_INT);
        $statement->bindValue(':actor_id', $actorUserId, $actorUserId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $statement->bindValue(':ip_address', $packedIp, PDO::PARAM_LOB);
        $statement->bindValue(':action', $action);
        $statement->bindValue(':old_type', $oldType);
        $statement->bindValue(':new_type', $newType);
        $statement->bindValue(':old_status', $oldStatus);
        $statement->bindValue(':new_status', $newStatus);
        $statement->bindValue(':reason', $reason === null ? null : mb_substr($reason, 0, 500));
        $statement->execute();
    }

    private function assertWebsiteOwnership(int $userId, int $websiteId): void
    {
        $statement = $this->db->prepare(
            'SELECT id FROM websites WHERE id = :website_id AND user_id = :user_id LIMIT 1'
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
