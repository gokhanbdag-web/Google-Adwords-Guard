-- AdGuard Pro migration 003
-- Run once after migration 002 on an existing installation.
-- Fresh installations should use the complete schema.sql and skip migrations.

USE adguard;

ALTER TABLE ip_rules
    ADD COLUMN user_id BIGINT UNSIGNED NULL AFTER id;

UPDATE ip_rules r
INNER JOIN websites w ON w.id = r.website_id
SET r.user_id = w.user_id
WHERE r.user_id IS NULL;

ALTER TABLE ip_rules
    MODIFY COLUMN user_id BIGINT UNSIGNED NOT NULL,
    MODIFY COLUMN website_id BIGINT UNSIGNED NULL,
    MODIFY COLUMN reason TEXT NULL,
    ADD COLUMN risk_score TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER status,
    ADD COLUMN evidence_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER risk_score,
    ADD COLUMN expires_at DATETIME NULL AFTER evidence_count,
    ADD INDEX idx_ip_rule_user_status (user_id, status),
    ADD CONSTRAINT fk_ip_rule_user FOREIGN KEY (user_id) REFERENCES users(id);
