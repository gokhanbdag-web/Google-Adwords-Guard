# AdGuard Pro

Native PHP 8.2+ and MySQL 8+ SaaS foundation for website traffic analysis, risk scoring, IP review, and Google Ads campaign IP exclusions.

## Requirements
- PHP 8.2+
- MySQL 8.0+
- Composer
- PHP extensions: PDO MySQL, JSON, OpenSSL, cURL, mbstring, DOM

## Fresh installation
1. Create an empty MySQL database.
2. Import `database/schema.sql`. **This file drops and recreates tables; use it only on an empty/new database.**
3. Copy `.env.example` to `.env`.
4. Set `APP_URL`, database credentials, and a unique random `APP_KEY` of at least 32 characters.
5. Run `composer install --no-dev --optimize-autoloader`.
6. Import `database/seeders.sql`.
7. Apply migrations in numeric order: `database/migrations/002_tracking_and_ip_management.sql`, then `003_google_ads_actions.sql`.
8. Set the web server document root to `public/`. If your host requires the repository root, the root `.htaccess` forwards requests to `public/`; verify Apache rewrite support.
9. Register the first account and promote it to admin with a controlled database operation:
   `UPDATE users SET role='admin' WHERE email='your-admin-email';`

## Environment
- Set `APP_ENV=production`, `APP_DEBUG=false`, and use HTTPS.
- Keep `.env` outside the public document root.
- Generate a random APP_KEY; changing it later makes existing encrypted Google tokens unreadable.
- Google OAuth needs a configured OAuth client and redirect URI.
- Google Ads access needs an approved developer token and the required account permissions.
- `GOOGLE_ADS_WRITES_ENABLED=false` is the safe default. Only set it to true after reviewing the Google Ads account and accepting the impact of campaign exclusions.
- `AUTO_BLOCK_ENABLED` remains disabled by default. Automatic Google Ads changes are not performed by the tracking endpoint.

## Tracking
Add the script shown on the Sites page to the registered website. Requests are sent to `/track`. The website must be active and its owner must have an active subscription. A daily quota is consumed atomically with the visit insert. Duplicate event IDs do not consume quota.

## Google Ads
Connect an account from the Google Ads page. Campaign IP exclusions require an active, approved deny rule, an explicit form submission, and `GOOGLE_ADS_WRITES_ENABLED=true`. Only IPv4 addresses are sent to Google Ads. Each API attempt is recorded in `google_ads_actions`.

## Cron and queue
Run the maintenance command every 5 minutes:
`php bin/cron.php`

Run one queued job every minute (or more frequently):
`php bin/queue-worker.php`

The worker supports `google_ip_exclusion` jobs with JSON payload keys `connection_id`, `campaign_id`, and `ip_rule_id`. Queue rows must also include the owning `user_id` and `website_id`.

## Payment processing
The application currently supports admin-managed manual subscription activation only. It does not claim to process online payments. A payment provider (for example PayTR or iyzico), merchant credentials, webhook verification, refund/cancellation behavior, and sandbox credentials must be selected and configured before online checkout can be enabled.

## Validation
GitHub Actions runs Composer manifest validation and PHP syntax checks on the integration branch. A successful lint run is not a substitute for MySQL integration tests, OAuth consent testing, or a production deployment rehearsal.
