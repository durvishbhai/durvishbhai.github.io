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

## Known gaps (tracked, not hidden)

- No 2FA, password reset, email verification or password-strength policy beyond length ≥ 12 in the console admin command (Phase 1).
- Anonymous visits create a session file (the public pages start the session). Phase 1: start sessions lazily for authenticated/form routes only, to avoid session-file growth on shared hosting.
- `audit_logs` hash chain detects tampering but cannot *prevent* a DB-privileged attacker from rewriting the entire chain; mitigate with periodic off-server export of the latest hash (Phase 9).
- Rate limiting covers login only; add for password reset, contact/referral forms, chat, uploads (Phase 1+).
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
