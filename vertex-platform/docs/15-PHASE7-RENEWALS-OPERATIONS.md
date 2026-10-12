# 15 · Phase 7 — Domain & hosting renewals, scheduler, backups, final hardening

Migration `0011_renewals.sql`. Code: `app/Services/{RenewalService,RenewalPricing,RenewalScheduler,BackupService,SystemStatus}.php`, controllers `Renewals/{RenewalController,RenewalPriceController,ClientServiceController}`, `Admin/SystemController`, views `renewals/*`, `admin/system.php`, config `config/renewals.php`, `config/backup.php`, console commands `schedule:run`, `schedule:status`, `backup:*`, and the production checks added to `doctor`.

## 0. What this phase does NOT do (read first)
* **There is no quotation or invoice module and no payment gateway.** A renewal's quotation and invoice are **references** that staff type in after issuing the document elsewhere; the system checks only that the invoice amount equals the approved price. Payments are recorded by staff (with a unique transaction reference); nothing is charged online. A client sees "Online payment is not available yet" on an awaiting-payment renewal.
* **There is no registrar or hosting-provider integration.** "Renewed" means a person recorded the provider's confirmation. Nothing is renewed with a provider by this system.
* **Nothing runs by itself until cron is configured** (see §3). The admin screens say so until the scheduled task has actually run.
* **No provider password, EPP/authorisation code or API key is stored.** There is no column for one, and text that looks like a credential is refused in every note field.

## 1. The renewal workflow

A **service** (domain / hosting / SSL / other: type, name, provider, activation date, expiry date, renewal period, notes, client notes) goes through one **cycle** per renewal. Each cycle keeps its own approved price, invoice references, payments and provider confirmation, so finished cycles are never rewritten.

| Status | How it is entered | Who |
|---|---|---|
| Active / Renewed | created; or after provider confirmation | Manager+ / `renewals.confirm_provider` |
| Renewal Approaching | scheduler at `RENEWAL_APPROACH_DAYS` (default 30) before expiry, or by hand | scheduler / `renewals.manage` |
| Awaiting Admin Review | scheduler at `RENEWAL_REVIEW_DAYS` (default 10) before expiry, or by hand; also after a price revision | scheduler / `renewals.manage` |
| Approved for Renewal | Admin approves a price: price list, typed override (reason) or no charge (reason) | `renewals.approve` + password |
| Invoice Issued | staff record the invoice (and optional quotation) number and amount; **amount must equal the approved total** | `renewals.manage` |
| Awaiting Payment | invoice marked sent (the client is notified in the portal), or a part payment arrives | `renewals.manage` |
| Payment Received | recorded payments cover the invoice (or the charge was waived) | `renewals.manage` |
| Renewal in Progress | staff start the work with the provider | `renewals.manage` |
| **Renewed** | **only** by recording the provider's confirmation reference, confirmation date and the new expiry date the provider shows | `renewals.confirm_provider` + password |
| Expired | scheduler, when the expiry date passed and the service was not yet paid (paid or in-progress services are flagged *overdue* instead) | scheduler |
| On Hold | with a reason; release returns it to where it was | `renewals.manage` (reject: `renewals.approve`) |
| Cancelled | with a reason; refused once money was recorded | `renewals.approve` + password |

Guarantees pinned by `RenewalServiceTest` / `RenewalHttpTest` / `FullPlatformJourneyTest`: **payment never renews** (after full payment the expiry date is unchanged and `last_renewed_at` is empty); confirmation is refused before the provider work was started, from a user without the permission, with a future date, or with a new expiry that is not later; the next cycle is scheduled from the provider's verified expiry date; a service is never silently renewed twice; the same transaction reference cannot be recorded twice; overpayment is refused; an issued invoice is never edited (to change the price an approver voids it and the renewal returns to Admin review; refused once money was recorded); the price list can change without touching an approved renewal (snapshot); a hold or cancellation needs a reason; every action is in `renewal_history` and the audit log.

**Renewal pricing** (`/renewals/prices`, `renewals.prices`, password): per type and period, `net = base + markup − discount`, `tax = floor(net × tax% )`, effective from a date; append-only (a new row with a later date supersedes). Until a price exists an approver must enter one with a reason.

