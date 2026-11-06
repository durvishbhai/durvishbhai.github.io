# 01 · Repository inspection report (Phase 0)

Inspected before any change was made. Nothing existing was modified or deleted.

## What the repository actually is

`durvishbhai/durvishbhai.github.io` is a **GitHub Pages user site**, unrelated to Vertex so far.

| Item | Finding |
|---|---|
| Files | `index.html` (1.1 KB), `CNAME` (`crazybooks.me`), `README.md` (one line) |
| Content | A one-page static site, "Young Author Durvish Jawale" (dark theme, inline CSS, placeholder email `your-email@gmail.com`) |
| History | 3 commits (`Initial commit`, `Create index.html`, `Added CNAME file for custom domain (crazybooks.me)`) |
| Branches | `main`, plus the working branch `ccr-13b48464-fpb2gp` |
| Language / framework | None. Static HTML. No PHP, no Composer, no JS tooling |
| Database / migrations | None |
| Authentication / authorization | None |
| Invoice page / invoice code | **None exists in this repository** |
| Admin dashboard / other modules | None |
| Configuration / secrets | None found |

## Consequences

1. **There is no existing invoice functionality here to preserve.** The brief says it must be preserved and extended. It is either in another repository, on a server, or not yet written. See `docs/07-DECISIONS.md` D-01. Nothing was invented to stand in for it.
2. **GitHub Pages cannot execute PHP or host MySQL.** The platform cannot run from this repo's Pages deployment. It needs PHP hosting (Hostinger/cPanel as specified).
3. **Source exposure risk.** If Pages is enabled for this repo, every committed file under it is served as static text, including `vertex-platform/config/*.php`, `app/**` and `database/migrations/*.sql`. No secrets are committed (`.env` is gitignored) but the application source and schema would be publicly readable at `crazybooks.me/vertex-platform/...`. **Recommendation: move the platform to its own private repository before real development.** It lives in a subfolder now only so that the existing live site (`index.html`, `CNAME`) is untouched.
4. **Name mismatch.** The repo/domain belong to a personal author site; the platform is for a business. Confirm this is the intended repository.

## Environment (this build sandbox, not the production host)

| | |
|---|---|
| PHP | 8.3.6 CLI; extensions present: PDO, pdo_mysql, mbstring, openssl, json, ctype, fileinfo, gd, intl, zip, curl, sodium |
| Database used for testing | MariaDB 10.11.14 (installed in the sandbox, throwaway databases only) |
| Composer | Available; PHPUnit 11 installed as the only dev dependency |
| Production target | Hostinger/cPanel (not verified: no access to the account). Requires PHP ≥ 8.2 selectable in cPanel, MySQL/MariaDB, Apache/LiteSpeed with `.htaccess` |

## Hosting compatibility requirements (design constraints applied)

- PHP ≥ 8.2 with `pdo_mysql`, `mbstring`, `openssl`, `json`, `ctype`, `fileinfo`. Later phases need `gd` (PDF/images), `intl`, `zip` (backups), `curl` (gateway/email).
- No dependency on Node, queues, Redis, long-running workers, shell access, or cron beyond cPanel's standard cron jobs. Zero runtime Composer packages in Phase 0 (works even if `composer install` is unavailable on the host).
- Document root should be `public/`; a root `.htaccess` fallback blocks exposure of `app/`, `config/`, `storage/`, `.env` if it is not possible.
- Triggers/stored programs avoided (shared hosting often lacks the privilege). Tamper evidence for audit logs is done in application code (hash chain) instead.
- Shared-hosting email limits and cron granularity will shape the email/renewal-reminder design in later phases.

## Missing dependencies and credentials (none are needed yet; needed by the phase shown)

| Needed | Phase | Notes |
|---|---|---|
| Production DB name/user/password | 1 (deploy) | cPanel > MySQL Databases |
| SMTP account (sender address) | 1/6 | Hostinger mailbox or transactional provider |
| Payment gateway account + API keys + webhook secret | 5 | Provider not chosen (D-05) |
| Domain/hosting provider details for renewals | 9 | Provider APIs may not exist; manual confirmation flow is the default |
| PDF library (e.g. Dompdf) | 4/7 | Composer package; bundle `vendor/` if Composer unavailable on host |
| Lawyer-reviewed legal text | 8 | Cannot be authored by engineering |
| Final logo files (vector/SVG) | 1 | Only a 1254×1254 PNG with a dark background was supplied; derived transparent PNGs were generated from it |

## Risks to existing functionality

| Risk | Mitigation |
|---|---|
| Overwriting the live `index.html` / `CNAME` | Platform is isolated in `vertex-platform/`; no file outside it was touched |
| Public source exposure through Pages | See Consequence 3; move to a private repo |
| Pages build treating `vertex-platform/` as a Jekyll site | Pages ignores PHP; harmless, but a root-level `.nojekyll` could be added if desired (not added, to avoid touching the live site) |
| Assuming an invoice system exists | None assumed; flagged as D-01 |
