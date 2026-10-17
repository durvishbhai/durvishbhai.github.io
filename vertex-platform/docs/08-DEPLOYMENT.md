# 08 · Deployment (Hostinger / cPanel)

> **Not yet exercised on a real Hostinger/cPanel account.** The steps below are a checklist to validate there. What has been verified in a development container is listed in `docs/13-FINAL-AUDIT.md`.
>
> **Do not publish this folder through GitHub Pages.** This repository is the GitHub Pages site for `crazybooks.me`; anything under `vertex-platform/` that reaches the Pages branch is downloadable as static files (PHP does not run there, so the source, docs and migrations would be readable by anyone). No secrets are committed (verified), but deploy the platform to PHP hosting from a private location and keep it out of the Pages publishing branch (a separate private repository is the clean solution).

## Hostinger upload package (php / js / css / sql only)

`php bin/build-release.php --schema=FILE --out=DIR` builds the package the hosting migrator and File Manager accept: **only `.php`, `.js`, `.css` and `.sql` files** (the build fails if anything else slips in, and lints every PHP file).

```
vertex-database.sql      the complete database (also inside vertex/database/schema.sql): structure + roles + permissions, no user data
public_html/             web root: index.php, install.php (one-time), assets/css, assets/js, assets/img.php (brand images embedded as text)
vertex/                  the application (app, config, database/migrations, bin/console.php, vendor = PHPMailer + autoloader), never web-accessible
```

* The two folders must sit next to each other (`domains/yoursite.com/public_html` + `domains/yoursite.com/vertex`). `public_html/index.php` finds `../vertex/bootstrap.php`.
* Dotfiles and images are not shippable under the "four extensions" rule, so **`install.php` writes them on the server**: `vertex/.env` (new random APP_KEY, mode 600), `public_html/.htaccess` (routing, HTTPS redirect, dotfile/sql/log protection), `vertex/.htaccess` and `vertex/storage/.htaccess` (deny all), and the private `storage/` folders.
* `install.php` is unlocked by a one-time installation key (only its SHA-256 is in the file). It checks PHP/extensions/permissions, imports the database file (rolling back every table it created if anything fails), accepts a database that already holds the imported file (phpMyAdmin route) and no users, refuses any other non-empty database, creates the Super Admin through the application's own code, then deletes itself. It refuses to run when `vertex/.env` exists.
* Regenerate the database file after adding a migration: `php bin/console.php migrate && php bin/console.php permissions:sync && php bin/console.php schema:export --file=...` against a scratch database. `SchemaExportTest` proves the file rebuilds the migrated database exactly.
* Later updates: upload the changed `vertex/` files and run `php vertex/bin/console.php migrate` (SSH), or import only the new migration in phpMyAdmin. Never re-import the full database file over a live database.

## Preferred layout (app outside the web root)

```
/home/<user>/vertex/          ← upload everything except public/ here (app, config, storage, bin, ...)
/home/<user>/public_html/     ← contents of vertex-platform/public/ go here
```

Then edit `public_html/index.php`: change the require to `require '/home/<user>/vertex/bootstrap.php';`.

## Alternative (document root can be set to `public/`)

Upload the whole project and set the domain's document root to `<project>/public`. If you cannot change the document root, the root `.htaccess` rewrites everything into `public/` as a safety net (still prefer the layout above).

## Steps

