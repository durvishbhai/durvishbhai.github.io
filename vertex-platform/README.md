# Vertex Integrated Solutions — platform

Agency management platform (PHP 8.2+, MySQL/MariaDB, HTML/CSS/JS). **Status: Phases 0–12 built.** All 37 modules of the requirements document are implemented except the items listed under "Not built" in `docs/19-REQUIREMENTS-TRACEABILITY.md`. **NOT yet proven in production:** PhonePe and AI assistance were tested only against simulated services, SMTP only against a local fake server, and nothing has run on the real Hostinger host. Read `docs/16-GO-LIVE-AND-HANDOVER.md` first, then `docs/18-PHASE12-CMS-AI-PHONEPE.md` (PhonePe sandbox checklist). Earlier phases: referral partners and commissions `docs/14`; renewals, scheduler, backups `docs/15`; quotations, invoices and Google Pay by UPI `docs/17`; support/warranty `docs/12`; deliverables/revisions `docs/11`; CRM/projects `docs/10`.

> This folder lives inside a GitHub Pages repository only so the existing site (`../index.html`, `../CNAME`) stays untouched. Read `docs/01-INSPECTION.md` (exposure risk) before deploying; a private repository is recommended.

## Requirements

PHP ≥ 8.2 with `pdo_mysql`, `mbstring`, `openssl`, `json`, `ctype`, `fileinfo`; MySQL 8 / MariaDB 10.6+; Composer (PHPMailer is a runtime dependency since Phase 1: upload `vendor/` if the host has no Composer; PHPUnit is dev only). Later phases add `gd`, `intl`, `zip`, `curl`.

## Local setup

```bash
cd vertex-platform
composer install                       # PHPMailer (runtime) + PHPUnit (dev)
cp .env.example .env                   # then edit: DB_*, APP_URL, APP_ENV=local, APP_DEBUG=true
php bin/console.php key:generate       # paste the APP_KEY line into .env
php bin/console.php doctor             # checks PHP, extensions, folders, config, DB
php bin/console.php migrate
php bin/console.php permissions:sync
php bin/console.php user:create-super-admin --email=you@example.com --name="Your Name"   # hidden password prompt
php bin/console.php db:seed-dev        # optional, APP_ENV=local only: fictional users/projects (prints a one-time password)
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

`php bin/console.php help` — `doctor`, `key:generate`, `migrate`, `migrate:status`, `permissions:sync [--reset-roles]`, `permissions:matrix`, `user:create-super-admin`, `user:set-password`, `db:seed-dev`, `audit:verify`.

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
| `docs/09-PHASE1-ACCOUNTS-RBAC.md` | Phase 1: migrations, env vars, first Super Admin, rollback, test results, gaps |
| `docs/11-PHASE3-DELIVERABLES-REVISIONS.md` | Phase 3: file storage and configuration, security controls, workflows, manual test checklist, limitations |
| `docs/19-REQUIREMENTS-TRACEABILITY.md` | Every requirement module and role mapped to the code, with an honest status and the list of what is not built |
| `docs/18-PHASE12-CMS-AI-PHONEPE.md` | Phase 12: website CMS and SEO, Integration Center, AI assistance, PhonePe payments, sandbox checklist |
| `docs/17-BILLING-AND-GOOGLE-PAY.md` | Phase 8: quotations, invoices without tax, payments and client UPI submissions, Google Pay settings (what works, what does not), manual test script |
| `docs/16-GO-LIVE-AND-HANDOVER.md` | Is it production-ready? (staged go-live; see gates), completion matrix, go-live gates and checklist, handover package, operations runbook |
| `docs/15-PHASE7-RENEWALS-OPERATIONS.md` | Phase 7: renewals workflow, reminders, scheduler/cron, backup and restore, system status, security and performance review, smoke test |
| `docs/14-PHASE6-REFERRAL-COMMISSIONS.md` | Phase 6: referral partner applications, referrals and attribution, commission formula and eligibility rules, payouts, permissions, manual test script, limitations |
| `docs/13-FINAL-AUDIT.md` | Final audit: what exists and what does not, test results, defects fixed, security review, completion matrix, prioritized issues, manual test script |
| `docs/12-PHASE5-SUPPORT-WARRANTY.md` | Phase 5: bug reports, warranty dates and eligibility rules, support tickets, permission controls, dependencies, manual test checklist |
| `docs/10-PHASE2-CRM-PROJECTS-TASKS.md` | Phase 2: features, pricing protection, migrations, upgrade steps, manual test checklist, known limitations |

## Conventions

- All SQL through PDO prepared statements, inside Repositories/Services.
- Every template output through `e()`; no inline `<script>`/`<style>` (strict CSP).
- Every new permission goes in `config/permissions.php`, then `permissions:sync`, then regenerate `docs/04-PERMISSIONS.md`, then extend `PermissionMatrixTest`.
- Every money/state change calls `Audit::record()` in the same transaction.
- Never commit `.env`, keys, or customer data.
