<?php

declare(strict_types=1);

/*
 * Krazy Integrated Solutions - one-time web installer (Hostinger / cPanel).
 *
 * Upload, open https://YOUR-DOMAIN/install.php, enter the installation key you were given, fill in the database and the first Super Admin.
 * It imports the database structure, writes vertex/.env, creates the Super Admin, creates the private storage folders, writes the .htaccess files
 * and then DELETES ITSELF. It refuses to run when vertex/.env already exists, so it can never overwrite a live installation.
 */

const INSTALL_KEY_HASH = '__INSTALL_KEY_HASH__';
const MIN_PHP = '8.2.0';

if (INSTALL_KEY_HASH === '__' . 'INSTALL_KEY_HASH' . '__') {
    exit('This installer was not built with an installation key. Use the release package.');
}

// The application folder is either inside this folder (single-folder upload) or next to it.
$appRoot = is_file(__DIR__ . '/vertex/bootstrap.php') ? __DIR__ . '/vertex' : dirname(__DIR__) . '/vertex';
$appInside = $appRoot === __DIR__ . '/vertex';
$schemaFile = $appRoot . '/database/schema.sql';
$envFile = $appRoot . '/.env';

function h(mixed $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Same statement splitter as the application's Migrator (comment lines dropped, split on ";" at the end of a line). @return list<string> */
function split_sql(string $sql): array
{
    $statements = [];
    $buffer = '';
    foreach (preg_split('/\R/', $sql) ?: [] as $line) {
        if (preg_match('/^\s*--/', $line)) {
            continue;
        }
        $buffer .= $line . "\n";
        if (preg_match('/;\s*$/', $line)) {
            $statements[] = trim($buffer);
            $buffer = '';
        }
    }
    if (trim($buffer) !== '') {
        $statements[] = trim($buffer);
    }

    return array_values(array_filter($statements, static fn (string $s): bool => $s !== ''));
}

function env_value(string $v): string
{
    return '"' . addcslashes($v, "\\\"") . '"';
}

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
        || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
}

/** @return list<array{0:string,1:bool,2:string,3:bool}> label, ok, detail, blocking */
function checks(string $appRoot, string $schemaFile, string $envFile): array
{
    $rows = [];
    $rows[] = ['PHP ' . MIN_PHP . ' or newer', version_compare(PHP_VERSION, MIN_PHP, '>='), 'This server runs PHP ' . PHP_VERSION . '. Choose PHP 8.2+ in hPanel -> Advanced -> PHP Configuration.', true];
    foreach (['pdo_mysql' => true, 'mbstring' => true, 'openssl' => true, 'json' => true, 'ctype' => true, 'fileinfo' => false, 'zip' => false] as $ext => $blocking) {
        $rows[] = ['PHP extension ' . $ext, extension_loaded($ext), $blocking ? 'Enable it in hPanel -> PHP Configuration -> Extensions.' : 'Optional' . ($ext === 'zip' ? ' (needed only for backups)' : ' (file uploads)') . '; enable it in PHP Configuration -> Extensions.', $blocking];
    }
    $rows[] = ['Application folder "vertex" found', is_file($appRoot . '/bootstrap.php'), 'Expected at ' . $appRoot . '. Upload the vertex folder into public_html (next to install.php) or next to public_html.', true];
    $rows[] = ['Database file present (vertex/database/schema.sql)', is_file($schemaFile), 'Re-upload the vertex folder completely.', true];
    $rows[] = ['Application folder is writable', is_dir($appRoot) && is_writable($appRoot), 'Set the folder permission to 755 (or 775) so the installer can write .env and storage/.', true];
    $rows[] = ['Web folder is writable (.htaccess)', is_writable(__DIR__), 'Needed to write public_html/.htaccess. If this fails, add the rewrite rules by hand.', false];
    $rows[] = ['Not installed yet (no vertex/.env)', !file_exists($envFile), 'vertex/.env already exists: this site is installed. Delete install.php.', true];
    $rows[] = ['Secure connection (HTTPS)', is_https(), 'Install on https:// so the session cookie of the Super Admin is secure. Enable AutoSSL in hPanel first.', false];

    return $rows;
}

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");

