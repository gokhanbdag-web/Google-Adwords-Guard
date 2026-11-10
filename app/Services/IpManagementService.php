<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;
use Throwable;

final class IpManagementService
{
    public function __construct(
        private readonly PDO $db
    ) {
    }

    /**
     * Checks IP rules.
     * Allowlist always takes precedence over denylist.
     */
    public function evaluateIp(
        int $userId,
        int $websiteId,
        string $ipAddress
    ): array {
        $packedIp = $this->packIp($ipAddress);

        $baseSql = "
            SELECT
                id,
                rule_type,
                status,
                source,
                reason,
                risk_score,
                evidence_count,
                expires_at
            FROM ip_rules
            WHERE user_id = :user_id
              AND ip_address = :ip_address
              AND (website_id = :website_id OR website_id IS NULL)
              AND status = 'active'
              AND (expires_at IS NULL OR expires_at > NOW())
        ";

        $allow = $this->db->prepare(
            $baseSql . "
                AND rule_type = 'allow'
                ORDER BY website_id DESC
                LIMIT 1"
        );

        $allow->execute([
            'user_id' => $userId,
            'ip_address' => $packedIp,
            'website_id' => $websiteId
        ]);

        $allowRule = $allow->fetch(PDO::FETCH_ASSOC);

        if ($allowRule) {
            return [
                'decision' => 'allow',
                'matched_rule' => $allowRule,
                'is_allowed' => true,
                'is_denied' => false
            ];
        }

        $deny = $this->db->prepare(
            $baseSql . "
                AND rule_type = 'deny'
                ORDER BY website_id DESC
                LIMIT 1"
        );

        $deny->execute([
            'user_id' => $userId,
            'ip_address' => $packedIp,
            'website_id' => $websiteId
        ]);

        $denyRule = $deny->fetch(PDO::FETCH_ASSOC);

        if ($denyRule) {
            return [
                'decision' => 'deny',
                'matched_rule' => $denyRule,
                'is_allowed' => false,
                'is_denied' => true
            ];
        }

        return [
            'decision' => 'monitor',
            'matched_rule' => null,
            'is_allowed' => false,
            'is_denied' => false
        ];
    }

    /**
     * Creates a manually approved allowlist or denylist rule.
     */
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

        if ($userId <= 0 || $websiteId <= 0) {
            throw new RuntimeException('Invalid user or website.');
        }

        $this->assertWebsiteOwnership($userId, $websiteId);

        $packedIp = $this->packIp($ipAddress);

        $reason = trim($reason);

        if ($reason === '') {
            throw new RuntimeException('A reason is required.');
        }

        if (mb_strlen($reason) > 1000) {
            throw new RuntimeException('Reason is too long.');
        }

        $this->validateExpiration($expiresAt);

