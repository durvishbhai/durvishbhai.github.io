# Vertex Integrated Solutions — platform

Agency management platform (PHP 8.2+, MySQL/MariaDB, HTML/CSS/JS). **Status: Phase 0 — foundation only.** See `docs/05-MODULES-AND-PLAN.md` for what is and is not built.

> This folder lives inside a GitHub Pages repository only so the existing site (`../index.html`, `../CNAME`) stays untouched. Read `docs/01-INSPECTION.md` (exposure risk) before deploying; a private repository is recommended.

## Requirements

PHP ≥ 8.2 with `pdo_mysql`, `mbstring`, `openssl`, `json`, `ctype`, `fileinfo`; MySQL 8 / MariaDB 10.6+; Composer (dev tooling only). Later phases add `gd`, `intl`, `zip`, `curl`.

## Local setup

```bash
cd vertex-platform
composer install                       # installs PHPUnit (dev only); the app has no runtime packages
cp .env.example .env                   # then edit: DB_*, APP_URL, APP_ENV=local, APP_DEBUG=true
php bin/console.php key:generate       # paste the APP_KEY line into .env
php bin/console.php doctor             # checks PHP, extensions, folders, config, DB
php bin/console.php migrate
php bin/console.php permissions:sync
php bin/console.php user:create-admin --email=you@example.com --name="Your Name"   # prompts for password
composer serve                         # http://127.0.0.1:8000  (development server only)
```

## Tests

```bash
cp .env.testing.example .env.testing   # point at a THROWAWAY database named *_test or *_phpunit
composer lint                          # php -l on every file
composer test                          # PHPUnit: unit + integration
```

Integration tests drop every table in the configured test database and refuse any other database name.

## Console

`php bin/console.php help` — `doctor`, `key:generate`, `migrate`, `migrate:status`, `permissions:sync`, `permissions:matrix`, `user:create-admin`, `audit:verify`.

## Documentation

| Doc | Content |
|---|---|
| `docs/01-INSPECTION.md` | Repository inspection, risks, missing dependencies |
| `docs/02-ARCHITECTURE.md` | Architecture, request lifecycle, conventions, state machines |
| `docs/03-DATABASE.md` | ERD and integrity rules |
| `docs/04-PERMISSIONS.md` | Generated role/permission matrix |
| `docs/05-MODULES-AND-PLAN.md` | Dependency map and phased plan |
| `docs/06-SECURITY-AND-TESTING.md` | Security controls, gaps, test strategy |
| `docs/07-DECISIONS.md` | Unresolved business decisions and integrations |
| `docs/08-DEPLOYMENT.md` | Hostinger/cPanel deployment |

## Conventions

- All SQL through PDO prepared statements, inside Repositories/Services.
- Every template output through `e()`; no inline `<script>`/`<style>` (strict CSP).
- Every new permission goes in `config/permissions.php`, then `permissions:sync`, then regenerate `docs/04-PERMISSIONS.md`, then extend `PermissionMatrixTest`.
- Every money/state change calls `Audit::record()` in the same transaction.
- Never commit `.env`, keys, or customer data.
