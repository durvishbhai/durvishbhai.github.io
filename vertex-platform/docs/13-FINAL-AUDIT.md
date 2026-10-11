# 13 · Final project audit

Scope: the code and schema that exist in this repository on branch `ccr-13b48464-fpb2gp`, inspected and exercised in a development container (PHP 8.3.6, MariaDB 10.11, throwaway databases, Chromium). Nothing was deployed, no live payment was enabled, and no existing record was deleted or replaced.

## 0. The most important finding

**The platform is not complete, and it cannot be declared ready.** The audit request lists quotations, invoices, payments, payment verification, duplicate-webhook handling, referral commissions (10% + 5%), payouts and domain/hosting renewals. **None of these exist in this repository.** They were never built:

* The phase numbering used in this project was: Phase 0 (foundation), 1 (accounts/RBAC/audit), 2 (CRM, projects, tasks, pricing foundation), 3 (deliverables, revisions, file store), 5 (bug reports, warranty, support). A "Phase 4" (quotations/invoices/payments) was never requested or built. The Phase 6 (referral partner portal and commissions) and Phase 7 (renewals, integration, launch) prompts were received but **not started**; I stopped after Phase 5 as that prompt said.
* Evidence: the schema has **no** table for quotations, invoices, payments, webhooks, commissions, payouts or renewals (`SHOW TABLES` lists 53 tables; the only related one is `referrals`, a minimal Phase 1 table). A test now fails if such a table appears without this audit being updated (`EndToEndWorkflowTest`). The only mentions in code are comments and permission keys (`invoices.*`, `payments.*`, `commissions.*`, `payouts.*`, `renewals.*` exist as keys in `config/permissions.php` and gate nothing). `.env.example` has `PAYMENT_*` lines that are commented out and read by no code.

Therefore, for items 3, 4 and 5 of the request I can report only this: **there is nothing to test.** I did not simulate any of it. The "15% = 10% after the qualifying advance payment + 5% after full payment" structure is **not implemented anywhere**, so it is **not verified**. Duplicate webhook handling, payment verification and invoice/payment financial calculations likewise do not exist. The only money handling that exists is integer-paise arithmetic for approved project prices, priority surcharges and chargeable-change proposals (tested, see below).

Building the missing modules is a separate, large piece of work (it needs a payment gateway account, webhook secrets and your commission rules). I have not started it because this audit asked me not to start unrelated features or rebuild the application.

## 1. What was run (actual results)

| Check | Result |
|---|---|
| Full PHPUnit suite, final run | **529 tests, 11,396 assertions, all passing** (7 min 17 s) |
| PHP syntax check of every `.php` file in app, bin, config, database, public, tests | clean |
| `php bin/console.php doctor` | all required checks passed |
| `php bin/console.php migrate:status` | 9 migrations, all applied, no drift |
| `php bin/console.php audit:verify` | chain intact (96 rows) |
| Fresh install | every test class migrates an empty database from migration 0001 to 0009 |
| Upgrade path | `MigrationUpgradeTest` (Phase 0 data to 0009) and `Phase2UpgradeTest` (Phase 1 data to 0009): users, hashes, projects, assignments and the audit chain preserved |
| Backup / restore | `mysqldump` of the dev database restored into a new database: identical row counts; `audit:verify` passed on the copy and detected a single edited row (`docs/08-DEPLOYMENT.md`) |
| Browser smoke test (Phase 5 flow, Chromium, 1366 px and 375 px) | no console errors, no horizontal overflow on 9 pages |
| Mutation checks (Phase 5 guards) | 6 deliberate breakages, all caught by tests |

New automated tests added by this audit:

