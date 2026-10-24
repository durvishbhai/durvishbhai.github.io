# 16 · Go-live assessment and handover package

Last updated: 2026-10-14 (after Phase 12) · Branch `ccr-13b48464-fpb2gp` · Environment tested: PHP 8.3.6, MariaDB 10.11, Chromium, development container. **Nothing has been deployed to a real host, no live payment has been taken, no live AI or Razorpay call has been made, and no secret is in this repository or in this document.**

## 1. Verdict: is it ready for production?

**Feature-complete against the 37 modules (with the stated exceptions), heavily tested in a development container — but not yet proven on a real host or against the live third-party services. Treat it as "ready for a staged go-live", not "ready to take real money tomorrow".**

* **Ready in code (automated tests, 750+ passing):** everything in `19-REQUIREMENTS-TRACEABILITY.md` marked IT: accounts, 2FA, custom roles, audit chain, CRM, quotations, invoices, credit notes, PDFs, coupons, reports and charts, approval centre, clients, projects/tasks/templates/checklists, deliverables and client approval, revisions, scope requests, timestamp feedback, support with SLA, workflow rules, forms, agreements, referral commissions, payouts, renewals, global search, recycle bin, CSV import/export, website CMS with SEO, settings centre.
* **Implemented but never exercised for real (labelled INT):** **Razorpay** (simulation only), **AI assistance** (simulation only), **SMTP delivery**, **Hostinger deployment and cron**, mobile layout of the Phase 9–12 screens.
* **Not built:** third-party e-sign, registrar/hosting provider APIs, WhatsApp/SMS, 2FA QR code, automated off-site backup and error alerting, free-form page builder (see `19` "Not built").
* **Never exercised on the real host:** the web installer, cron, SMTP, HTTPS cookie behaviour behind the proxy, a restore on the real server, the Razorpay webhook (HMAC signature) reaching the server.

### Gate A — internal pilot (staff only, payments stay manual/UPI)
No new development needed:
1. Upload the Hostinger package, run `install.php` (it removes itself), sign in as the Owner (`08-DEPLOYMENT.md`).
2. *Integrations & settings*: SMTP (send a real invitation and reset to yourself), business name, company facts.
3. Cron: `schedule:run` and `backup:create` (`15-PHASE7-RENEWALS-OPERATIONS.md` §3), run both once by hand, confirm *System status* is green.
4. Backups: copy one off the server, store `APP_KEY` separately, do one real **restore drill** into an empty database and run `audit:verify`.
5. Business decisions in §5 (at least D-60).

### Gate B — customers paying online through Razorpay
Needs inputs only you can supply and a test only the real service can give: Razorpay API keys (test first), then the **test-mode checklist in `18-PHASE12-CMS-AI-PHONEPE.md` §4** end to end (including a closed-tab payment and a webhook), then one real ₹1 production payment. Until that is done, keep UPI + staff verification as the payment method.

### Gate C — AI switched on
Add an API key, try each helper on harmless data, confirm the monthly cap and the log (*ai_requests*). Keep it off if you do not want any record text to leave the server.

## 2. Completion matrix

See **`19-REQUIREMENTS-TRACEABILITY.md`** for the module-by-module table (37 modules, 12 roles) with one honest label each. Earlier-phase detail remains in docs 09–15 and 17.

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
- [ ] Razorpay test-mode checklist completed (doc 18 §4) before telling clients they can pay online; no claim that WhatsApp or third-party e-signature exist.
- [ ] Website pictures folder (`public_html/uploads/cms`) is writable and appears in a backup archive.

## 4. Handover package (what to give the operator; contains no secrets)
1. **Source and layout** — repository `durvishbhai.github.io`, folder `vertex-platform/` (README.md, composer.json). 2. **Architecture** — `docs/02-ARCHITECTURE.md`. 3. **Module inventory and status** — §2 above and `docs/05-MODULES-AND-PLAN.md`. 4. **Database** — `docs/03-DATABASE.md`, `database/migrations/0001…0017` (forward-only). 5. **Roles and permissions** — `docs/04-PERMISSIONS.md` (generated matrix). 6. **Configuration reference** — `.env.example` (names and meanings only). 7. **Deployment guide** — `docs/08-DEPLOYMENT.md`. 8. **Scheduled tasks** — `docs/15-PHASE7-RENEWALS-OPERATIONS.md` §3. 9. **Backup and restore runbook** — same document §4. 10. **Security review and residual risks** — `docs/13-FINAL-AUDIT.md` and `15` §7. 11. **Tests** — `tests/` (`composer test`; needs a throwaway database named `*_phpunit`; see README). 12. **Manual smoke tests** — `docs/09…15` checklists and §6 below. 13. **Operations runbook** — §6. 14. **Decisions and open questions** — `docs/07-DECISIONS.md` (D-01 … D-83), `docs/17-BILLING-AND-GOOGLE-PAY.md` and §5.
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
