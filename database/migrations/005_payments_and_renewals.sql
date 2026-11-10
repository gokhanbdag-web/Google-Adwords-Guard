ALTER TABLE subscriptions
 ADD COLUMN auto_renew TINYINT(1) NOT NULL DEFAULT 0 AFTER provider_subscription_id,
 ADD COLUMN renewal_consent_at DATETIME NULL AFTER auto_renew,
 ADD COLUMN cancelled_at DATETIME NULL AFTER renewal_consent_at,
 ADD COLUMN cancellation_reason VARCHAR(100) NULL AFTER cancelled_at;
CREATE TABLE payments (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id BIGINT UNSIGNED NOT NULL,plan_id BIGINT UNSIGNED NOT NULL,subscription_id BIGINT UNSIGNED NULL,
 provider ENUM('paytr','iyzico') NOT NULL,merchant_order_id VARCHAR(64) NOT NULL UNIQUE,provider_payment_id VARCHAR(190) NULL,provider_token VARCHAR(190) NULL,
 amount DECIMAL(12,2) NOT NULL,currency CHAR(3) NOT NULL,status ENUM('pending','paid','failed','refunded') NOT NULL DEFAULT 'pending',
 auto_renew_requested TINYINT(1) NOT NULL DEFAULT 0,auto_renew_consent_at DATETIME NULL,failure_reason VARCHAR(500) NULL,provider_response JSON NULL,paid_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
 INDEX idx_payment_user_created(user_id,created_at),INDEX idx_payment_status_created(status,created_at),INDEX idx_payment_provider_token(provider,provider_token),
 UNIQUE KEY uq_payment_provider_reference(provider,provider_payment_id),
 CONSTRAINT fk_payment_user FOREIGN KEY(user_id) REFERENCES users(id),CONSTRAINT fk_payment_plan FOREIGN KEY(plan_id) REFERENCES plans(id),
 CONSTRAINT fk_payment_subscription FOREIGN KEY(subscription_id) REFERENCES subscriptions(id)
) ENGINE=InnoDB;
CREATE TABLE payment_provider_plans (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,plan_id BIGINT UNSIGNED NOT NULL,provider ENUM('paytr','iyzico') NOT NULL,
 provider_plan_reference VARCHAR(190) NOT NULL,is_active TINYINT(1) NOT NULL DEFAULT 1,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,UNIQUE KEY uq_provider_plan(plan_id,provider),
 CONSTRAINT fk_provider_plan FOREIGN KEY(plan_id) REFERENCES plans(id) ON DELETE CASCADE
) ENGINE=InnoDB;
CREATE TABLE payment_webhook_events (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,provider ENUM('paytr','iyzico') NOT NULL,event_key VARCHAR(190) NOT NULL,payload_hash CHAR(64) NOT NULL,
 processing_status ENUM('received','processed','failed') NOT NULL DEFAULT 'received',processed_at DATETIME NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_payment_webhook_event(provider,event_key)
) ENGINE=InnoDB;