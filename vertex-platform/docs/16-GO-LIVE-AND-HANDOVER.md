# 16 · Go-live assessment and handover package

Date of assessment: 2026-10-12 · Branch `ccr-13b48464-fpb2gp` · Environment tested: PHP 8.3.6, MariaDB 10.11, Chromium, development container. **Nothing was deployed, no live payment was enabled, no secret is in this repository or in this document.**

## 1. Verdict: is it ready for production?

**No — not as a complete system, and not yet as a pilot.** What exists is solid and heavily tested; what stands between it and production is a short list of *unbuilt features*, *missing credentials/business decisions*, and *things that have never been run on the real host*. None of the blockers is a known security flaw.

* **Ready in code (tested):** accounts and roles, audit chain, CRM, clients, projects, tasks, deliverables and client approval, revisions and chargeable-work consent, bug reports, warranty, support tickets, referral partners with 10% + 5% commissions and payouts, domain/hosting renewals with a scheduler, portable backup and restore, system status page. **Full automated suite: 665 tests, 19,317 assertions, all passing (final run, 2026-10-12).**
* **Not built (so the platform cannot yet run the whole business):** quotations, invoices and receipts; an online payment gateway and its webhooks (so "duplicate webhook handling" does not exist to be tested); WhatsApp and general email notifications; a CMS and public website inside the platform (the existing `crazybooks.me` GitHub Pages site is separate); chat; calendar-slot appointment booking; payouts for freelancers/workers; financial reports; two-factor authentication.
* **Never exercised on the real host:** Hostinger/cPanel deployment, cron, SMTP delivery, HTTPS cookie behaviour behind the real proxy, a restore on the real server.

### Gate A — internal pilot (staff only, billing stays outside the system)
Can be reached with **no new development**, in roughly this order:
1. Deploy from a **private** location, not through GitHub Pages (`08-DEPLOYMENT.md`). Document root = `public/`.
2. Production `.env` (names only in `.env.example`): `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://…`, new `APP_KEY`, database credentials, `SESSION_FORCE_SECURE` if TLS is terminated upstream.
3. `migrate`, `permissions:sync`, `user:create-super-admin` (password prompted). **Do not run `db:seed-dev`; if it was ever run, delete the `@example.test` users** (`doctor` warns).
4. **SMTP**: set `MAIL_*`, send a real invitation and a password reset to yourself, confirm delivery.
5. **Cron**: `schedule:run` daily and `backup:create` nightly (`15-PHASE7-RENEWALS-OPERATIONS.md` §3); run both once by hand; confirm `schedule:status`, `backup:status` and *System status* show OK.
6. **Backups**: copy a backup off the server, store `APP_KEY` separately, perform one real **restore drill** into an empty database and run `audit:verify`.
7. `php bin/console.php doctor` shows no warnings you cannot explain.
8. Business decisions in §5 (at least D-60).

### Gate B — public launch with customers paying through the platform
Needs **new work and inputs that only you can provide**: a payment gateway account and a choice of provider (sandbox first, then live; webhook secret; signature verification and idempotency to be built and audited); quotations and GST-compliant invoices (GSTIN, HSN/SAC, numbering rules, credit notes: needs your accountant); legal text (Terms, Privacy Policy, Referral Partner Terms: placeholders today); two-factor authentication for Owner/Administrator; a malware scanner; a third-party penetration test; a provider/registrar workflow decision for renewals; accountant sign-off on TDS/GST for commission payouts.

## 2. Completion matrix (exactly one label per feature)

Labels: **IT** Implemented and tested · **INT** Implemented but not fully tested · **PI** Partially implemented · **DEF** Deferred to a future phase · **BLK** Blocked by a missing credential or external dependency.

| Area | Feature | Status | Notes |
|---|---|---|---|
| Foundation | Sessions, login throttling, CSRF, CSP, security headers | IT | Route sweep, HTTP tests, live header probe |
| | Registration, email verification, password reset, invitations | IT | Tested with a local fake SMTP; real delivery not verified (see Email) |
| | Roles, permissions, levels, step-up password, audit hash chain | IT | `PermissionMatrixTest`, `RoleServiceTest`, route sweep |
| | Two-factor authentication | DEF | Recommended for Owner/Admin |
| Email | SMTP delivery | BLK | Needs real SMTP credentials; only the local fake server was used |
| Public | Request-quote, contact, book-appointment forms → CRM | IT | Honeypot, rate limit, CSRF |
| | Public website / CMS inside the platform | DEF | The existing static site is separate |
| | Appointment calendar with slots and double-booking prevention | DEF | The form creates an enquiry only |
| CRM & delivery | Enquiries, stages, follow-ups, duplicates, client records, merge | IT | |
| | Projects, assignments, milestones, tasks, time, team, board | IT | |
| | Pricing: approved baseline, priority surcharge, client-decided changes | IT | |
| | Deliverables, versions, QA, client approval, private downloads | IT | |
| | Revisions, cost proposals, client consent | IT | |
| | Bug reports, warranty, support tickets | IT | |
| | Chat | DEF | Permission key only |
| Money | Quotations | DEF | Not built |
| | Invoices, receipts, GST numbering | DEF | Not built; needs your legal details |
| | Online payments, gateway, webhooks, duplicate-webhook handling | BLK | Not built; needs a provider account and keys |
| | Manual client payment recording + two-person verification | IT | `client_payments`, unique reference |
| | Financial reports | DEF | |
| Referral partners | Application, approval (no auto-activation), terms | IT | |
| | Referrals, duplicate detection, attribution, locking | IT | |
| | Commissions 10% (advance) + 5% (full payment), adjustments, reversals | IT | Formula mode needs your confirmation (D-60) |
| | Payout requests, concurrency-safe reservation, approval ≠ payment, manual recording | IT | Subprocess race tests |
| | Freelancer / worker payouts | DEF | Only referral-partner payouts exist |
| Renewals | Records, 12-status workflow, price list, approval, invoice reference, payments, provider confirmation | IT | |
| | Client portal for domains and hosting | IT | Read-only |
| | Reminders with de-duplication, scheduler | IT | The command is tested; the host cron has never run |
| | Registrar / hosting provider integration | DEF | Manual confirmation by design |
| Notifications | In-app events with de-duplication | IT | |
| | Email notifications (account flows, referral emails, optional renewal reminders) | INT | Delivery depends on SMTP |
| | WhatsApp, SMS, push | BLK | Needs an approved integration and credentials |
| Operations | Backup, verify, restore (CLI), retention | IT | Dev restore + automated tests |
| | Scheduled backups, off-site copy, encryption | PI | Cron lines documented; nothing runs until you configure it |
| | System status page, `doctor` production checks | IT | |
| | Deployment on Hostinger/cPanel | INT | Documented; never run on the real host |
| Security | Static scan, route sweep, dependency audit, live probe | IT | `composer audit`: no advisories on 2026-10-12 |
| | Third-party penetration test | DEF | Not performed |

