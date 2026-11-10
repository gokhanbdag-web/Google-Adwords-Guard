-- ADGUARD PRO - Migration 005: Google Ads account-level exclusions and rollback audit states
-- Apply once after migration 003 and back up the database first.
ALTER TABLE google_ads_actions
    MODIFY COLUMN action_type ENUM(
        'ip_exclusion',
        'account_ip_exclusion',
        'ip_exclusion_remove',
        'account_ip_exclusion_remove'
    ) NOT NULL,
    MODIFY COLUMN status ENUM(
        'processing',
        'validated',
        'succeeded',
        'failed',
        'already_exists',
        'not_found'
    ) NOT NULL DEFAULT 'processing';
