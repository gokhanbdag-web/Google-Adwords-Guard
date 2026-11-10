-- ADGUARD PRO - Migration 005: explicit renewal consent audit timestamp
-- Apply once after migration 004.
ALTER TABLE payments
    ADD COLUMN auto_renew_consent_at DATETIME NULL AFTER auto_renew_requested;
