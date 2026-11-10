-- ADGUARD PRO - Migration 002
-- MySQL 8.0+. Apply once after schema.sql. Never run schema.sql on production.
ALTER TABLE visits
    ADD INDEX idx_visit_website_suspicious_time (website_id, is_suspicious, created_at),
    ADD INDEX idx_visit_website_risk_time (website_id, risk_level, created_at),
    ADD INDEX idx_visit_website_score_time (website_id, risk_score, created_at);

ALTER TABLE ip_rules
    ADD COLUMN source_visit_id BIGINT UNSIGNED NULL AFTER website_id,
    ADD COLUMN risk_score TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER source,
    ADD COLUMN evidence_count SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER risk_score,
    ADD COLUMN expires_at DATETIME NULL AFTER evidence_count,
    ADD INDEX idx_ip_rule_website_ip_status (website_id, ip_address, status),
    ADD INDEX idx_ip_rule_created (website_id, created_at),
    ADD INDEX idx_ip_rule_source_visit (source_visit_id),
    ADD CONSTRAINT fk_ip_rule_source_visit
        FOREIGN KEY (source_visit_id) REFERENCES visits(id) ON DELETE SET NULL;

CREATE TABLE ip_rule_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ip_rule_id BIGINT UNSIGNED NULL,
    website_id BIGINT UNSIGNED NOT NULL,
    actor_user_id BIGINT UNSIGNED NULL,
    ip_address VARBINARY(16) NOT NULL,
    action ENUM('created','approved','revoked','updated') NOT NULL,
    old_rule_type ENUM('allow','deny','candidate','excluded_google') NULL,
    new_rule_type ENUM('allow','deny','candidate','excluded_google') NULL,
    old_status ENUM('active','pending','revoked') NULL,
    new_status ENUM('active','pending','revoked') NULL,
    reason VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ip_history_rule (ip_rule_id, created_at),
    INDEX idx_ip_history_website (website_id, created_at),
    INDEX idx_ip_history_actor (actor_user_id, created_at),
    INDEX idx_ip_history_ip (website_id, ip_address, created_at),
    CONSTRAINT fk_ip_history_rule FOREIGN KEY (ip_rule_id) REFERENCES ip_rules(id) ON DELETE SET NULL,
    CONSTRAINT fk_ip_history_website FOREIGN KEY (website_id) REFERENCES websites(id) ON DELETE CASCADE,
    CONSTRAINT fk_ip_history_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