1. cPanel → **Select PHP Version**: PHP 8.2 or newer; enable `pdo_mysql`, `mbstring`, `openssl`, `json`, `ctype`, `fileinfo` (`zip` is required for backups; later phases may use `gd`, `intl`, `curl`).
2. cPanel → **MySQL Databases**: create a database and user, grant all privileges.
3. Copy `.env.example` to `.env`; set `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL`, DB credentials; generate the key with `php bin/console.php key:generate` (or run it locally and paste the value). `chmod 600 .env`.
4. Make `storage/*` writable by PHP (755/775 depending on the host's user model); never expose `storage/`.
5. `composer install --no-dev --optimize-autoloader` locally and upload `vendor/` if the host has no Composer. **PHPMailer is a runtime dependency** (needed only when `MAIL_DRIVER=smtp`); the app boots without `vendor/` but SMTP delivery then fails.
6. Over SSH (or cPanel Terminal): `php bin/console.php doctor`, `php bin/console.php migrate`, `php bin/console.php permissions:sync`, `php bin/console.php user:create-super-admin --email=owner@yourdomain --name="Full Name"` (the password is prompted, never a command-line argument).
7. Enable HTTPS (AutoSSL) and set `APP_URL` to the `https://` URL; confirm the session cookie shows `Secure`. Behind a proxy that terminates TLS, set `SESSION_FORCE_SECURE=true`.
8. Visit `/health` → `{"status":"ok"}`; sign in; confirm `/.env`, `/config/app.php`, `/storage/logs/` return 404/403.

## Updating

Upload changed files, run `php bin/console.php migrate` then `permissions:sync`. Never edit an applied migration; add a new one.


## Uploads location

Private uploads are stored in `storage/uploads/YYYY/MM/<random>` by default. Set `UPLOAD_STORAGE_PATH` (absolute path) to keep them elsewhere, outside the web root. **The automated tests never touch this directory** (they use a temporary one); earlier versions of the test suite deleted files in `storage/uploads`, so do not run a pre-audit copy of the tests on a machine that holds real uploads.

## Backup and restore

Since Phase 7 the application can create, verify and restore its own backups from the console (details, limits and the tested results in `15-PHASE7-RENEWALS-OPERATIONS.md` §4). **Nothing runs by itself**: a backup exists only after `backup:create` has been run (by hand or by cron), and backups are trustworthy only after a restore drill.

```bash
php bin/console.php backup:create --keep=14          # database + uploads -> one verified zip in BACKUP_DIR (default storage/backups)
php bin/console.php backup:verify --file=PATH.zip     # re-check every checksum
php bin/console.php backup:status                     # when was the newest one made? says so plainly if there is none
# restore ONLY into an empty database (it refuses anything else):
php bin/console.php backup:restore --file=PATH.zip --database=NEW_EMPTY_DB --uploads-dir=/path/to/new/uploads
php bin/console.php migrate:status && php bin/console.php audit:verify    # with DB_DATABASE pointing at the restored database
```

The zip contains the database snapshot, the uploads store and a checksum manifest. It does **not** contain `.env`: keep `APP_KEY` with the backup set (a password manager), otherwise encrypted payout details cannot be read after a restore. The backup is not encrypted: encrypt it (for example `gpg -c`) and copy it **off the server**. The manual `mysqldump` + `tar` procedure from earlier phases still works and is a valid second method. Requires the PHP `zip` extension.

Verified: a backup/restore round trip on the development database and in automated tests (identical row counts, uploads and audit chain). **Not verified:** a restore on the real host, off-site storage, encryption, scheduled runs, restore time at production size.

## Rollback

Migrations are forward-only. Roll back a bad release by restoring the pre-release database backup and the previous code. Take a backup immediately before every `migrate`.

## Cron / scheduled tasks

Two cron jobs are needed (cPanel → Cron Jobs; adjust paths):

```
15 2 * * *  /usr/bin/php /home/USER/vertex/bin/console.php schedule:run >> /home/USER/vertex/storage/logs/cron.log 2>&1
40 2 * * *  /usr/bin/php /home/USER/vertex/bin/console.php backup:create --keep=14 >> /home/USER/vertex/storage/logs/cron.log 2>&1
```

`schedule:run` moves renewals along the calendar, sends reminders and releases commission hold periods; it is idempotent. **Do not tell staff or clients that reminders or backups run until you have seen both jobs run** (`schedule:status`, `backup:status`, *Admin → System status*). Until then the dashboards show a warning. Run `php bin/console.php doctor` before launch: it lists every production warning (HTTP instead of HTTPS, debug on, SMTP not configured, no scanner, seed users, never-run cron, no backup).

## Phase 12 additions (website, AI, PhonePe)

* **Website pictures** are saved in `public_html/uploads/cms/` (the installer creates it with a script-blocking `.htaccess`). They are included in `backup:create` (archive path `uploads/website-media/`); after a restore copy them back into that folder.
* **PhonePe webhook**: `https://YOUR-SITE/webhooks/phonepe`. The installer's `.htaccess` passes the `Authorization` header to PHP. If you replace the `.htaccess`, keep `RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]`.
* **Settings**: after install sign in as the Owner and open *Integrations & settings* to enter SMTP, AI and PhonePe values instead of editing `.env`. `APP_KEY` must stay the same forever (sealed secrets depend on it).
* **Scheduled task**: the daily `schedule:run` now also publishes due website pages and re-checks pending PhonePe orders; with PhonePe on, run it more often (hourly is fine, it is idempotent).
* Sandbox checklist for PhonePe: `docs/18-PHASE12-CMS-AI-PHONEPE.md` §4.


## Single-folder package (upload straight into public_html)

`bin/build-release.php --layout=inside` also produces `vertex-platform-hostinger-single-folder-<date>.zip`: a folder `upload_to_public_html/` (index.php, install.php, assets/, and the `vertex/` application folder inside it) plus `vertex-database.sql` beside it. Upload the **contents** of `upload_to_public_html/` into `public_html/`, open `/install.php`. The installer writes `vertex/.htaccess` (deny all) and then probes `/vertex/bootstrap.php`, warning if the server still serves it. The sibling layout above is safer (nothing application-related is under the web root) and is preferred when possible. Keep `vertex-database.sql` out of `public_html`.
