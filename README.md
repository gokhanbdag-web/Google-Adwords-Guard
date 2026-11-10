# AdGuard Pro

PHP 8.2+ and MySQL 8+ SaaS for website traffic analysis, risk scoring, IP review, and Google Ads IP exclusions.

## Shared-hosting installation (no SSH / no Composer)

1. In your hosting control panel, create a MySQL database and database user. Grant the user all privileges on that database.
2. Upload the complete release ZIP into the target subdirectory and extract it. Keep the `app`, `bootstrap`, `config`, `database`, `install`, `public`, `routes`, `storage`, and `vendor` directories together.
3. Visit the subdirectory URL in a browser. The installer checks PHP 8.2+, required extensions and write permissions.
4. Enter the database host/name/user/password, site URL, and first administrator details.
5. The installer creates the schema, imports starter content, applies migrations, generates a unique application key, writes the protected `.env` file, and locks the installer.
6. Sign in at the URL shown after installation.

No SSH, terminal, Composer command, phpMyAdmin SQL import, or manual administrator promotion is required. Use a fresh, empty database. Do not upload only the `public` directory: the application source directories must remain alongside it. The root entry point and rewrite rules support a subdirectory installation on compatible Apache shared hosting.

## Requirements
- PHP 8.2+
- MySQL 8.0+
- PHP extensions: PDO MySQL, JSON, OpenSSL, cURL, mbstring, DOM
- Apache `mod_rewrite` (for clean URLs); hosting must allow `.htaccess` overrides

## Post-install configuration
- Google OAuth requires a configured OAuth client and redirect URI.
- Google Ads live writes are disabled by default. Enable only after testing and explicit confirmation controls.
- Configure PayTR/iyzico credentials in the protected environment file only after sandbox testing.
- Set up cron jobs from the hosting control panel if supported. Without cron, retention pruning and queued background tasks will not run automatically.
- Customize the privacy notice, data-controller identity, legal basis and retention settings before production use. This software does not itself guarantee KVKK/GDPR compliance.

## Security
- The installer is disabled after creating `storage/installed.lock`; never delete that file on a live installation.
- Keep `.env` and all source directories inaccessible from the web. The included root rewrite rules deny direct access to application/config/database/vendor paths.
- Use HTTPS. Use a strong unique administrator password and keep a tested backup.
