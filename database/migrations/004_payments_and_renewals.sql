-- ADGUARD PRO - Migration 004: payments, explicit renewal consent and provider references
-- Apply once after migrations 001-003. Back up the database first.
ALTER TABLE subscriptions
    ADD COLUMN auto_renew TINYINT(1) NOT NULL DEFAULT 0 AFTER provider_subscription_id,
    ADD COLUMN renewal_consent_at DATETIME NULL AFTER auto_renew,
    ADD COLUMN cancelled_at DATETIME NULL AFTER renewal_consent_at,
    ADD COLUMN cancellation_reason VARCHAR(255) NULL AFTER cancelled_at,
    ADD INDEX idx_subscription_renewal (status, auto_renew, ends_at);

CREATE TABLE payments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    plan_id BIGINT UNSIGNED NOT NULL,
    subscription_id BIGINT UNSIGNED NULL,
    provider ENUM('paytr','iyzico','manual') NOT NULL,
    merchant_order_id VARCHAR(64) NOT NULL,
    provider_payment_id VARCHAR(190) NULL,
    provider_token VARCHAR(255) NULL,
    amount DECIMAL(12,2) NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'TRY',
    status ENUM('pending','paid','failed','refunded','cancelled') NOT NULL DEFAULT 'pending',
    auto_renew_requested TINYINT(1) NOT NULL DEFAULT 0,
    period_starts_at DATETIME NULL,
    period_ends_at DATETIME NULL,
    paid_at DATETIME NULL,
    failure_reason VARCHAR(500) NULL,
    provider_response JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_payment_order (merchant_order_id),
    UNIQUE KEY uq_payment_provider_id (provider, provider_payment_id),
    INDEX idx_payment_user (user_id, created_at),
    INDEX idx_payment_status (status, created_at),
    CONSTRAINT fk_payment_user FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT fk_payment_plan FOREIGN KEY (plan_id) REFERENCES plans(id),
    CONSTRAINT fk_payment_subscription FOREIGN KEY (subscription_id) REFERENCES subscriptions(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE payment_provider_plans (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    plan_id BIGINT UNSIGNED NOT NULL,
    provider ENUM('paytr','iyzico') NOT NULL,
    provider_plan_reference VARCHAR(190) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_provider_plan (plan_id, provider),
    UNIQUE KEY uq_provider_reference (provider, provider_plan_reference),
    CONSTRAINT fk_provider_plan FOREIGN KEY (plan_id) REFERENCES plans(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE payment_methods (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    provider ENUM('paytr','iyzico') NOT NULL,
    provider_customer_reference VARCHAR(190) NULL,
    provider_card_reference TEXT NULL,
    card_brand VARCHAR(40) NULL,
    card_last_four CHAR(4) NULL,
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    status ENUM('active','revoked','expired') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_payment_method_user (user_id, provider, status),
    CONSTRAINT fk_payment_method_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE payment_webhook_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    provider ENUM('paytr','iyzico') NOT NULL,
    event_key VARCHAR(190) NOT NULL,
    payload_hash CHAR(64) NOT NULL,
    processing_status ENUM('received','processed','ignored','failed') NOT NULL DEFAULT 'received',
    received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at DATETIME NULL,
    UNIQUE KEY uq_payment_webhook (provider, event_key),
    INDEX idx_payment_webhook_status (processing_status, received_at)
) ENGINE=InnoDB;
