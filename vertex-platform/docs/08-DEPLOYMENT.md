# 08 · Deployment (Hostinger / cPanel)

> **Not yet exercised on a real Hostinger/cPanel account.** The steps below are a checklist to validate there. What has been verified in a development container is listed in `docs/13-FINAL-AUDIT.md`.
>
> **Do not publish this folder through GitHub Pages.** This repository is the GitHub Pages site for `crazybooks.me`; anything under `vertex-platform/` that reaches the Pages branch is downloadable as static files (PHP does not run there, so the source, docs and migrations would be readable by anyone). No secrets are committed (verified), but deploy the platform to PHP hosting from a private location and keep it out of the Pages publishing branch (a separate private repository is the clean solution).

## Preferred layout (app outside the web root)

```
/home/<user>/vertex/          ← upload everything except public/ here (app, config, storage, bin, ...)
/home/<user>/public_html/     ← contents of vertex-platform/public/ go here
```

Then edit `public_html/index.php`: change the require to `require '/home/<user>/vertex/bootstrap.php';`.

## Alternative (document root can be set to `public/`)

Upload the whole project and set the domain's document root to `<project>/public`. If you cannot change the document root, the root `.htaccess` rewrites everything into `public/` as a safety net (still prefer the layout above).

## Steps

1. cPanel → **Select PHP Version**: PHP 8.2 or newer; enable `pdo_mysql`, `mbstring`, `openssl`, `json`, `ctype`, `fileinfo` (later phases: `gd`, `intl`, `zip`, `curl`).
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

## Backup and restore (manual procedure; tested once in a development container)

There is **no backup module and no scheduler** in this release (`backups.manage` is only a permission key). Nothing in this document means backups are running: you must configure and test them. A backup is **two things**: the database and the uploads directory, taken together.

```bash
# 1. Database (consistent snapshot, no table locks for InnoDB). Credentials come from ~/.my.cnf or prompts; never put the password on the command line.
mysqldump --single-transaction --default-character-set=utf8mb4 DBNAME | gzip > vis-db-$(date +%F).sql.gz
# 2. Uploads (same moment as the dump) and the env file (stored separately and encrypted: it holds the APP_KEY and DB password)
tar -czf vis-uploads-$(date +%F).tgz -C /path/to/storage uploads
```

Restore into an EMPTY database (never over production while testing):

```bash
mysql -e "CREATE DATABASE vis_restore CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
gunzip -c vis-db-YYYY-MM-DD.sql.gz | mysql vis_restore
DB_DATABASE=vis_restore php bin/console.php migrate:status     # every migration "applied"
DB_DATABASE=vis_restore php bin/console.php audit:verify        # "Audit chain intact (N rows verified)"
tar -xzf vis-uploads-YYYY-MM-DD.tgz -C /path/to/restored/storage
```

Verified here: a dump of the development database restored into a new database with identical row counts for users, clients, projects, tickets, audit log, stored files and migrations; `audit:verify` passed on the restored copy and reported `AUDIT CHAIN BROKEN at row 5` after a single row was edited. **Not verified:** off-site storage, encryption, scheduled runs, restore time on production-sized data. Keep the `APP_KEY` with the backup set; losing it does not corrupt data but invalidates sessions and tokens.

## Rollback

Migrations are forward-only. Roll back a bad release by restoring the pre-release database backup and the previous code. Take a backup immediately before every `migrate`.

## Cron / scheduled tasks

None are required by the current code, and none exist. Do not tell clients that reminders, expiry notices or automatic backups run: they do not.
