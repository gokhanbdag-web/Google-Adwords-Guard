# AdGuard Pro

Google Ads invalid-traffic monitoring and IP-risk management SaaS, built with native PHP 8.2+ and MySQL 8.0+.

> Current implementation status: account registration, seven-day trial provisioning, website registration, browser tracking, visit storage, initial risk scoring, quota enforcement, and manual-review IP candidates are being assembled. Google Ads OAuth and live account/IP-exclusion API operations are not yet implemented. Google Ads write operations remain disabled by default.

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
9. Visit the application URL and register a customer account. Registration activates a seven-day trial.

For local HTTP development only, set `SESSION_SECURE=false`. Keep it `true` in production.

## Database upgrades

- `schema.sql` is for fresh installations. It no longer drops existing tables.
- Existing installations based on the earlier schema must take a database backup first.
- If migration 002 has not already been applied, run `migrations/002_tracking_and_ip_management.sql`, then run `migrations/003_ip_rule_service_alignment.sql`.
- Do not run upgrade migrations on a fresh database created from the complete current `schema.sql`.

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
- Google Ads OAuth and Google Ads API IP exclusion synchronization are not complete.
- Automated tests and production deployment verification still need to be added.
