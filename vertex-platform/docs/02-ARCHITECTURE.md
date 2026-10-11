# 02 · Application architecture

## Shape

A **modular monolith** in plain PHP 8.2+ (no framework), server-rendered, with a small purpose-built core. Rationale: it must run on Hostinger/cPanel shared hosting, be auditable line by line (it handles money), and avoid dependency churn. The core mirrors common framework concepts (front controller, router, middleware, services, repositories, views) so a later migration to Laravel/Symfony is mechanical if desired.

```
vertex-platform/
  public/            only web-exposed directory (front controller, assets, .htaccess)
  app/
    Core/            framework-level: Env, Config, Database, Router, Request, Response, Session, Csrf,
                     Validator, View, ErrorHandler, Logger, SecurityHeadersPolicy, helpers
    Http/
      Kernel.php     global pipeline + error → response
      Middleware/    one class per cross-cutting concern
      Controllers/   thin: validate → call service → render/redirect
    Services/        business logic (Auth, Gate, Audit, Migrator, PermissionSync ...). Later: Billing, Payouts, ...
    Repositories/    all SQL lives here (prepared statements only)
    Views/           plain PHP templates; everything echoed through e()
    routes.php       route table
  config/            app, database, session, permissions (roles/permissions source of truth)
  database/migrations/   forward-only SQL migrations
  bin/console.php    ops commands (migrate, permissions:sync, doctor, audit:verify, ...)
  storage/           logs, sessions, cache, uploads (outside web root in correct setups; never directly served)
  tests/             PHPUnit (Unit = no DB, Integration = throwaway DB)
  docs/
```

## Request lifecycle

```
Request::capture()
  → Router matches route (404/405 raised as HttpException)
  → global middleware: AddSecurityHeaders → StartSession → VerifyCsrfToken
  → route middleware: auth | guest | can:<permission>[,<permission>]
  → Controller (validate → Service → Response)
  → Response::send()
Any Throwable → ErrorHandler: logged with a reference id; users see a generic page (details only if APP_DEBUG)
```

## Cross-cutting decisions

| Concern | Decision |
|---|---|
| Config | `.env` (gitignored) + real env vars (win over file). `config/*.php` reads via `Env::get`. No credentials in code. |
| Database | PDO, `ERRMODE_EXCEPTION`, `EMULATE_PREPARES=false` (true prepared statements), utf8mb4, strict `sql_mode`, **all timestamps UTC**. |
| Money | **Integer minor units (paise) in BIGINT + ISO currency code.** Never FLOAT/DECIMAL arithmetic in PHP. Percentages stored as basis points. Rounding rule defined once in a `Money` service (Phase 4). |
| IDs | Internal auto-increment `id`; externally exposed identifiers are random `public_id` (no sequential IDs in URLs → no enumeration). Authorization is still checked on every access; a random ID is not a security control. |
| Migrations | Forward-only SQL files, checksummed; drift (edited applied file) blocks migrating. No `down`: money data is restored from backup, not rolled back by script. |
| Authorization | Two layers: (1) **permission** (`Gate::can('payouts.approve')`, route middleware `can:`) from `config/permissions.php`; (2) **row-level policies** per module ("is this user assigned to *this* project / owner of *this* invoice") from Phase 2. Both are required for scoped permissions. No super-user bypass; Admin holds permissions explicitly. |
| Audit | `Audit::record()` in the same DB transaction as the change; append-only hash chain verifiable with `audit:verify`. |
| Errors | `ErrorHandler` converts warnings to exceptions, logs to `storage/logs`, renders reference-numbered pages. |
| Templates | No inline `<script>`/`<style>` → strict CSP (`script-src 'self'; style-src 'self'`). |
| Files (Phase 2) | Stored outside web root; served only through an authorized controller that checks project membership; random storage names; MIME sniffing + extension allow-list; size limits. |
| Emails | Through an `email_outbox` table + sender service (Phase 6). Referral approval/rejection emails are queued only by an explicit Admin action that includes a confirmation step (business rule). |
| Background work | cPanel cron calling `php bin/console.php <job>` (renewal reminders, outbox flush, backups). No daemon required. |

## State machines (to be implemented with explicit transition tables, tested per transition)

- **Referral application:** `RECEIVED → APPROVED | REJECTED` (reason mandatory on REJECTED). Final; email only after explicit Admin confirm.
- **Milestone:** `PROPOSED → APPROVED → IN_PROGRESS → SUBMITTED → ACCEPTED` (+ `REJECTED`). Workers may create/propose per role permission; approval needs `milestones.approve`.
- **Invoice:** `DRAFT → ISSUED → PARTIALLY_PAID → PAID` (+ `VOID`). Renewal invoices cannot enter `ISSUED` without a recorded Admin approval.
- **Payment (client):** `INITIATED → AUTHORIZED/CAPTURED → (REFUNDED)`; a payment becomes `CAPTURED` only from a **signature-verified gateway callback/webhook**, never from the browser redirect alone.
- **Payout request:** `REQUESTED → APPROVED | REJECTED → PAID` (Admin records manual payment details). Separate from client payments.
- **Renewal:** `UPCOMING → ADMIN_APPROVAL_PENDING → APPROVED → INVOICED → PAID → RENEWED`; `RENEWED` requires a provider-confirmation record (reference + who recorded it).

## Phase 1 additions
User/Role/Project services (`UserService`, `RoleService`, `ProjectPolicy`, `ProjectAssignmentService`), `TokenService`, `RateLimiter`, `Mailer` (PHPMailer), controller base with step-up confirmation, `project`/`can`/`auth`/`guest`/`nosession` route middleware, lazy sessions for anonymous visitors. See `09-PHASE1-ACCOUNTS-RBAC.md`.

## Phase 0 implementation status

Implemented and tested: config/env, PDO, error handling, router + middleware, sessions, CSRF, validation, views/layout/branding, auth (login/logout/throttle), RBAC (roles, permissions, gate, route guard), audit hash chain, migrations, console, health endpoint.

**Not implemented as of Phase 0 (users, reset, email were delivered in Phase 1):** user management UI, password reset, email, 2FA, CMS, projects, pricing, quotations, invoices, payments, payouts, referrals, chat, warranty, appointments, renewals, legal pages, cookie consent, backups, reports.
