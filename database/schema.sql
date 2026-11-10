CREATE DATABASE IF NOT EXISTS adguard
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE adguard;

SET FOREIGN_KEY_CHECKS = 0;

SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(190) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin','customer') NOT NULL DEFAULT 'customer',
    status ENUM('active','suspended','pending') NOT NULL DEFAULT 'active',
    last_login_at DATETIME NULL,
    email_verified_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS plans (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    slug VARCHAR(140) NOT NULL UNIQUE,
    description TEXT NULL,
    price DECIMAL(12,2) NOT NULL DEFAULT 0,
    currency CHAR(3) NOT NULL DEFAULT 'TRY',
    billing_period ENUM('monthly','yearly') NOT NULL DEFAULT 'monthly',
    daily_visit_limit INT UNSIGNED NOT NULL DEFAULT 100,
    website_limit SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    features JSON NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS subscriptions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    plan_id BIGINT UNSIGNED NOT NULL,
    status ENUM('pending','active','cancelled','expired','suspended') NOT NULL DEFAULT 'pending',
    starts_at DATETIME NULL,
    ends_at DATETIME NULL,
    payment_provider VARCHAR(40) NULL,
    provider_subscription_id VARCHAR(190) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_subscription_user_status (user_id, status),
    CONSTRAINT fk_subscription_user FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT fk_subscription_plan FOREIGN KEY (plan_id) REFERENCES plans(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS websites (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(120) NOT NULL,
    domain VARCHAR(253) NOT NULL,
    site_key CHAR(64) NOT NULL UNIQUE,
    status ENUM('pending','active','paused') NOT NULL DEFAULT 'pending',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_website_user (user_id),
    INDEX idx_website_domain (domain),
    CONSTRAINT fk_website_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS visits (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    website_id BIGINT UNSIGNED NOT NULL,
    event_id CHAR(36) NOT NULL,
    ip_address VARBINARY(16) NOT NULL,
    user_agent VARCHAR(1000) NULL,
    page_url VARCHAR(2048) NULL,
    referrer VARCHAR(2048) NULL,
    country_code CHAR(2) NULL,
    risk_score TINYINT UNSIGNED NOT NULL DEFAULT 0,
    risk_level ENUM('low','medium','high','critical') NOT NULL DEFAULT 'low',
    detection_reasons JSON NULL,
    is_suspicious TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_visit_event (website_id, event_id),
    INDEX idx_visit_ip_time (ip_address, created_at),
    INDEX idx_visit_website_time (website_id, created_at),
    CONSTRAINT fk_visit_website FOREIGN KEY (website_id) REFERENCES websites(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS ip_rules (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    website_id BIGINT UNSIGNED NOT NULL,
    ip_address VARBINARY(16) NOT NULL,
    rule_type ENUM('allow','deny','candidate','excluded_google') NOT NULL,
    reason VARCHAR(500) NULL,
    source ENUM('manual','automatic','google_ads') NOT NULL DEFAULT 'manual',
    status ENUM('active','pending','revoked') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ip_rule (website_id, ip_address, rule_type),
    INDEX idx_ip_rule_status (website_id, status),
    CONSTRAINT fk_ip_rule_website FOREIGN KEY (website_id) REFERENCES websites(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS google_connections (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    customer_id VARCHAR(20) NOT NULL,
    login_customer_id VARCHAR(20) NULL,
    access_token TEXT NULL,
    refresh_token TEXT NOT NULL,
    token_expires_at DATETIME NULL,
    scope TEXT NULL,
    status ENUM('active','expired','revoked') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_google_user_customer (user_id, customer_id),
    CONSTRAINT fk_google_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS pages (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(190) NOT NULL,
    slug VARCHAR(190) NOT NULL UNIQUE,
    content LONGTEXT NULL,
    status ENUM('draft','published') NOT NULL DEFAULT 'draft',
    meta_title VARCHAR(190) NULL,
    meta_description VARCHAR(320) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS menus (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    location VARCHAR(40) NOT NULL,
    label VARCHAR(120) NOT NULL,
    url VARCHAR(500) NOT NULL,
    target VARCHAR(20) NOT NULL DEFAULT '_self',
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_menu_location (location, is_active, sort_order)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS settings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(190) NOT NULL UNIQUE,
    setting_value LONGTEXT NULL,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS daily_usage (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    usage_date DATE NOT NULL,
    visits_count INT UNSIGNED NOT NULL DEFAULT 0,
    UNIQUE KEY uq_usage_day (user_id, usage_date),
    CONSTRAINT fk_usage_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS queue_jobs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    website_id BIGINT UNSIGNED NOT NULL,
    job_type VARCHAR(60) NOT NULL,
    payload JSON NOT NULL,
    status ENUM('pending','processing','completed','failed') NOT NULL DEFAULT 'pending',
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at DATETIME NULL,
    last_error TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_queue_status (status, available_at),
    CONSTRAINT fk_queue_user FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT fk_queue_website FOREIGN KEY (website_id) REFERENCES websites(id)
) ENGINE=InnoDB;