        $statement = $this->db->prepare(
            "INSERT INTO ip_rules (
                user_id,
                website_id,
                ip_address,
                rule_type,
                status,
                source,
                risk_score,
                evidence_count,
                reason,
                expires_at,
                created_at
            ) VALUES (
                :user_id,
                :website_id,
                :ip_address,
                :rule_type,
                'active',
                'manual',
                0,
                0,
                :reason,
                :expires_at,
                NOW()
            )"
        );

        $statement->execute([
            'user_id' => $userId,
            'website_id' => $websiteId,
            'ip_address' => $packedIp,
            'rule_type' => $ruleType,
            'reason' => $reason,
            'expires_at' => $expiresAt
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Creates a pending automatic IP candidate.
     * It does not block the IP or modify Google Ads.
     */
    public function createCandidate(
        int $userId,
        int $websiteId,
        string $ipAddress,
        int $riskScore,
        int $evidenceCount,
        array $reasons
    ): ?int {
        if ($userId <= 0 || $websiteId <= 0) {
            throw new RuntimeException('Invalid user or website.');
        }

        $this->assertWebsiteOwnership($userId, $websiteId);

        $packedIp = $this->packIp($ipAddress);

        $riskScore = max(0, min(100, $riskScore));

        if ($riskScore < 80 || $evidenceCount < 3) {
            return null;
        }

        if ($this->hasAllowRule($userId, $websiteId, $packedIp)) {
            return null;
        }

        $encodedReasons = json_encode(
            array_values($reasons),
            JSON_THROW_ON_ERROR |
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        );

        $ownsTransaction = !$this->db->inTransaction();

        if ($ownsTransaction) {
            $this->db->beginTransaction();
        }

        try {
            $existing = $this->db->prepare(
                "SELECT id
                 FROM ip_rules
                 WHERE user_id = :user_id
                   AND website_id = :website_id
                   AND ip_address = :ip_address
                   AND rule_type = 'deny'
                   AND source = 'automatic'
                   AND status = 'pending'
                 LIMIT 1
                 FOR UPDATE"
            );

            $existing->execute([
                'user_id' => $userId,
                'website_id' => $websiteId,
                'ip_address' => $packedIp
            ]);

            $candidate = $existing->fetch(PDO::FETCH_ASSOC);

            if ($candidate) {
                $update = $this->db->prepare(
                    "UPDATE ip_rules
                     SET risk_score = GREATEST(risk_score, :risk_score),
                         evidence_count = GREATEST(
                             evidence_count,
                             :evidence_count
                         ),
                         reason = :reason
                     WHERE id = :id"
                );

                $update->execute([
                    'risk_score' => $riskScore,
                    'evidence_count' => $evidenceCount,
                    'reason' => $encodedReasons,
                    'id' => (int) $candidate['id']
                ]);

                if ($ownsTransaction) {
                    $this->db->commit();
                }

                return (int) $candidate['id'];
            }

            $insert = $this->db->prepare(
                "INSERT INTO ip_rules (
                    user_id,
                    website_id,
                    ip_address,
                    rule_type,
                    status,
                    source,
                    risk_score,
                    evidence_count,
                    reason,
                    expires_at,
                    created_at
                ) VALUES (
                    :user_id,
                    :website_id,
                    :ip_address,
                    'deny',
                    'pending',
                    'automatic',
                    :risk_score,
                    :evidence_count,
                    :reason,
                    NULL,
                    NOW()
                )"
            );

            $insert->execute([
                'user_id' => $userId,
                'website_id' => $websiteId,
                'ip_address' => $packedIp,
                'risk_score' => $riskScore,
                'evidence_count' => $evidenceCount,
                'reason' => $encodedReasons
            ]);

            $id = (int) $this->db->lastInsertId();

            if ($ownsTransaction) {
                $this->db->commit();
            }

            return $id;
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
     * Approves a pending candidate for internal denylist use.
     * This does not execute a Google Ads API operation.
     */
    public function approveCandidate(
        int $userId,
        int $candidateId
    ): bool {
        $statement = $this->db->prepare(
            "UPDATE ip_rules
             SET status = 'active',
                 source = 'manual'
             WHERE id = :id
               AND user_id = :user_id
               AND rule_type = 'deny'
               AND source = 'automatic'
               AND status = 'pending'"
        );

        $statement->execute([
            'id' => $candidateId,
            'user_id' => $userId
        ]);

        return $statement->rowCount() === 1;
    }

    /**
     * Revokes an existing IP rule.
     */
    public function revokeRule(
        int $userId,
        int $ruleId
    ): bool {
        $statement = $this->db->prepare(
            "UPDATE ip_rules
             SET status = 'revoked'
             WHERE id = :id
               AND user_id = :user_id
               AND status IN ('active', 'pending')"
        );

        $statement->execute([
            'id' => $ruleId,
            'user_id' => $userId
        ]);

        return $statement->rowCount() === 1;
    }

    private function hasAllowRule(
        int $userId,
        int $websiteId,
        string $packedIp
    ): bool {
        $statement = $this->db->prepare(
            "SELECT id
             FROM ip_rules
             WHERE user_id = :user_id
               AND ip_address = :ip_address
               AND (website_id = :website_id OR website_id IS NULL)
               AND rule_type = 'allow'
               AND status = 'active'
               AND (expires_at IS NULL OR expires_at > NOW())
             LIMIT 1"
        );

        $statement->execute([
            'user_id' => $userId,
            'ip_address' => $packedIp,
            'website_id' => $websiteId
        ]);

        return (bool) $statement->fetchColumn();
    }

    private function assertWebsiteOwnership(
        int $userId,
        int $websiteId
    ): void {
        $statement = $this->db->prepare(
            "SELECT id
             FROM websites
             WHERE id = :website_id
               AND user_id = :user_id
             LIMIT 1"
        );

        $statement->execute([
            'website_id' => $websiteId,
            'user_id' => $userId
        ]);

        if (!$statement->fetchColumn()) {
            throw new RuntimeException(
                'Website does not belong to this user.'
            );
        }
    }

    private function packIp(string $ipAddress): string
    {
        $packed = inet_pton(trim($ipAddress));

        if ($packed === false) {
            throw new RuntimeException('Invalid IP address.');
        }

        return $packed;
    }

    private function validateExpiration(?string $expiresAt): void
    {
        if ($expiresAt === null) {
            return;
        }

        $date = \DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s',
            $expiresAt
        );

        if (
            !$date ||
            $date->format('Y-m-d H:i:s') !== $expiresAt ||
            $date <= new \DateTimeImmutable()
        ) {
            throw new RuntimeException(
                'Invalid expiration date.'
            );
        }
    }
}