* **`RouteSweepTest`** (6 tests, 4,256 assertions) is driven by the real route table, so a route added later cannot be forgotten: every route not on an explicit public allow-list requires sign-in (anonymous GET and POST are redirected to `/login`); **no route opts out of CSRF** (all 108 state-changing routes answer 419 without a token, even when signed in); for **each of 9 roles** (owner, administrator, manager, finance, support, developer, freelancer, referral partner, client), every route whose `can:` gate the user does not satisfy answers **403** for GET and for a token-bearing POST (1,300+ route/role combinations, 500+ refusals asserted) and **nothing returns a 5xx**; hostile query strings (SQL fragments, quotes, `UNION SELECT`, NUL, 5,000-character values, huge numbers, script tags) sent on every list page produce no 5xx and no SQL/PHP error text, and leave the `users` table unchanged.
* **`EndToEndWorkflowTest`** drives one customer through every module that exists (enquiry, won, conversion, assignment, status, approved baseline, priced priority change decided by the client, task and time log, deliverable upload, internal QA, publication, client approval, revision, chargeable proposal approved by the client, warranty, bug report with eligibility review, fix and client closure, billing ticket) and proves: the approved project price and price revisions are never changed by approving extra work; a second client sees nothing in any module; even the Owner, who holds every permission key, cannot act *as* the client (approve a cost proposal, answer a deliverable, file a bug); the audit chain verifies and contains the expected actions; no password text is in the audit log; and no invoice/payment/commission/payout/renewal/webhook/quotation table exists.
* `RequestTest::testPageNumbersAreAlwaysBoundedPositiveIntegers`.

## 2. Defects found and fixed during the audit

| # | Defect (reproduced first) | Fix | Verified by |
|---|---|---|---|
| A-1 | **HTTP 500 on every paginated list** when `?page=99999999999999999999` (the SQL OFFSET overflowed to a float, producing a SQL syntax error; a crafted URL could fill the error log and raise alerts). Found by the new hostile-query sweep. | `Request::page()` (positive integer, at most 6 digits) used by every controller; every service also clamps its OFFSET. | `RouteSweepTest::testHostileQueryStrings…`, `RequestTest` |
| A-2 | **The test suite deleted every file in `storage/uploads/`** (CRM test setup globbed and unlinked the real directory). Running the tests on a machine that held real uploads would destroy them; it actually removed the dev uploads during this project. | New `UPLOAD_STORAGE_PATH` setting (`config/uploads.php`, `FileStore::root()`); the test bootstrap points it at a temporary directory; all tests use `FileStore::root()`. Verified: the real directory is untouched by a full run. | full suite, `FileStoreTest`, `CrmHttpTest`, `DeliverableHttpTest`, `TicketHttpTest`, `RevisionHttpTest` |
| A-3 | `docs/08-DEPLOYMENT.md` was stale: wrong console command (`user:create-admin`), claimed no runtime dependencies (PHPMailer is one), no backup/restore or rollback guidance. | Corrected; added tested backup and restore commands, uploads location, rollback, "no cron exists". | manual run of the commands |

Defects found earlier in Phases 3 and 5 are listed in the phase documents and were fixed with tests.

## 3. Security review

