# 06 · Security and testing plan

## Controls in place now (Phase 0), and how each is verified

| Threat | Control | Verified by |
|---|---|---|
| SQL injection | PDO real prepared statements only (`EMULATE_PREPARES=false`); no string-built SQL with user input | `testSqlInjectionAttemptInEmailIsInert`; code review rule |
| XSS | `e()` on all output; strict CSP (no inline script/style, `object-src 'none'`); `X-Content-Type-Options` | Browser run: 0 CSP console errors on home/login/dashboard |
| CSRF | Global synchroniser token on POST/PUT/PATCH/DELETE; opt-out only per route (future webhooks) ; `SameSite=Lax` cookie | `CsrfTest` (6), HTTP: POST without token → 419 |
| Session fixation/hijack | Strict mode, `session_regenerate_id` on login, ID + CSRF rotated; HttpOnly, SameSite, Secure on HTTPS; idle (60 m) + absolute (12 h) timeout; UA binding | HTTP flow; session code review |
| Credential stuffing / brute force | DB-backed throttle per email+IP (5/15 min) and per-IP ceiling (20); generic failure message; dummy hash for unknown email | `testLockoutAfterRepeatedFailures…`, HTTP lockout run |
| Account enumeration | Same message for bad password / unknown email / suspended | `testWrongPassword…Indistinguishable` |
| Weak password storage | `password_hash(PASSWORD_DEFAULT)` + rehash on login | `testPasswordsAreStoredHashed…` |
| Stale sessions after password change / suspension | Session bound to HMAC of password hash; status checked each request | two integration tests |
| Privilege escalation | Explicit permission matrix; no wildcard/bypass; Admin-only permission list pinned by tests | `PermissionMatrixTest` (mutation-checked: leaking `projects.assign` to Manager fails the suite) |
| Audit tampering | Hash-chained `audit_logs`; secrets redacted; `audit:verify` | tamper (modify/delete) tests |
| Info leakage | Prod error pages show only a reference id; `X-Powered-By` stripped; `/health` returns status only; `display_errors` off | HTTP checks |
| Open redirect | Post-login redirect restricted to same-site paths | code (`AuthController::intended`); add a test in Phase 1 |
| Clickjacking / MIME / referrer | `X-Frame-Options: DENY` + `frame-ancestors 'none'`, `nosniff`, `Referrer-Policy`, `Permissions-Policy`, HSTS in production | header check |
| Secrets in repo | `.env` gitignored; `.env.example` only; no keys in code | `git status`/scan before commit |

## Controls added in Phase 2, and how each is verified

| Threat | Control | Verified by |
|---|---|---|
| IDOR on enquiries/clients/projects/tasks/milestones/attachments | Per-record policies (`EnquiryPolicy`, `ClientPolicy`, `ProjectPolicy`, `TaskPolicy`) used by routes, services, list queries, JSON endpoints and downloads; missing and forbidden both 404; random public ids | `ProjectTaskHttpTest::testGuessingProjectAndTaskUrls…`, `ClientHttpTest`, `CrmHttpTest`, `TaskServiceTest::testTaskVisibilityFollowsProjectAssignmentExactly` |
| Cross-project object swap | Child records are always resolved *within* the project of the URL (`findInProject`) | `testMilestoneIdsCannotBeSwappedAcrossProjects`, dependency cross-project test |
| Worker privilege creep | Workers move only their own tasks; cannot assign, edit, archive, add dependencies, or see budget/pricing | `TaskServiceTest` (assignee/review/assign tests), `ProjectTaskHttpTest` |
| Drag-and-drop bypass | The Kanban endpoint uses the same `TaskService::transition()`; CSRF header required; role + dependency + state checks on the server | `testDragAndDropEndpointValidatesEveryMoveOnTheServer`; browser run |
| Race / stale writes | Row locks (`FOR UPDATE`) on task status, project priority and the price request; stale views rejected | stale-status, stale-proposal tests |
| Silent price change | Approved baseline written once; changes only via client-approved append-only revisions; source-scan test forbids updates/deletes of price history | `PricingTest` (23 tests incl. mutation-checked) |
| Upload abuse | Extension + sniffed MIME allow-list, 5 MB, random names outside web root, per-user rate limit, authorized download only | `CrmHttpTest`, `testTaskAttachmentsAreAuthorizedByTheTaskPolicy` |
| Public form abuse | CSRF, honeypot, per-IP rate limit, consent, aggregated validation, no data echoed unescaped | `CrmHttpTest` |
| Bad data / injection | Prepared statements; LIKE escaping; all text via `FieldRules` (control chars stripped, `< >` rejected in titles); output via `e()` | search-injection and XSS tests |
| Closure variable bugs (silent wrong-scope) | `ClosureScopeTest` statically checks every `function () use (...)` for undeclared variables | mutation-verified |

## Known gaps (tracked, not hidden)

- No 2FA. (Password reset, email verification, password policy and lazy sessions were added in Phase 1.)
- `audit_logs` hash chain detects tampering but cannot *prevent* a DB-privileged attacker from rewriting the entire chain; mitigate with periodic off-server export of the latest hash (Phase 9).
- Rate limiting now covers login, step-up password checks, registration, password reset and verification resend, the public enquiry forms and attachment uploads; still to add: chat. No CAPTCHA (D-38).
- Not tested on a real Hostinger account (PHP selector, `.htaccess` behaviour under LiteSpeed, file permissions).
- No SAST/dependency scanning pipeline yet (`composer audit` is available; add to CI).

## Controls required in later phases (acceptance criteria written now)

- **Files:** stored outside web root, random names, MIME sniff + allow-list, size limits, authorized streaming only, optional AV scan; IDOR tests for every file endpoint.
- **Payments:** signature verification (constant-time) of every webhook; replay protection by event id; amounts taken from the server-side invoice, never from the browser; transaction + row lock on invoice; idempotency keys; reconciliation report; no card data touches the server (hosted gateway fields).
- **Payouts:** separate permissions for request/approve/record; approver ≠ requester check; amount ≤ ledger balance inside a locked transaction.
- **IDOR:** every `{id}` route has a policy check and a negative test per role.
- **PII / compliance:** data-retention and deletion policy, consent records, DPDP Act considerations (D-14).
- **Backups:** encrypted, off-server copy, restore drill before launch.

## Test strategy

| Layer | Tooling | Scope |
|---|---|---|
| Static | `php -l` on every file (`composer lint`), later PHPStan level 6+ and `composer audit` | every commit |
| Unit | PHPUnit 11, no DB | Env, Validator, Router, CSRF, Request/IP trust, permission-matrix invariants, migrator splitter, (later) Money, commission math, state-machine transitions |
| Integration | PHPUnit + throwaway MySQL/MariaDB (refuses any DB not named `*_test`/`*_phpunit`) | migrations, sync, Gate, Auth, throttling, audit, transactions; (later) every service |
| HTTP smoke | `php -S` + curl; later Playwright for portal flows | login/logout/CSRF/lockout/redirects/headers; later role journeys |
| Security regression | One negative test per rule: unassigned user ⇒ 403/404; replayed webhook ⇒ no second payment; manager ⇒ cannot assign | grows per phase; mutation spot-checks of critical rules |
| Pre-launch | Manual pen-test checklist (OWASP ASVS L2 subset), restore drill, load test of payment webhook | Phase 9 |

Run: `composer lint && composer test` (needs `.env.testing`, see `README.md`).