## 2. Reminders and alerts
Staff (in the portal): expiry countdown to everyone with `renewals.manage`, review needed / approval pending / expired to approvers, invoice still to be recorded (5 days before expiry), payment received, **provider confirmation outstanding** (paid but unconfirmed 3 days before expiry). Clients (in the portal): an expiry reminder at 30 / 10 / 3 / 1 days and on the day, while the service is Active…Awaiting Payment or Expired and *client reminders* is on for the service; the wording is generic and never contains a price that was not approved. **Email to clients is off** unless `RENEWAL_CLIENT_EMAIL=true` **and** SMTP works; each attempt is recorded in `renewal_reminders` (sent / failed, retried up to 3 times / not configured). Duplicates are impossible: every reminder slot is claimed under a UNIQUE key before anything is sent, and only the **smallest offset reached and not yet sent** fires, so a missed cron day never produces a burst of old reminders. Held and cancelled services are never reminded. WhatsApp is **not implemented** (no approved integration or credentials).

## 3. The scheduled task (cron)
`php bin/console.php schedule:run` is idempotent and safe to run repeatedly; an advisory lock stops overlapping runs. It (1) moves services along the calendar, (2) sends reminders, (3) releases commission hold periods. Every run is recorded in `scheduler_runs`; `schedule:status`, the `/renewals` dashboard, `/admin/system` and `doctor` show the last run and warn if it is older than 36 hours or has never run. **Until cron is configured and has run, none of the automatic behaviour exists, and the screens say so.**

Hostinger / cPanel → *Cron Jobs* (PHP path and folder differ per host; find them with `which php` / the File Manager):
```
15 2 * * *  /usr/bin/php /home/USER/vertex/bin/console.php schedule:run   >> /home/USER/vertex/storage/logs/cron.log 2>&1
40 2 * * *  /usr/bin/php /home/USER/vertex/bin/console.php backup:create --keep=14 >> /home/USER/vertex/storage/logs/cron.log 2>&1
```
After adding them, run both once by hand over SSH or "Run now", then check `schedule:status`, `backup:status` and the *System status* page. **A cron line that has never been seen to run is not a working backup.**

## 4. Backups and restore
`backup:create` writes one zip (`vis-backup-YYYYMMDD-HHMMSS.zip`) to `BACKUP_DIR` (default `storage/backups`, denied by `storage/.htaccess`, outside the web root in the preferred layout) containing a consistent snapshot of every table (single `REPEATABLE READ` transaction, one statement per line), the uploads store, and a manifest (migrations, row count per table, SHA-256 of every file). It verifies itself immediately and prunes to `--keep` files. It needs only PHP (`zip` extension): no `mysqldump`, `tar` or shell. **`.env` is deliberately not inside** (it holds the database password and `APP_KEY`): store `APP_KEY` with the backup set in a password manager, or encrypted payout details become unreadable. Backups are **not encrypted** by this tool; encrypt before copying off the server (for example `gpg -c`), and copy them off the server: a backup only on the same machine is not a backup.

`backup:verify --file=…` re-checks every checksum. `backup:restore --file=… --database=NAME [--uploads-dir=PATH]` restores into an **empty** database (it refuses a database that already has tables, and a non-empty uploads folder, and refuses damaged archives or unsafe paths), then compares row counts with the manifest and re-verifies the audit hash chain of the restored data.

Disaster-recovery procedure: create an empty database → set it in `.env` (`DB_DATABASE`) → upload the code → `backup:restore` → `migrate:status` (all *applied*) → `audit:verify` → point the web root at the app → sign in.

**Verified** (`BackupServiceTest`, `FullPlatformJourneyTest`, and a manual run on the development database): backup of 76 tables / 1,077 rows / 5 uploads → verify → restore into an empty database with identical row counts, byte-identical quotes, backslashes, newlines, semicolons and emoji, uploads restored with matching checksums, audit chain intact (161 rows), commissions/payouts/payments/renewals identical, encrypted payout details readable again with the same `APP_KEY`; a tampered upload, an edited dump, a truncated file and a random file all fail verification and nothing is restored; a forged audit row is reported as BROKEN after restore. **Not verified:** a restore on the real host, off-site storage, encryption, scheduled runs, restore time at production data size, MySQL 8 specifics (tested on MariaDB 10.11).

## 5. System status page
`/admin/system` (`settings.view`) reports environment, debug, HTTPS, PHP/DB versions, migrations, **scheduler last run, newest backup**, email driver, malware scanner, upload folder, and states that quotations/invoices/online payments are not built. Warnings are never shown as OK and no secret is printed (tested).

