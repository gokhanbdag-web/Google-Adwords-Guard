-- ADGUARD PRO - Migration 003: Google Ads write audit trail
-- Apply once after migrations 001/002 and back up the database first.
CREATE TABLE google_ads_actions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    google_connection_id BIGINT UNSIGNED NOT NULL,
    ip_rule_id BIGINT UNSIGNED NOT NULL,
    customer_id VARCHAR(20) NOT NULL,
    campaign_id BIGINT UNSIGNED NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    action_type ENUM('ip_exclusion','account_ip_exclusion','ip_exclusion_remove','account_ip_exclusion_remove') NOT NULL,
    status ENUM('processing','validated','succeeded','failed','already_exists','not_found') NOT NULL DEFAULT 'processing',
    provider_resource_name VARCHAR(255) NULL,
    error_message VARCHAR(1000) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_google_action_user (user_id, created_at),
    INDEX idx_google_action_rule (ip_rule_id, created_at),
    INDEX idx_google_action_campaign (customer_id, campaign_id, created_at),
    CONSTRAINT fk_google_action_user FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT fk_google_action_connection FOREIGN KEY (google_connection_id) REFERENCES google_connections(id),
    CONSTRAINT fk_google_action_rule FOREIGN KEY (ip_rule_id) REFERENCES ip_rules(id)
) ENGINE=InnoDB;
