# 09 · Phase 1 — database, authentication and RBAC

## Database migrations (forward-only, checksummed)

| # | File | What it does | Data impact |
|---|---|---|---|
| 0001 | `0001_foundation.sql` | roles, permissions, role_permissions, users, user_roles, login_attempts, audit_logs | (Phase 0) |
| 0002 | `0002_accounts_security.sql` | `users`: status enum `pending/active/deactivated/archived`, `session_version`, `timezone`, `deactivated_at/by/reason`; new `auth_tokens` (hashed single-use tokens), `rate_limits` | Existing users kept. Legacy status `suspended` → `deactivated` (two-step enum change so no row is ever invalid). Existing sessions stay valid (`session_version` defaults to 1). |
| 0003 | `0003_roles_hierarchy.sql` | `roles.level`, `roles.is_locked`, `permissions.is_sensitive`; legacy role mapping | Phase 0 `admin` → **`super_admin`** (same privileges, it held every permission). Phase 0 `staff` → merged into **`manager`** (users keep access). Users, passwords, audit rows untouched. |
| 0004 | `0004_project_access.sql` | `clients`, `projects`, `project_assignments`, `referrals` (all minimal, access model only) | New tables only |

Constraints: 16 foreign keys; unique keys on `users.email`, `users.public_id`, `clients.user_id`, `(project_id,user_id)` assignments, `auth_tokens.token_hash`. All timestamps are stored in UTC (the connection forces `time_zone='+00:00'`); display conversion uses `local_time()` with the user's time zone (falling back to `APP_TIMEZONE`). Multi-step operations run in `Database::transaction()`; every audit record is written inside the same transaction as the change it describes.

**Verified:** `MigrationUpgradeTest` builds a genuine Phase 0 database (migration 0001 + Phase 0 style roles, users incl. a `suspended` one, audit rows), applies 0002–0004 and asserts: same user ids and password hashes, admin→super_admin, staff→manager, suspended→deactivated, legacy roles gone, audit chain still verifies, re-run is a no-op, editing an applied file is detected as drift and blocks migrating. The same upgrade was also run by hand on the Phase 0 sandbox database (16 audit rows verified before and after).

### Commands
```bash
php bin/console.php migrate:status     # applied | pending | drifted | missing
php bin/console.php migrate            # applies pending files in order, records checksum + batch
php bin/console.php permissions:sync   # run after every migrate/deploy; keeps UI edits
```

### Rollback — what is and is not safe
There are **no automatic down migrations**: this system will hold financial records.
1. **Always take a backup first** (`mysqldump --single-transaction --routines <db> > backup.sql`, or cPanel *Backup*).
2. To roll back, restore the backup and redeploy the previous code. That is the only fully safe path.
3. 0004 only adds empty tables in Phase 1: if it was applied by mistake and no project data exists, `DROP TABLE referrals, project_assignments, projects, clients;` then `DELETE FROM schema_migrations WHERE migration='0004_project_access.sql';` is safe. Do **not** do this once any row exists.
4. 0002/0003 change existing columns and merge roles; they are **not** reversible by script (information about legacy `suspended`/`staff` is lost by design). Restore from backup.
5. Never edit an applied migration; add a new numbered file.

### Seed data (development only)
`php bin/console.php db:seed-dev` — 11 **fictional** users on the reserved `@example.test` domain (one per role, two clients), 3 projects, assignments made through the real service, 1 referral. Refuses to run unless `APP_ENV` is `local`/`testing`. The shared password is random per run (or `SEED_PASSWORD`), printed once, never stored in the repo. Idempotent.

## Environment variables

Copy `.env.example` to `.env` (never commit it). Real server variables win over the file.

