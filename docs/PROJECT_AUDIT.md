# AdGuard Pro — Initial Repository Audit

Audit date: 2026-11-10
Branch: development/adguard-pro

## Executive summary

The repository contains useful PHP domain logic and an initial MySQL schema, but the application is not currently deployable as a coherent project. The source files are stored flat in the repository root while Composer autoloading and controller view paths expect a directory-based application. The current root index.php is a dashboard template rather than an HTTP front controller.

No existing application files have been deleted or overwritten during this audit. The default main branch remains unchanged.

## Confirmed structural blockers

1. **No working front controller:** .htaccess rewrites requests to /index.php, but index.php contains dashboard HTML and does not load Composer, environment configuration, routes, or controllers.
2. **Composer paths do not exist:** composer.json maps App\\ to app/ and loads app/Helpers/functions.php, while the tracked PHP files are in the repository root.
3. **Missing expected configuration paths:** Database.php loads config/database.php, but database.php is currently at the root. app.php and google.php are also root-level configuration files.
4. **Missing view tree:** controllers require resources/views/... files, but the tracked view templates are flat root files.
5. **Controller namespace mismatch:** web.php imports App\\Controllers\\DashboardController for the customer dashboard, while the tracked DashboardController.php declares App\\Controllers\\Admin\\DashboardController.
6. **IP service/schema mismatch:** IpManagementService queries and writes ip_rules columns user_id, risk_score, evidence_count, and expires_at, and permits website_id IS NULL. The current schema defines none of those columns and makes website_id NOT NULL.
7. **Subscription schema mismatch:** QuotaService reads subscriptions.current_period_end, but schema.sql defines ends_at.
8. **Tracking is incomplete:** TrackingController stores every accepted visit with risk_score=0 and risk_level='low'; it does not invoke BotDetectionService, RiskScoringService, QuotaService, or IpManagementService. No tracker.js asset is present in the tracked root files, although the sites template emits a tracker.js URL.
9. **Google Ads integration:** OAuth multi-account linking, encrypted token storage/refresh, campaign listing, campaign IP-exclusion validation, opt-in live mutation, and operation audit logging have now been added. A real Google Ads account/API access test is still required.
10. **Potential destructive database setup:** schema.sql drops existing tables before recreating them. It must be clearly treated as a fresh-install script and must not be run against an existing production database.

## Existing useful components

- PDO database wrapper and transaction helper
- Environment loader
- CSRF token helper and authenticated session helpers
- Authentication controller with password hashing
- Site registration controller
- Bot signal detection and risk scoring services
- Quota service
- IP rule service with allowlist precedence and pending automatic candidates
- Tracking endpoint with input-size limits, UUID validation, URL validation, origin checks, and duplicate-event protection

## Required repair sequence

1. Establish the application directory structure and a real front controller without losing existing source.
2. Reconcile the SQL schema and migrations with every service query; provide non-destructive upgrades for existing installations.
3. Wire tracking to quota, bot detection, risk scoring, and pending-candidate creation. Keep automatic blocking and Google Ads write operations disabled by default.
4. Implement missing controllers, views, assets, and route coverage.
5. Validate Google OAuth and Google Ads account/IP-exclusion workflows against a real test account; add removal/rollback and reconcile API errors/access levels.
6. Add admin/customer workflows, validation, security headers, logging, and deployment documentation.
7. Run PHP syntax checks and consistency checks; document any tests that require a real MySQL/Google Ads environment.

## Safety decisions

- Do not automatically block IP addresses based on a single signal.
- Do not send write operations to Google Ads unless the account owner explicitly enables them and confirms the action.
- Preserve the existing main branch; work on development/adguard-pro until the implementation is reviewed.
