# AdGuard Pro

Native PHP 8.2+ and MySQL 8+ SaaS foundation for website traffic analysis, risk scoring, IP review, and Google Ads campaign IP exclusions.

## Requirements
- PHP 8.2+
- MySQL 8.0+
- Composer
- PHP extensions: PDO MySQL, JSON, OpenSSL, cURL, mbstring, DOM

## Fresh installation
1. Install and start MySQL 8, and create a dedicated database user with least-privilege permissions.
2. Create a database named `adguard` and import `database/schema.sql`. The base schema uses `CREATE TABLE IF NOT EXISTS` and does not drop existing tables. It does not alter existing table definitions; use the numbered migrations for upgrades.
3. Copy `.env.example` to `.env`.
4. Set `APP_URL`, database credentials, and a unique random `APP_KEY` of at least 32 characters.
5. Run `composer install --no-dev --optimize-autoloader`.
6. Import `database/seeders.sql`.
7. Apply migrations in numeric order: `database/migrations/002_tracking_and_ip_management.sql`, `003_google_ads_actions.sql`, `004_google_ads_account_exclusions.sql`, then `005_payments_and_renewals.sql`.
8. Set the web server document root to `public/`. If your host requires the repository root, the root `.htaccess` forwards requests to `public/`; verify Apache rewrite support.
9. Register the first account and promote it to admin with a controlled database operation:
   `UPDATE users SET role='admin' WHERE email='your-admin-email';`

## Environment
- Set `APP_ENV=production`, `APP_DEBUG=false`, and use HTTPS. If the app is behind a reverse proxy, list only the proxy's exact IP addresses in `TRUSTED_PROXIES`; forwarded client IP headers are ignored unless `REMOTE_ADDR` is on that list.
- Keep `.env` outside the public document root.
- Generate a random APP_KEY; changing it later makes existing encrypted Google tokens unreadable.
- Google OAuth needs a configured OAuth client and redirect URI.
- Google Ads API v26 access is tied to the Google Cloud project that owns the OAuth client. The developer-token requirement was sunset on September 9, 2026; this application does not request or send a developer-token header. Enable the Google Ads API and obtain the required project access level in Google Cloud Console. See the [v26 API reference](https://developers.google.com/google-ads/api/reference/rpc/v26/overview) and [customer-level negative criteria service](https://developers.google.com/google-ads/api/reference/rpc/v26/CustomerNegativeCriterionService/MutateCustomerNegativeCriteria).
- `GOOGLE_ADS_WRITES_ENABLED=false` is the safe default. It runs API validation only. To enable real changes, set it to true and require a separate live-confirmation checkbox for every exclusion or removal.
- Tracking never writes to Google Ads automatically. Only a user-approved IP rule and a separately confirmed action can trigger a Google Ads API write.

## Tracking
Add the script shown on the Sites page to the registered website. Requests are sent to `/track` using a `text/plain` JSON beacon. The website must be active and its owner must have an active subscription. A daily quota is consumed atomically with the visit insert. Duplicate event IDs do not consume quota. The server strips query strings and fragments from stored page/referrer URLs. Visit records contain the server-observed IP address and user agent; set `VISIT_RETENTION_DAYS` (default 90, range 7–3650) and run `php bin/cron.php` every five minutes to prune old visit rows. Customize the privacy notice for your own legal basis and retention policy; this software does not guarantee KVKK/GDPR compliance.

## Google Ads
Connect an account from the Google Ads page. Account-level IP exclusions apply across the customer account (up to 500 IP entries). Campaign-level exclusions are restricted to supported Search, Display and Shopping campaign types (up to 500 per campaign). Both require an active, approved deny rule; only IPv4 addresses are sent to Google Ads. Live writes and removals require `GOOGLE_ADS_WRITES_ENABLED=true` plus an explicit confirmation checkbox. API attempts are recorded in `google_ads_actions`.

## Cron and queue
Run the maintenance command every 5 minutes:
`php bin/cron.php`

Run one queued job every minute (or more frequently):
`php bin/queue-worker.php`

The worker supports `google_ip_exclusion` jobs with JSON payload keys `connection_id`, `ip_rule_id`, optional `scope` (`campaign` or `account`), `campaign_id` for campaign scope, and `confirm_live` (boolean). Queue rows must also include the owning `user_id` and `website_id`. Live changes require both `confirm_live=true` and `GOOGLE_ADS_WRITES_ENABLED=true`; otherwise the worker uses API validation only.

## Payment processing
- PayTR hosted iFrame one-time checkout and iyzico Checkout Form one-time checkout are wired into customer checkout.
- iyzico automatic renewal uses its native Subscription API. Create monthly/yearly pricing plans for local packages in iyzico and save each reference under Admin > Plans. Configure subscription notifications at `https://YOUR_DOMAIN/payments/iyzico/webhook` and enable X-IYZ-SIGNATURE-V3.
- Set IYZICO_MERCHANT_ID, IYZICO_API_KEY, IYZICO_SECRET_KEY and IYZICO_BASE_URL. Use sandbox credentials first.
- In the PayTR merchant panel, set the server notification/callback URL to `https://YOUR_DOMAIN/payments/paytr/callback`. Do not use the browser return URL as proof of payment.
- PayTR automatic renewal is not enabled in this release. Its recurring path requires separate card-storage/Direct API and Non3D merchant permissions; PayTR remains available for one-time checkout.
- Customers can cancel iyzico renewal from their dashboard. PAN/CVV are never stored by this application.
- Test payments, callbacks, renewal notifications, cancellations and failure scenarios in sandbox before using live credentials.

## Validation
GitHub Actions runs Composer manifest validation and PHP syntax checks on the integration branch. A successful lint run is not a substitute for MySQL integration tests, OAuth consent testing, or a production deployment rehearsal.