| Area | Finding | Evidence |
|---|---|---|
| **CSRF** | Global middleware; zero routes opt out. Tokens are per-session, compared in constant time. | `RouteSweepTest`, `CsrfTest` |
| **SQL injection** | All SQL is PDO prepared statements. Interpolated fragments (placeholder lists, `LIMIT/OFFSET` integers, column lists, scope SQL) are built from constants or cast integers, reviewed by grep (no `ORDER BY` from user input, no user text concatenated). Hostile payload sweep passed. No `eval`, `unserialize`, `shell_exec`, `system`; the only `proc_open` is the optional virus-scanner hook with an argument array. | grep review + sweep |
| **XSS** | Every template output goes through `e()`; the few unescaped `<?=` are constants or ternaries. Strict CSP (`script-src 'self'; style-src 'self'`, no inline script or style). Payload tests on tickets/notes/messages. Titles reject `<` and `>` at validation. | `TicketHttpTest`, `CrmHttpTest` |
| **IDOR / cross-client leakage** | Per-record policies (404 for missing and forbidden) for projects, enquiries, clients, tasks, deliverables, revisions, change requests, tickets, files; list queries use the same scope as record checks (a test compares them for every user and ticket). Internal notes live in separate tables and are shaped out at the data layer. | `AccessControlHttpTest`, `TicketServiceTest`, `EndToEndWorkflowTest`, `RouteSweepTest` |
| **Privilege escalation** | Levels, "strictly lower" rule, sensitive permissions only via the Owner, no self-escalation, step-up password on sensitive actions, immediate effect of role changes and deactivation. Only the Owner holds every key; Administrators are 16 keys short (owner-only and client/partner-only keys). | `AccessControlHttpTest`, `RoleServiceTest`, `PermissionMatrixTest` |
| **Sessions** | HttpOnly, SameSite=Lax, `Secure` on HTTPS or when forced, strict mode, ID regenerated on login (confirmed with curl: cookie value changes), idle and absolute timeouts, deactivation kills live sessions. | `SessionTimeoutHttpTest`, `AuthHttpTest`, curl probe |
| **Authentication** | `password_hash(PASSWORD_DEFAULT)` with rehash on login, 12-character minimum plus common-password list, identical error for unknown user and wrong password (confirmed with curl), login throttling, single-use expiring tokens for reset/invite/verification. | `AuthHttpTest`, `AccountFlowsHttpTest`, `TokenAndRateLimitTest` |
| **File uploads** | Extension + detected MIME + signature must agree; SVG/HTML/scripts/executables refused; random names in private storage outside `public/`; `storage/.htaccess` denies access and disables handlers; no route takes a path; downloads authorised per record, `attachment` + `nosniff` + `no-store`, audited, rate limited. Virus scanning is an integration point only: with no scanner configured files are recorded `not_scanned`. | `FileStoreTest`, `DeliverableHttpTest`, `TicketHttpTest` |
| **Exposed credentials** | `.env` is git-ignored and not tracked; a scan of tracked files and history found no keys, tokens or passwords; logs contain no passwords; `doctor` fails production with `APP_DEBUG=true`; the `log` mail driver is refused in production. | git scan, `doctor` |
| **Headers** | CSP, `X-Frame-Options: DENY`, `nosniff`, referrer policy, permissions policy, `Cache-Control: no-store` on every response; HSTS on HTTPS. | curl, tests |
| **Payment callbacks, duplicate webhooks, financial calculations on invoices/payments** | **Not applicable: no payment or webhook code exists.** (`VerifyCsrfToken` documents a `csrf=false` option "reserved for signature-verified webhooks"; no route uses it, and the sweep test fails if one does.) | n/a |
| **Audit log** | Hash-chained, append-only by application code (no UPDATE/DELETE path), ids not personal data, verified after restore, tampering detected. The database itself does not stop a DB-level `UPDATE` (see issue 9). | `AccessControlHttpTest`, restore test |
| **Dependency vulnerability scan** | Not run (`composer audit` needs network access to the advisory database). Runtime dependency: PHPMailer 6.x only. | n/a |

## 4. Database, configuration and deployment

* 53 tables, all InnoDB, utf8mb4; every table has a primary key; every foreign-key column has a leading index; 56 foreign keys are `RESTRICT`, 64 `SET NULL`, 9 `CASCADE` (only link rows: assignments, dependencies, role grants, auth tokens, read markers). Money is `BIGINT` integer paise. The code deletes only link rows, throttle rows and permission grants; it never deletes projects, clients, files, tickets, approvals or audit rows.
* Migrations 0001-0009 are forward-only; the migrator records applied files and detects drift; applied migrations must never be edited.
* `.env.example` covers every setting the code reads except the test-only `SESSION_IDLE_SECONDS`; `PAYMENT_*` entries are commented placeholders with no code behind them; `UPLOAD_STORAGE_PATH` was added.
* `docs/08-DEPLOYMENT.md` is a checklist that has **not** been run on Hostinger/cPanel. Compatible by design (PHP 8.2+, PDO MySQL, no framework, `.htaccess` front controller, app can sit outside `public_html`), but real hosting is unverified.
* **GitHub Pages exposure:** this repository is the Pages site for `crazybooks.me`. Merging `vertex-platform/` into the publishing branch would make its source, docs and migrations downloadable as static files. No secret is committed, but this is not what you want; see issue 2.

## 5. Feature-by-feature completion report

Status labels are exactly: **Implemented and tested** (IT), **Implemented but not fully tested** (INT), **Partially implemented** (PI), **Deferred**, **Blocked**.

