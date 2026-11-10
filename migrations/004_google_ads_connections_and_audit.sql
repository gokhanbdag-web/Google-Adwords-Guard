-- AdGuard Pro migration 004
-- Run once on existing installations after backing up the database.

USE adguard;

ALTER TABLE google_connections
    ADD COLUMN is_selected TINYINT(1) NOT NULL DEFAULT 0 AFTER status;

CREATE TABLE IF NOT EXISTS google_ads_operations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    connection_id BIGINT UNSIGNED NOT NULL,
    customer_id VARCHAR(20) NOT NULL,
    campaign_id VARCHAR(30) NOT NULL,
    ip_address VARBINARY(16) NOT NULL,
    action ENUM('validate','create') NOT NULL,
    status ENUM('validated','succeeded','already_exists','failed') NOT NULL,
    request_payload JSON NOT NULL,
    response_payload JSON NULL,
    error_message TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ads_ops_user_created (user_id, created_at),
    INDEX idx_ads_ops_connection (connection_id, created_at),
    CONSTRAINT fk_ads_ops_user FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT fk_ads_ops_connection FOREIGN KEY (connection_id) REFERENCES google_connections(id)
) ENGINE=InnoDB;