session_name('vertex_install');
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Strict']);
session_start();
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(24));
}

$errors = [];
$done = null;
$old = ['app_name' => 'Krazy Integrated Solutions', 'app_url' => (is_https() ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? ''), 'timezone' => 'Asia/Kolkata', 'db_host' => 'localhost', 'db_port' => '3306', 'db_name' => '', 'db_user' => '', 'admin_name' => '', 'admin_email' => '', 'mail_driver' => 'none', 'mail_host' => '', 'mail_port' => '587', 'mail_enc' => 'tls', 'mail_user' => '', 'mail_from' => '', 'mail_from_name' => 'Krazy Integrated Solutions'];

$alreadyInstalled = file_exists($envFile);
$posted = $_SERVER['REQUEST_METHOD'] === 'POST';

if ($posted && !$alreadyInstalled) {
    $action = (string) ($_POST['action'] ?? '');
    if (!hash_equals((string) $_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))) {
        $errors[] = 'The form expired. Reload the page and try again.';
    } elseif ($action === 'unlock') {
        $ok = hash_equals(INSTALL_KEY_HASH, hash('sha256', trim((string) ($_POST['key'] ?? ''))));
        if ($ok) {
            session_regenerate_id(true);
            $_SESSION['unlocked'] = true;
            $_SESSION['csrf'] = bin2hex(random_bytes(24));
            header('Location: install.php');
            exit;
        }
        sleep(2);
        $errors[] = 'That installation key is not correct.';
    } elseif ($action === 'install' && !empty($_SESSION['unlocked'])) {
        foreach (array_keys($old) as $k) {
            $old[$k] = trim((string) ($_POST[$k] ?? $old[$k]));
        }
        $dbPass = (string) ($_POST['db_pass'] ?? '');
        $adminPass = (string) ($_POST['admin_pass'] ?? '');
        $mailPass = (string) ($_POST['mail_pass'] ?? '');
        $log = [];
        $imported = false;
        $pdo = null;
        try {
            foreach (checks($appRoot, $schemaFile, $envFile) as [$label, $ok, , $blocking]) {
                if (!$ok && $blocking) {
                    $errors[] = 'Requirement not met: ' . $label . '.';
                }
            }
            foreach ([$old['app_name'], $old['app_url'], $old['db_host'], $old['db_name'], $old['db_user'], $old['mail_host'], $old['mail_user'], $old['mail_from'], $old['mail_from_name'], $dbPass, $mailPass] as $v) {
                if (preg_match('/[\r\n\0]/', $v)) {
                    $errors[] = 'Line breaks are not allowed in the fields.';
                    break;
                }
            }
            if ($old['app_name'] === '') {
                $errors[] = 'Enter the business name.';
            }
            if (!preg_match('~^https?://[A-Za-z0-9.-]+(:\d+)?$~', $old['app_url'])) {
                $errors[] = 'The site address must look like https://example.com (no trailing slash, no path).';
            }
            if (!in_array($old['timezone'], DateTimeZone::listIdentifiers(), true)) {
                $errors[] = 'Unknown timezone.';
            }
            if ($old['db_host'] === '' || $old['db_name'] === '' || $old['db_user'] === '' || !ctype_digit($old['db_port'])) {
                $errors[] = 'Enter the database host, port, name and user.';
            }
            if ($old['admin_name'] === '' || !filter_var($old['admin_email'], FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Enter the Super Admin name and a valid email address.';
            }
            if (!hash_equals($adminPass, (string) ($_POST['admin_pass2'] ?? ''))) {
                $errors[] = 'The two Super Admin passwords do not match.';
            }
            if (!in_array($old['mail_driver'], ['none', 'smtp'], true)) {
                $errors[] = 'Unknown email setting.';
            } elseif ($old['mail_driver'] === 'smtp' && ($old['mail_host'] === '' || !ctype_digit($old['mail_port']) || !in_array($old['mail_enc'], ['tls', 'ssl', 'none'], true) || !filter_var($old['mail_from'], FILTER_VALIDATE_EMAIL))) {
                $errors[] = 'For SMTP email enter the host, port, encryption and a valid From address.';
            }
            if ($errors !== []) {
                throw new RuntimeException('Please correct the problems above.');
            }

            session_write_close();
            require $appRoot . '/bootstrap.php';

            $problems = Vertex\Services\PasswordPolicy::check($adminPass, mb_strtolower($old['admin_email']), $old['admin_name']);
            if ($problems !== []) {
                $errors = array_merge($errors, $problems);
                throw new RuntimeException('Please choose a stronger Super Admin password.');
            }

            // 1. Database
            try {
                $pdo = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $old['db_host'], (int) $old['db_port'], $old['db_name']), $old['db_user'], $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            } catch (PDOException $e) {
                $errors[] = 'Could not connect to the database. Check the host (usually "localhost"), name, user and password. Server said: ' . preg_replace('/\s+/', ' ', $e->getMessage());
                throw new RuntimeException('Database connection failed.');
            }
            $log[] = 'Connected to the database (' . $pdo->query('SELECT VERSION()')->fetchColumn() . ').';
            $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
            $schema = (string) file_get_contents($schemaFile);
            preg_match_all("/^-- ===== (\d{4}_[a-z0-9_]+\.sql) =====/m", $schema, $m);
            $expectedMigrations = count($m[1]);
            if ($tables === []) {
                $pdo->exec('SET NAMES utf8mb4');
                $imported = true; // from here on a failure drops whatever was created
                foreach (split_sql($schema) as $statement) {
                    $pdo->exec($statement);
                }
                $log[] = 'Imported the database structure (' . count($tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN)) . ' tables).';
            } elseif (in_array('schema_migrations', $tables, true) && in_array('users', $tables, true)
                && (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0
                && (int) $pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn() === $expectedMigrations
                && (int) $pdo->query('SELECT COUNT(*) FROM permissions')->fetchColumn() > 0) {
                $log[] = 'The database already contains the imported structure (for example through phpMyAdmin) and no users; it was kept.';
            } else {
                $errors[] = 'The database is not empty (' . count($tables) . ' tables). Use a new, empty database, or one that only contains the imported vertex-database.sql. Nothing was changed.';
                throw new RuntimeException('Database not empty.');
            }
            if ((int) $pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn() !== $expectedMigrations || (int) $pdo->query('SELECT COUNT(*) FROM permissions')->fetchColumn() < 1) {
                $errors[] = 'The database structure is incomplete. The uploaded schema.sql may be damaged: upload the package again.';
                throw new RuntimeException('Incomplete import.');
            }

            // 2. Settings file
            $appKey = 'base64:' . base64_encode(random_bytes(32));
            $env = [
                '# Written by install.php on ' . gmdate('Y-m-d H:i') . ' UTC. Keep this file private (chmod 600). Never commit or e-mail it.',
                '# Keep a copy of APP_KEY in a password manager: without it encrypted payment settings and payout details cannot be read.',
                'APP_NAME=' . env_value($old['app_name']),
                'APP_ENV=production',
                'APP_DEBUG=false',
                'APP_URL=' . $old['app_url'],
                'APP_TIMEZONE=' . $old['timezone'],
                'APP_KEY=' . $appKey,
                'TRUSTED_PROXIES=',
                'DB_HOST=' . env_value($old['db_host']),
                'DB_PORT=' . (int) $old['db_port'],
                'DB_DATABASE=' . env_value($old['db_name']),
                'DB_USERNAME=' . env_value($old['db_user']),
                'DB_PASSWORD=' . env_value($dbPass),
                'SESSION_IDLE_MINUTES=60',
                'SESSION_ABSOLUTE_MINUTES=720',
                'SESSION_FORCE_SECURE=' . (str_starts_with($old['app_url'], 'https://') ? 'true' : 'false'),
                'LOGIN_MAX_ATTEMPTS=5',
                'LOGIN_LOCKOUT_MINUTES=15',
                'REGISTRATION_ENABLED=false',
                'MAIL_DRIVER=' . $old['mail_driver'],
                'MAIL_HOST=' . env_value($old['mail_host']),
                'MAIL_PORT=' . (int) $old['mail_port'],
                'MAIL_ENCRYPTION=' . $old['mail_enc'],
                'MAIL_USERNAME=' . env_value($old['mail_user']),
                'MAIL_PASSWORD=' . env_value($mailPass),
                'MAIL_FROM_ADDRESS=' . env_value($old['mail_from']),
                'MAIL_FROM_NAME=' . env_value($old['mail_from_name']),
                'BACKUP_KEEP=14',
                '',
            ];
            if (@file_put_contents($envFile, implode("\n", $env), LOCK_EX) === false) {
                $errors[] = 'Could not write vertex/.env. Make the vertex folder writable (permission 755/775) and try again.';
                throw new RuntimeException('Cannot write .env.');
            }
            @chmod($envFile, 0600);
            $log[] = 'Wrote vertex/.env (permission 600) with a new random APP_KEY.';

            // 3. Super Admin (through the application's own code so passwords, roles and the audit chain are identical to the console command)
            Vertex\Core\Config::set('app.key', $appKey);
            Vertex\Core\Config::set('database', ['host' => $old['db_host'], 'port' => (int) $old['db_port'], 'database' => $old['db_name'], 'username' => $old['db_user'], 'password' => $dbPass, 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci']);
            Vertex\Core\Database::disconnect();
            $sync = Vertex\Services\PermissionSync::run(false);
            $repo = new Vertex\Repositories\UserRepository();
            $email = mb_strtolower($old['admin_email']);
            $id = $repo->create($old['admin_name'], $email, password_hash($adminPass, PASSWORD_DEFAULT), 'active', ['super_admin'], null, null, true);
            Vertex\Services\Audit::record('user.created', 'user', $id, [], ['email' => $email, 'roles' => ['super_admin'], 'status' => 'active'], ['via' => 'installer'], null, 'installer');
            $log[] = 'Created the Super Admin ' . $email . ' (roles and permissions checked: ' . $sync['permissions'] . ' permissions).';

            // 4. Private folders and .htaccess files
            $deny = "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n";
            foreach (['storage', 'storage/logs', 'storage/sessions', 'storage/uploads', 'storage/backups', 'storage/cache'] as $d) {
                if (!is_dir($appRoot . '/' . $d)) {
                    @mkdir($appRoot . '/' . $d, 0750, true);
                }
            }
            @file_put_contents($appRoot . '/.htaccess', "# Private application folder: nothing here may be served.\n" . $deny);
            @file_put_contents($appRoot . '/storage/.htaccess', "# Private storage (uploads, logs, sessions, backups). Never serve anything from here.\n" . $deny . "Options -Indexes -ExecCGI\nRemoveHandler .php .phtml .php3 .php4 .php5 .php7 .phar\n");
            $https = str_starts_with($old['app_url'], 'https://') ? "    # Send everything to HTTPS (the AutoSSL validation path stays reachable)\n    RewriteCond %{HTTPS} off\n    RewriteCond %{HTTP:X-Forwarded-Proto} !https\n    RewriteCond %{REQUEST_URI} !^/\\.well-known/\n    RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]\n\n" : '';
            $public = "# Front controller routing (Apache / LiteSpeed on Hostinger). Written by install.php.\nOptions -Indexes -MultiViews\nDirectoryIndex index.php\n\n<IfModule mod_rewrite.c>\n    RewriteEngine On\n" . $https
                . "    # Pass the Authorization header to PHP (kept for payment webhooks)\n    RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]\n\n"
                . "    # Real files (css, js, images) are served directly; everything else goes through index.php\n    RewriteCond %{REQUEST_FILENAME} !-f\n    RewriteCond %{REQUEST_FILENAME} !-d\n    RewriteRule ^ index.php [L]\n</IfModule>\n\n"
                . "# Never expose dotfiles or leftovers\n<FilesMatch \"(^\\.|~\$|\\.(bak|old|orig|sql|log|env|ini|sh|md|lock|json)\$)\">\n    <IfModule mod_authz_core.c>\n        Require all denied\n    </IfModule>\n    <IfModule !mod_authz_core.c>\n        Order allow,deny\n        Deny from all\n    </IfModule>\n</FilesMatch>\n";
            if (@file_put_contents(__DIR__ . '/.htaccess', $public) === false) {
                $log[] = 'WARNING: could not write public_html/.htaccess. Pages other than the home page will not load until you create it (see the notes at the end).';
            } else {
                $log[] = 'Wrote the .htaccess files (routing, HTTPS redirect, private folders).';
            }
            if ($appInside) { // the app folder is under the web root: prove the server really refuses to serve it
                $probe = @get_headers(rtrim($old['app_url'], '/') . '/vertex/bootstrap.php', false, stream_context_create(['http' => ['timeout' => 6, 'ignore_errors' => true], 'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]));
                if (is_array($probe) && isset($probe[0]) && str_contains($probe[0], ' 200 ')) {
                    $log[] = 'WARNING: the vertex folder is reachable from the web. Move the vertex folder next to public_html (one level up), or ask Hostinger support to enable .htaccess rules for this site.';
                } else {
                    $log[] = 'Checked: the vertex folder is not served to visitors.';
                }
            }
            $media = __DIR__ . '/uploads/cms';
            if (!is_dir($media)) {
                @mkdir($media, 0755, true);
            }
            @file_put_contents($media . '/.htaccess', "# Website pictures only: nothing in here may ever run as a script.\nOptions -Indexes -ExecCGI\nRemoveHandler .php .phtml .php3 .php4 .php5 .php7 .phar\n<FilesMatch \"\\.(php|phtml|phar|pl|py|cgi|sh)\$\">\n    Require all denied\n</FilesMatch>\n");
            $log[] = 'Created the private storage folders and the website pictures folder.';

            $removed = @unlink(__FILE__);
            $done = ['log' => $log, 'email' => $email, 'url' => $old['app_url'], 'removed' => $removed, 'php' => PHP_VERSION];
            session_start();
            $_SESSION = [];
            session_destroy();
        } catch (Throwable $e) {
            if ($errors === []) {
                $errors[] = 'Installation stopped: ' . preg_replace('/\s+/', ' ', $e->getMessage());
            }
            // Undo everything this run created so the installer can simply be run again.
            if ($imported && $pdo instanceof PDO) {
                try {
                    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
                    foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) {
                        $pdo->exec('DROP TABLE IF EXISTS `' . str_replace('`', '``', (string) $t) . '`');
                    }
                    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
                    $log[] = 'The tables created by this run were removed again.';
                } catch (Throwable) {
                    $errors[] = 'Could not clean the tables up automatically: empty the database in phpMyAdmin before trying again.';
                }
            }
            if (is_file($envFile) && $done === null) {
                @unlink($envFile);
            }
            if (session_status() !== PHP_SESSION_ACTIVE) {
                session_start();
            }
        }
    }
}

$unlocked = !empty($_SESSION['unlocked']);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Install Vertex</title>
<style>
:root{color-scheme:light dark;--bg:#f4f6fb;--card:#fff;--ink:#14213d;--mut:#5a6785;--line:#d6dcea;--acc:#1d4ed8;--ok:#0a7d3b;--bad:#b42318}
@media (prefers-color-scheme:dark){:root{--bg:#0d1424;--card:#151e33;--ink:#e8ecf7;--mut:#9aa7c4;--line:#2a3654;--acc:#7aa2ff;--ok:#4ade80;--bad:#ff8a80}}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:16px/1.5 system-ui,-apple-system,Segoe UI,Roboto,sans-serif}
main{max-width:720px;margin:0 auto;padding:24px 16px 64px}h1{font-size:1.5rem;margin:.2em 0}h2{font-size:1.1rem;margin:1.6em 0 .4em}
.card{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:18px 18px 8px;margin:14px 0}
label{display:block;font-weight:600;margin:12px 0 4px}small{color:var(--mut);display:block;margin-top:2px}
input,select{width:100%;padding:10px;border:1px solid var(--line);border-radius:8px;background:var(--bg);color:var(--ink);font:inherit}
button{margin:18px 0 12px;padding:12px 20px;border:0;border-radius:8px;background:var(--acc);color:#fff;font:inherit;font-weight:700;cursor:pointer}
.err{border:1px solid var(--bad);color:var(--bad);border-radius:8px;padding:10px 14px;margin:12px 0}.err p{margin:.2em 0}
table{width:100%;border-collapse:collapse}td{padding:6px 4px;border-bottom:1px solid var(--line);vertical-align:top}.y{color:var(--ok);font-weight:700}.n{color:var(--bad);font-weight:700}.w{color:var(--mut);font-weight:700}
code,pre{background:var(--bg);border:1px solid var(--line);border-radius:6px;padding:2px 6px;word-break:break-all}pre{padding:10px;white-space:pre-wrap}
.row{display:grid;grid-template-columns:1fr 1fr;gap:0 14px}@media(max-width:560px){.row{grid-template-columns:1fr}}
</style>
</head>
<body>
<main>
<h1>Krazy Integrated Solutions &middot; installer</h1>

<?php if ($alreadyInstalled && $done === null): ?>
    <div class="card"><p><strong>This site is already installed.</strong> <code>vertex/.env</code> exists, so the installer is disabled.</p>
    <p>Delete <code>install.php</code> from the <code>public_html</code> folder (File Manager). Then open the site.</p></div>

<?php elseif ($done !== null): ?>
    <div class="card"><h2 style="margin-top:0">Installed</h2>
    <ul><?php foreach ($done['log'] as $l): ?><li><?= h($l) ?></li><?php endforeach; ?></ul>
    <p><?= $done['removed'] ? '<span class="y">install.php deleted itself.</span>' : '<span class="n">Delete install.php from public_html now (File Manager). It could not delete itself.</span>' ?></p></div>
    <h2>Next steps</h2>
    <div class="card"><ol>
        <li>Open <a href="<?= h($done['url']) ?>/login"><?= h($done['url']) ?>/login</a> and sign in as <strong><?= h($done['email']) ?></strong>. <a href="<?= h($done['url']) ?>/health">/health</a> should show <code>{"status":"ok"}</code>.</li>
        <li>Save the APP_KEY line of <code>vertex/.env</code> in a password manager.</li>
        <li><strong>Cron jobs</strong> (hPanel &rarr; Advanced &rarr; Cron Jobs; choose PHP 8.2+ if asked). Use the full path of your <code>vertex</code> folder:
<pre>15 2 * * *  php <?= h($appRoot) ?>/bin/console.php schedule:run
40 2 * * *  php <?= h($appRoot) ?>/bin/console.php backup:create --keep=14</pre></li>
        <li>In the admin area: Administration &rarr; Business details, then Administration &rarr; Payments (Google Pay UPI).</li>
        <li>Email is <?= $old['mail_driver'] === 'smtp' ? 'set to SMTP: send yourself a test password reset to prove it works' : 'off: set the MAIL_* lines in vertex/.env to your Hostinger mailbox to enable invitations and password resets' ?>.</li>
    </ol></div>

<?php elseif (!$unlocked): ?>
    <p>Enter the installation key that came with this package.</p>
    <?php foreach ($errors as $e): ?><div class="err"><?= h($e) ?></div><?php endforeach; ?>
    <form method="post" class="card" autocomplete="off">
        <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>"><input type="hidden" name="action" value="unlock">
        <label for="key">Installation key</label><input id="key" name="key" type="password" required autofocus>
        <button type="submit">Continue</button>
    </form>

<?php else: ?>
    <h2>1. Server check</h2>
    <div class="card"><table>
    <?php foreach (checks($appRoot, $schemaFile, $envFile) as [$label, $ok, $detail, $blocking]): ?>
        <tr><td class="<?= $ok ? 'y' : ($blocking ? 'n' : 'w') ?>"><?= $ok ? 'OK' : ($blocking ? 'FIX' : 'NOTE') ?></td><td><?= h($label) ?><?php if (!$ok): ?><small><?= h($detail) ?></small><?php endif; ?></td></tr>
    <?php endforeach; ?>
    </table><br></div>

    <?php foreach ($errors as $e): ?><div class="err"><?= h($e) ?></div><?php endforeach; ?>
    <form method="post" autocomplete="off">
    <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>"><input type="hidden" name="action" value="install">

    <h2>2. Business and site</h2>
    <div class="card">
        <label for="app_name">Business name</label><input id="app_name" name="app_name" value="<?= h($old['app_name']) ?>" required>
        <label for="app_url">Site address</label><input id="app_url" name="app_url" value="<?= h($old['app_url']) ?>" required><small>Exactly as customers open it, e.g. https://yourdomain.com (no slash at the end).</small>
        <label for="timezone">Timezone</label><input id="timezone" name="timezone" value="<?= h($old['timezone']) ?>" required>
    </div>

    <h2>3. Database</h2>
    <div class="card">
        <small>hPanel &rarr; Databases &rarr; Management: create an EMPTY MySQL database and user. Use the same name, user and password here.</small>
        <div class="row"><div><label for="db_host">Host</label><input id="db_host" name="db_host" value="<?= h($old['db_host']) ?>" required></div>
        <div><label for="db_port">Port</label><input id="db_port" name="db_port" value="<?= h($old['db_port']) ?>" required></div></div>
        <label for="db_name">Database name</label><input id="db_name" name="db_name" value="<?= h($old['db_name']) ?>" required>
        <label for="db_user">Database user</label><input id="db_user" name="db_user" value="<?= h($old['db_user']) ?>" required>
        <label for="db_pass">Database password</label><input id="db_pass" name="db_pass" type="password">
    </div>

    <h2>4. Super Admin (the owner account)</h2>
    <div class="card">
        <label for="admin_name">Full name</label><input id="admin_name" name="admin_name" value="<?= h($old['admin_name']) ?>" required>
        <label for="admin_email">Email</label><input id="admin_email" name="admin_email" type="email" value="<?= h($old['admin_email']) ?>" required>
        <div class="row"><div><label for="admin_pass">Password</label><input id="admin_pass" name="admin_pass" type="password" minlength="12" required><small>At least 12 characters; not your name or email.</small></div>
        <div><label for="admin_pass2">Repeat password</label><input id="admin_pass2" name="admin_pass2" type="password" minlength="12" required></div></div>
    </div>

    <h2>5. Email (can be done later)</h2>
    <div class="card">
        <label for="mail_driver">Sending</label>
        <select id="mail_driver" name="mail_driver"><option value="none"<?= $old['mail_driver'] === 'none' ? ' selected' : '' ?>>Off for now</option><option value="smtp"<?= $old['mail_driver'] === 'smtp' ? ' selected' : '' ?>>SMTP (Hostinger mailbox)</option></select>
        <div class="row"><div><label for="mail_host">SMTP host</label><input id="mail_host" name="mail_host" value="<?= h($old['mail_host']) ?>" placeholder="smtp.hostinger.com"></div>
        <div><label for="mail_port">Port</label><input id="mail_port" name="mail_port" value="<?= h($old['mail_port']) ?>"></div></div>
        <label for="mail_enc">Encryption</label><select id="mail_enc" name="mail_enc"><?php foreach (['tls' => 'STARTTLS (587)', 'ssl' => 'SSL (465)', 'none' => 'None'] as $k => $v): ?><option value="<?= $k ?>"<?= $old['mail_enc'] === $k ? ' selected' : '' ?>><?= h($v) ?></option><?php endforeach; ?></select>
        <label for="mail_user">SMTP username</label><input id="mail_user" name="mail_user" value="<?= h($old['mail_user']) ?>">
        <label for="mail_pass">SMTP password</label><input id="mail_pass" name="mail_pass" type="password">
        <div class="row"><div><label for="mail_from">From address</label><input id="mail_from" name="mail_from" type="email" value="<?= h($old['mail_from']) ?>"></div>
        <div><label for="mail_from_name">From name</label><input id="mail_from_name" name="mail_from_name" value="<?= h($old['mail_from_name']) ?>"></div></div>
    </div>

    <button type="submit">Install</button>
    <p><small>This imports the database, writes the settings file and deletes this installer. It stops without changes if the database is not empty.</small></p>
    </form>
<?php endif; ?>
</main>
</body>
</html>