| Feature | Status | Notes |
|---|---|---|
| Authentication, sessions, password reset, invites, throttling | IT | Real-HTTP tests |
| Role-based permissions, roles editor, step-up password | IT | DB-backed; matrix in `docs/04-PERMISSIONS.md` |
| Client / worker / partner data isolation | IT | Route sweep for 9 roles, per-record policies, end-to-end test |
| Audit log with hash chain | IT | Verified after restore; DB-level immutability not enforced (issue 9) |
| CRM enquiries, pipeline, duplicates, follow-ups | IT | |
| Client records, merge, portal invite | IT | Invite email delivery not tested against a real mail server |
| Projects, assignments, milestones | IT | |
| Tasks, Kanban, dependencies, time logs | IT | |
| Priority pricing, approved baseline, client-approved price changes | IT | Integer paise; "Awaiting Payment" is only a manual project status, nothing verifies a payment |
| Deliverables, versions, internal QA, publication | IT | |
| Secure file storage and downloads | IT | No virus scanner configured (hook only) |
| Client approvals of deliverables | IT | |
| Revision requests and change requests (extra-cost consent) | IT | Consent record only, no invoice |
| Support tickets and bug reports | IT | |
| Warranty tracking and per-issue eligibility | IT | |
| In-app notifications | IT | No email or WhatsApp |
| Email (SMTP via PHPMailer) | INT | Tested against a local fake SMTP server; never against a real provider; no notification uses it yet |
| Public website forms (requirements, contact) | IT | Honeypot, rate limit, consent |
| Public home page | PI | Minimal landing page; no CMS |
| Appointment booking | PI | Only an appointment **request** form that creates an enquiry; no calendar, slots or double-booking prevention |
| Quotations | Deferred | Not built |
| Invoices | Deferred | Not built |
| Payments, payment verification, gateway | Deferred | Not built; would also be Blocked by missing gateway credentials |
| Duplicate-webhook handling | Deferred | No webhook code exists |
| Referral partner enquiry submission (partners see only their own) | IT | Phase 2 |
| Referral partner application portal and approval | Deferred | Phase 6, not started |
| Referral commissions (10% after advance, 5% after full payment) | Deferred | **Not implemented, therefore not verified** |
| Commission ledger, payout requests, payout approval | Deferred | Not built |
| Freelancer payouts | Deferred | Not built |
| Domain and hosting renewals | Deferred | Not built |
| Scheduled reminders / cron jobs | Deferred | No scheduler exists |
| Backups | PI | Manual, documented and restore-tested once in a dev container; no module, no schedule, no off-site copy |
| Chat / messaging module | Deferred | Not built (ticket conversations exist) |
| CMS / public site management | Deferred | Not built |
| Reports and analytics | Deferred | Only a permission key |
| Performance / load testing | Deferred | Not done; no slow queries observed, all lists paginated, FK columns indexed |
| Deployment on Hostinger/cPanel | Blocked | Needs a real hosting account (and decision on issue 2) |
| Live payment processing | Blocked | Does not exist; needs gateway account, webhook secret, tests |

## 6. Prioritized remaining issues

**Blockers (must be resolved before calling the platform complete or taking money)**

1. **Quotation, invoice, payment, webhook, commission, payout and renewal modules do not exist.** Needs a decision from you on scope and commission rules (commission base: taxes, discounts, refunds, partial payments, cancelled projects), and a gateway account for sandbox testing. Until then nothing can be said about payment verification, duplicate webhooks, or the 10%/5% commission milestones.
2. **Do not publish `vertex-platform/` via GitHub Pages.** Move it to a private repository or deploy by upload; keep PR #2 out of the Pages publishing branch (or exclude the folder) unless you accept that the source is public.
3. **Production hosting has never been exercised** (PHP version/extensions, `.htaccess`, file permissions, HTTPS cookies, SMTP, cron limits).

**High**

4. **No scheduler** (needed for renewal and warranty-expiry reminders, session/log cleanup).
5. **Backups are manual only.** Automate (cron + off-site + encryption), then rehearse a restore on production-sized data.
6. **Real SMTP delivery untested**; no business notification emails exist (clients are told in-app only).
7. **No virus scanner configured** (`UPLOAD_SCANNER=none`); decide on ClamAV or an external service.

**Medium**