| Variable | Purpose |
|---|---|
| `APP_ENV`, `APP_DEBUG`, `APP_URL`, `APP_TIMEZONE`, `APP_KEY` | `production`/`false` in production. `APP_URL` is the public https URL — **all emailed links are built from it**, never from request headers; without it email features are disabled. `APP_KEY` from `key:generate` (signs session binding). |
| `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | MySQL/MariaDB connection (PDO, utf8mb4, strict mode, UTC). |
| `SESSION_IDLE_MINUTES` (60), `SESSION_ABSOLUTE_MINUTES` (720), `SESSION_FORCE_SECURE`, `SESSION_IDLE_SECONDS` (advanced override) | Server-side session limits; cookie is HttpOnly + SameSite=Lax (+ Secure on HTTPS). |
| `LOGIN_MAX_ATTEMPTS` (5), `LOGIN_LOCKOUT_MINUTES` (15) | Login throttle. Other limits are in `config/auth.php`. |
| `REGISTRATION_ENABLED` (false) / `REGISTRATION_MODE` | Public client self-registration. Normally chosen by the Owner in *Integrations & settings → Feature switches → Client self-registration*: **Off**, **On with email confirmation** (needs working email) or **On with team approval** (works without email: the new account waits as *pending* until a person with `users.edit` presses *Approve this registration* on the user's page). `REGISTRATION_ENABLED=true` alone still means "email confirmation"; `REGISTRATION_MODE` (off / email / approval) is the .env equivalent of the screen. |
| `MAIL_DRIVER` (`none`\|`smtp`\|`log`), `MAIL_HOST/PORT/ENCRYPTION/USERNAME/PASSWORD/FROM_ADDRESS/FROM_NAME` | `none`: registration, verification, password reset and invitations are disabled and the UI says so. `smtp`: PHPMailer. `log`: dev only, refused in production. |
| `TRUSTED_PROXIES` | Only if behind a proxy whose `X-Forwarded-For` you trust. |

## Creating the first Super Admin (safe procedure)
1. Configure `.env`, then `php bin/console.php migrate` and `php bin/console.php permissions:sync`.
2. `php bin/console.php user:create-super-admin --email=owner@yourdomain --name="Full Name"`
3. Enter the password at the **hidden prompt** (min 12 chars; common passwords and passwords containing your email name are rejected). It is never a command-line argument, so it does not reach shell history or process lists.
4. The command refuses to run if a Super Admin already exists (`--additional` overrides, intentionally awkward). It is audited.
5. Sign in, create Administrators from *Users → Create user* (the Owner role is console-only), and use *My account* to set your time zone. Everyone else is invited by email (or `user:set-password` if email is not configured).
Upgrading from Phase 0: your existing admin was automatically mapped to Super Admin by migration 0003.

## What was built
- **Accounts:** register (client-only), verify email (GET shows a button; only POST consumes the token), login, logout, forgot/reset password, admin invitations (person sets their own password), self-service profile (name/phone/time zone only) and password change (signs out other sessions), deactivate/reactivate/archive with reason, immediate access revocation (`session_version` + per-request status check).
- **RBAC:** 10 roles, 86 permissions in 12 modules (22 flagged sensitive) separating view/create/edit/archive/approve/publish/assign/manage-users/edit-content/view-finance/record-payments/change-settings; DB-backed and editable in the UI; levels, assignable-role and sensitive-permission rules (see `04-PERMISSIONS.md`); step-up password confirmation on role/permission/lifecycle changes (server-enforced; the browser dialog is only a convenience).
- **Project access foundation:** `ProjectPolicy` + `project` route middleware (404 for missing and forbidden alike, random public ids, active-account check), Admin-only assignment service, minimal project list/detail page to exercise it. Project CRUD, milestones, files are Phase 2 and must reuse the middleware.
- **Audit:** `Audit::record()` for auth events, account lifecycle, role/permission changes, project assignments, blocked escalation attempts, integrity checks; secrets redacted; admin viewer with search/filter/pagination; no code path updates or deletes audit rows (a test scans the source).
- **Admin UI:** users (list/search/filter/paginate, create, edit, roles, status actions), roles & permissions editor, audit log.

## Test results (actually executed, this sandbox: PHP 8.3.6, MariaDB 10.11.14)
- `php -l`: 115 files, 0 errors. `composer audit`: no advisories.
- PHPUnit: **207 tests, 1365 assertions, all passing** (unit 73 · integration 134), including 2×–3× repeats of the timing-sensitive test.
- Real-browser run (Chromium): admin UI flows, JS confirm dialog, live-session revocation, manager 403, client isolation; 0 CSP violations.
- **Mutation checks** (rule deliberately broken → suite must fail): 13 rules checked; 11 caught immediately, 1 equivalent mutant (redundant self-check, the hierarchy check covers it), 1 gap found and closed by new tests (atomic single-use token consume).

## Not tested / outstanding
- Real SMTP provider (TLS/STARTTLS, auth, deliverability/SPF/DKIM): only a local fake SMTP server without TLS was used. Needs credentials (D-03).
- Hostinger/LiteSpeed behaviour (`.htaccess`, PHP selector, file permissions, cron).
- MySQL 8 (tested on MariaDB 10.11 only; SQL is standard but unverified there).
- Concurrency races beyond the atomic token consume (e.g. two admins editing the same role simultaneously — last write wins, both audited).
- 2FA, account lockout notification emails, session list/"sign out everywhere" UI, CAPTCHA, breached-password (HIBP) check, GDPR/DPDP export & delete — not built.
- Referral partner and finance row-level policies exist only as permission design plus the `referrals` ownership relation; their modules (and policies) arrive in later phases.
- Phone numbers are format-checked, not verified (no SMS).

## Existing functionality and the invoice module
- **No invoice module, invoice table or invoice data exists in this repository** (Phase 0 inspection, still true; D-01 remains open). Therefore there was nothing to preserve or break, and none was invented. When the location of the existing invoice page is provided it will be integrated in Phase 4.
- `git diff` against `main` shows **no change outside `vertex-platform/`**: `index.html`, `CNAME`, `README.md` are untouched.
- Existing Phase 0 *data* (users, audit history) was preserved by the migration path described above and verified by test.
