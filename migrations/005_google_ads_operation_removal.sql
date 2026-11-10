-- AdGuard Pro migration 005
-- Run once on existing installations after backing up the database.

USE adguard;

ALTER TABLE google_ads_operations
    MODIFY COLUMN action ENUM('validate','create','remove') NOT NULL,
    MODIFY COLUMN status ENUM('validated','succeeded','already_exists','not_found','failed') NOT NULL;