## 3. Go-live checklist (tick each, with evidence)
- [ ] Deployed from a private repository or archive; document root is `public/`; `/.env`, `/storage/…`, `/app/…`, `/vendor/…` return 404/403.
- [ ] `APP_ENV=production`, `APP_DEBUG=false`, HTTPS on, session cookie shows `Secure`.
- [ ] New `APP_KEY` stored in a password manager together with the first backup.
- [ ] Super Admin created from the console; no seed users; every other account invited by email.
- [ ] SMTP verified with real messages (invitation, reset).
- [ ] `schedule:run` and `backup:create` in cron and **seen to run**; `/admin/system` has no unexplained warning.
- [ ] One off-site backup copy exists, encrypted, and a **restore drill** passed on a clean database.
- [ ] Terms, Privacy Policy and Referral Partner Terms reviewed by someone qualified; D-60 decided.
- [ ] Staff briefed on what is manual: invoices, payments, provider renewals are recorded by hand.
- [ ] No claim to clients that online payment, automatic invoices or WhatsApp exist.

## 4. Handover package (what to give the operator; contains no secrets)
1. **Source and layout** — repository `durvishbhai.github.io`, folder `vertex-platform/` (README.md, composer.json). 2. **Architecture** — `docs/02-ARCHITECTURE.md`. 3. **Module inventory and status** — §2 above and `docs/05-MODULES-AND-PLAN.md`. 4. **Database** — `docs/03-DATABASE.md`, `database/migrations/0001…0011` (forward-only). 5. **Roles and permissions** — `docs/04-PERMISSIONS.md` (generated matrix). 6. **Configuration reference** — `.env.example` (names and meanings only). 7. **Deployment guide** — `docs/08-DEPLOYMENT.md`. 8. **Scheduled tasks** — `docs/15-PHASE7-RENEWALS-OPERATIONS.md` §3. 9. **Backup and restore runbook** — same document §4. 10. **Security review and residual risks** — `docs/13-FINAL-AUDIT.md` and `15` §7. 11. **Tests** — `tests/` (`composer test`; needs a throwaway database named `*_phpunit`; see README). 12. **Manual smoke tests** — `docs/09…15` checklists and §6 below. 13. **Operations runbook** — §6. 14. **Decisions and open questions** — `docs/07-DECISIONS.md` (D-01 … D-76) and §5.
Credentials, API keys, the `.env` file, `APP_KEY`, SMTP and gateway secrets are **never** part of the handover repository; hand them over through a password manager.

## 5. Open business decisions
D-60 commission formula mode (cumulative vs per-milestone) · GST/TDS treatment of commission payouts and of invoices · payment gateway choice · SMTP provider · terms/privacy wording · whether renewal client reminders may also go by email (`RENEWAL_CLIENT_EMAIL`) · which administrators hold `renewals.approve` / `renewals.confirm_provider` / `payouts.approve` · whether separation of duties stays on in a very small team · backup retention and where the off-site copy lives.

## 6. Operations runbook
| Situation | Action |
|---|---|
| New staff member | *Users → Create/Invite*; assign the lowest role that works; never share logins |
| Someone left | *Users →* deactivate (sessions end); data and audit trail are kept |
| Password forgotten | Self-service reset (needs SMTP); otherwise `user:set-password --email=` on the server |
| Reminders stopped | `schedule:status`; check cron and `storage/logs/cron.log`; run `schedule:run` by hand |
| No recent backup | `backup:status`; run `backup:create`; check disk space and the zip extension |
| Disk filling | Prune old backups (`--keep`), rotate `storage/logs`, check upload growth |
| Restore needed | Empty database → `backup:restore` → `migrate:status` → `audit:verify` (`15` §4) |
| Wrong expiry date on a service | *Correct the expiry date* (reason + password); not possible while an invoice/payment is in flight |
| Client says "I paid" | Record the payment with the transaction reference; it does **not** renew: record the provider confirmation separately |
| Partner dispute over credit | Referral page: credit decision with a reason; once commission is eligible the credit is locked and only commission adjustments are possible |
| APP_KEY lost or changed | Sessions and tokens are invalidated and **encrypted payout details become unreadable** (partners must re-enter them): never rotate it casually |
| Audit chain reported broken | Stop; restore the last good backup to a separate database and compare; treat as a possible tamper |
| Roll back a release | Restore the pre-release backup and the previous code (migrations are forward-only); take a backup before every `migrate` |
