# AdGuard Pro

Google Ads invalid-traffic monitoring and IP-risk management SaaS, built with native PHP 8.2+ and MySQL 8.0+.

> Current implementation status: account registration, seven-day trial provisioning, website registration, browser tracking, visit storage, initial risk scoring, quota enforcement, manual-review IP candidates, Google OAuth account linking, campaign listing, IP-exclusion validation, and opt-in campaign IP-exclusion writes are implemented. A live Google Ads account test is still required. Google Ads write operations remain disabled by default.

## Requirements

- PHP 8.2 or newer
- MySQL 8.0 or newer
- Composer 2
- PHP extensions: PDO, pdo_mysql, json, openssl, curl, mbstring
- Apache with mod_rewrite and AllowOverride enabled, or equivalent web-server routing

## Fresh installation

1. Create a MySQL database/user with least-privilege permissions.
2. Upload the project files to the application directory.
3. Run `composer install --no-dev --optimize-autoloader`.
4. Import `schema.sql` into the new database.
5. Import `seeders.sql` to create the trial and paid plans plus starter content.
6. Copy `.env.example` to `.env` and set the application URL and database credentials.
7. Generate an application key, for example with:
   `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"`
   Put the generated value in `APP_KEY`.
8. Point the web server to the application root and enable the supplied `.htaccess`. HTTPS is required for production.
9. Visit the application URL and register a customer account. Registration activates a seven-day trial. To promote an existing account to administrator, run `php bin/create-admin.php user@example.com` from the project root over SSH/CLI.

For local HTTP development only, set `SESSION_SECURE=false`. Keep it `true` in production.

## Database upgrades

- `schema.sql` is for fresh installations. It no longer drops existing tables.
- Existing installations based on the earlier schema must take a database backup first.
- If migration 002 has not already been applied, run `migrations/002_tracking_and_ip_management.sql`, then run `migrations/003_ip_rule_service_alignment.sql` and `migrations/004_google_ads_connections_and_audit.sql`.
- Do not run upgrade migrations on a fresh database created from the complete current `schema.sql`.

## Google Ads API setup

1. Create or select a Google Cloud project and enable the Google Ads API.
2. Configure an OAuth consent screen and create a Web application OAuth client.
3. Add the exact `GOOGLE_REDIRECT_URI` value as an authorized redirect URI.
4. Set `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, and `GOOGLE_REDIRECT_URI` in `.env`.
5. Set `GOOGLE_ADS_API_VERSION=v26`.
6. Confirm that the Google Cloud project has the required Google Ads API access level for the accounts you intend to manage.
7. Sign in to AdGuard, open Google Ads, and connect the Google account. Select the customer account and, when using a manager account, provide its manager customer ID.

The Google Ads API developer-token sunset took effect on September 9, 2026. This project therefore does not require or send a developer-token header; API access is associated with the Google Cloud project that owns the OAuth credentials. Production-account access may require Explorer access or another approved access level in Google Cloud Console.

The default `GOOGLE_ADS_WRITES_ENABLED=false` setting permits API validation only. To allow real campaign changes, an operator must deliberately set it to `true`; the user must also tick the live-operation confirmation for each individual IP exclusion. All operations are recorded in `google_ads_operations`. Start with validation mode and test with an account you control.

## Website tracking

After adding a website, copy the tracking snippet shown in the Sites page into the website's HTML, preferably before the closing `</body>` tag. The script sends a minimal page-view event to the AdGuard tracking endpoint. The server validates the site key, origin, event identifier and URL before storing a visit.

The tracking endpoint uses the visitor IP observed by the server. Do not configure arbitrary forwarded-IP headers as trusted without a correctly configured trusted-proxy layer.

## Safety defaults

- Risk scoring is advisory and does not itself block a visitor.
- Repeated high-risk activity creates a pending IP review candidate.
- A candidate is not an active deny rule until a user reviews and approves it.
- Google Ads writes are disabled by default. Do not enable them until OAuth, account selection, confirmation, audit logging, and API error handling have been implemented and tested.
- Never commit `.env`, access tokens, refresh tokens, private keys, or production database credentials.

## Development

Work is being performed on the `development/adguard-pro` branch. The `main` branch is intentionally left unchanged until the feature set and tests are reviewed.

## Current limitations

- Payment collection and paid-plan subscription lifecycle are not implemented.
- Email verification and password reset are not implemented.
- Admin plan/user management is not complete.
- Live Google Ads API behavior still needs verification against a real authorized account and the access level assigned to the deploying Cloud project. Removal/rollback of IP exclusions is not yet exposed in the UI.
- Automated tests and production deployment verification still need to be added.