## 6. Notification review
Channels: **in-app** (all events, de-duplicated by `(user, dedupe key)`), **email** for account flows (invitation, verification, reset), referral status emails (preview + explicit confirmation + hash, never automatic) and optional renewal reminders (§2). Delivery is never claimed unless the mail driver accepted the message; failures are recorded (`referral_email_log`, `renewal_reminders`). Events (in-app): change_request.{awaiting_approval, client_decision}; client_payment.needs_verification; commission.{milestone, review_needed}; deliverable.{published, review_required, review_decision, client_response, revised_version_published}; partner.application_{received, approved, rejected}; payout.{requested, ready_to_pay}; referral.{received, received_duplicate, status_changed, converted, enquiry_converted}; renewal.{expiry, expired, approval_pending, review_needed, approved, invoice, invoice_due, payment_received, ready_for_provider, confirmation_outstanding, client_reminder, completed}; revision.{received, assigned, update, client_update, potentially_chargeable}; ticket.{assigned, client_reply, staff_reply, escalated, reopened, eligibility, status_*}. Not built: WhatsApp (needs an approved integration and credentials), SMS, push, a general "email me my notifications" preference.

## 7. Security audit (Phase 7)
Re-run on the final code: full automated suite; `RouteSweepTest` (every route: anonymous redirect, CSRF on all state-changing routes, per-role 403, hostile query strings, no 5xx); new `StaticSecurityScanTest` (no `eval/exec/unserialize/md5/sha1/rand`… outside a 3-entry reviewed allow-list, no superglobal reads outside the request layer, nothing executable in `public/` except `index.php`, no committed secrets or `.env`, every route handler exists); `composer audit` (**no advisories** on 2026-10-12); live probe of a production-mode server: security headers present (CSP without inline script, HSTS, `X-Frame-Options: DENY`, `nosniff`, referrer and permissions policies, `no-store`), session cookie `HttpOnly; SameSite=Lax` and `Secure` when HTTPS is configured (`SESSION_FORCE_SECURE=true` behind a TLS-terminating proxy), unknown paths and a CSRF failure show no stack trace or file path, and `.env`, `config/`, `app/`, `vendor/`, `database/`, `bin/`, `tests/`, `docs/`, `storage/*` all answer 404 when requested over HTTP.

New attack surface and how it was checked: renewals (IDOR across clients, client calling staff endpoints, step-up password, escaping, CSV formula injection neutralised, credential-looking text refused), backup/restore (CLI only, no web route; path traversal in the manifest refused; never overwrites a non-empty database), scheduler (CLI only; no input).

**Findings, none critical:** (1) No two-factor authentication: recommended for Owner/Administrator before launch (deferred). (2) Uploads are not malware-scanned unless a scanner command is configured (`UPLOAD_SCANNER`). (3) Backups are unencrypted at rest (operator responsibility, §4). (4) The audit log records `REMOTE_ADDR`, which behind a proxy is the proxy's address. (5) No third-party penetration test was performed. (6) The payment-gateway callbacks and webhook signature handling **do not exist**, so they could not be audited; they must be audited when built. **No known critical vulnerability is unresolved.** Production is blocked by missing business inputs and unbuilt features (see `16-GO-LIVE-AND-HANDOVER.md`), not by a known exploitable flaw.

## 8. Performance review
Measured on the development container (MariaDB 10.11) with 20,000 synthetic renewal services: list page 1 ≈ 90 ms, page 2000 ≈ 130 ms, filtered/search ≈ 70–110 ms, dashboard (10 counts) ≈ 20–35 ms; all list queries use indexes (`EXPLAIN`). The scheduler's first run over 20,000 services (3,000 stage moves, 13,000 reminders) took about 60 s and an idempotent rerun about 6 s after it was changed to load only candidate rows; real volumes (hundreds of services) take well under a second. Notification unread counts are index-only. Not measured: concurrent load, large uploads, a real shared host.

## 9. Manual smoke test (development or staging, never production data)
1. `php bin/console.php migrate && php bin/console.php permissions:sync`; `schedule:status` says NEVER RUN; `/admin/system` shows the same warning.
2. Renewal price: *Domains & hosting → Price list* → add a domain/12-month price (password).
3. *Add a service* for a client: expiry 8 days ahead. Run `schedule:run`: it becomes **Awaiting Admin Review**; Administrators get a notification; the client gets an expiry reminder. Run `schedule:run` again: nothing new.
4. As Administrator: **Approve** (list price) with your password. As Manager: record invoice (wrong amount is refused, correct amount accepted) → mark sent (client notified) → record a part payment, a duplicate of the same reference (refused), the rest → *Payment Received* (expiry unchanged).
5. Start the provider renewal → as a Manager try to confirm (refused) → as Administrator confirm with reference, date and new expiry + password → **Renewed**, next expiry set; the client sees "Renewed" and no confirmation reference.
6. As the client: *Domains & hosting* shows only your own services; another client's URL is 404.
7. `backup:create`, `backup:verify`, create an empty database, `backup:restore --database=… --uploads-dir=…`: row counts MATCH and the audit chain is intact.
8. `doctor` lists the production warnings you still have to clear.
