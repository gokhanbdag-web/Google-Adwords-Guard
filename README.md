# AdGuard Pro

Native PHP 8.2+ and MySQL 8+ SaaS application for website traffic analysis and suspicious-visit review.

## Requirements
- PHP 8.2+
- MySQL 8.0+
- Composer
- PHP extensions: PDO MySQL, JSON, OpenSSL, cURL

## Installation
1. Create a database and import `database/schema.sql` on a new/empty database only.
2. Copy `.env.example` to `.env` and set database credentials, a unique APP_KEY (at least 32 characters), and APP_URL.
3. Run `composer install --no-dev --optimize-autoloader`.
4. Import `database/seeders.sql`.
5. Configure the web server document root to the `public/` directory.
6. Create an administrator account by registering normally, then promote its role to admin directly in the database.

## Tracking
Add the generated script from the Sites page to the registered website. Tracking requests are accepted at `/track`. A website must be active and its owner must have an active subscription.

## Migrations
Apply each file in `database/migrations` once, in numeric order, after backing up the database. The base schema is for fresh installations; do not rerun it against an existing production database.

## Security
Use HTTPS, keep `.env` outside the public document root, use a strong random APP_KEY, and never expose database credentials or OAuth tokens.
