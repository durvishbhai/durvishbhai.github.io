# 08 · Deployment (Hostinger / cPanel)

> Not yet exercised on a real Hostinger account. Treat as a checklist to validate in Phase 1.

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
5. `composer install --no-dev --optimize-autoloader` locally and upload `vendor/` if the host has no Composer (Phase 0 has no runtime dependencies, so this step is optional until a later phase adds one).
6. Over SSH (or cPanel Terminal): `php bin/console.php doctor`, `php bin/console.php migrate`, `php bin/console.php permissions:sync`, `php bin/console.php user:create-admin --email=... --name="..."`.
7. Enable HTTPS (AutoSSL) and set `APP_URL` to the `https://` URL; confirm the session cookie shows `Secure`. Behind a proxy that terminates TLS, set `SESSION_FORCE_SECURE=true`.
8. Visit `/health` → `{"status":"ok"}`; sign in; confirm `/.env`, `/config/app.php`, `/storage/logs/` return 404/403.

## Updating

Upload changed files, run `php bin/console.php migrate` then `permissions:sync`. Never edit an applied migration; add a new one.