8. Appointment booking is a request form only (no slots, no double-booking protection).
9. Make audit immutability a database control as well (revoke `UPDATE`/`DELETE` on `audit_logs` from the application's DB user, or add triggers); today only the hash chain detects tampering.
10. Run `composer audit` and add a CI job (lint + tests + audit) once network access is available.
11. Load and performance test with realistic data volumes.
12. Owner role carries client-only and partner-only permission keys by design; row-level ownership checks prevent misuse (tested), but consider removing those keys from the Owner role for cleanliness.

**Low**

13. `SESSION_IDLE_SECONDS` is a test-only setting that is not in `.env.example`.
14. Open decisions D-48..D-59 in `docs/07-DECISIONS.md` (warranty end-day rule, reopen window, worker visibility) await your confirmation.

## 7. Exact manual testing instructions

Prerequisites: PHP 8.2+, MariaDB/MySQL, the repository checked out, `composer install` (or the committed `vendor/`).

**A. Set up a throwaway environment**

```bash
cd vertex-platform
cp .env.example .env            # set APP_ENV=local, APP_DEBUG=true, DB_* for a THROWAWAY database, MAIL_DRIVER=log
php bin/console.php key:generate   # paste into APP_KEY
php bin/console.php doctor
php bin/console.php migrate && php bin/console.php permissions:sync
php bin/console.php db:seed-dev    # prints ONE generated password for the fictional @example.test users; keep it
php -S 127.0.0.1:8099 -t public bin/dev-router.php
```

**B. Automated checks**

```bash
cp .env.testing.example .env.testing    # point DB_DATABASE at a throwaway database whose name ends in _test or _phpunit
vendor/bin/phpunit                       # expect: OK (529 tests, 11396 assertions); about 7 minutes
find app bin config database public tests -name '*.php' -print0 | xargs -0 -n1 php -l | grep -v "^No syntax"   # expect no output
```

**C. Isolation and permissions (browser)**

1. Sign in as `client1@example.test`; open *Projects*: only their project. Copy a project, deliverable and ticket URL.
2. Sign out, sign in as `client2@example.test`, paste each copied URL: expect **404** (never the content).
3. Sign in as `developer@example.test` (worker): open `/tickets` (technical tickets on assigned projects only), try `/warranties` and `/admin/users`: expect **403**.
4. As `partner@example.test`: only *My referrals*; `/projects` and `/tickets` are refused.
5. As `manager@example.test`: try *Record or change the warranty* on a project: not shown; a crafted POST returns 403.

**D. End-to-end journey (browser)**

1. As `admin@example.test`: *CRM*, create an enquiry, move it to **Won**, *Convert*; open the new project, record the approved quotation baseline (password required), assign the manager and developer.
2. As the developer: upload a deliverable (stays a draft; the client cannot see it). As the manager: *QA*, approve. As the admin: publish.
3. As `client1@example.test`: open *My deliverables*, download, approve the version; *Request a revision*.
4. As the manager: classify the revision *chargeable* with a reason and proposal; send it. As the client: approve or decline. Check the project price on the project page did not change.
5. As the client: *Support* > *Report a bug*; as the admin: *Warranty* tab, record start date and basis; as the manager: *Warranty review* and decision; walk the bug to *Client Verification*; as the client confirm or reopen.
6. As the admin: *Audit log*: every step above appears; click *Verify chain*: intact.

**E. Security probes (any terminal)**

```bash
curl -si 'http://127.0.0.1:8099/projects?page=99999999999999999999' | head -1     # expect a 303 to /login (anonymous), never 500
curl -si -X POST http://127.0.0.1:8099/login -d 'email=a&password=b' | head -1     # expect 419 (no CSRF token)
curl -si http://127.0.0.1:8099/.env | head -1                                      # expect 403/404
curl -si http://127.0.0.1:8099/storage/logs/ | head -1                              # expect 403/404
```

**F. Backup and restore rehearsal**: run the commands in `docs/08-DEPLOYMENT.md` ("Backup and restore") against the throwaway database; expect identical counts and `Audit chain intact`.

**G. What you must NOT expect to work**: quotations, invoices, payments, webhooks, commissions, payouts, renewals, reminders, scheduled backups, email notifications. They do not exist.
