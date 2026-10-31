<?php

declare(strict_types=1);

/*
 * Krazy Integrated Solutions - one-time web UPGRADER for a site that is already installed (Hostinger / cPanel, no SSH needed).
 *
 * Use it after uploading a newer release over the old files: open https://YOUR-DOMAIN/upgrade.php, enter the installation key printed with the
 * release, and press "Apply updates". It applies only the database changes that are still missing (the same forward-only migrations the command
 * line uses), adds any new permissions without touching the roles you edited, and then DELETES ITSELF. It never touches .env, users or content,
 * and it refuses to run when the site is not installed yet (use install.php for that).
 */

const INSTALL_KEY_HASH = '__INSTALL_KEY_HASH__';

if (INSTALL_KEY_HASH === '__' . 'INSTALL_KEY_HASH' . '__') {
    exit('This upgrader was not built with an installation key. Use the release package.');
}

$appRoot = is_file(__DIR__ . '/vertex/bootstrap.php') ? __DIR__ . '/vertex' : dirname(__DIR__) . '/vertex';

function h(mixed $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
        || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
}

/**
 * Puts back the small server files a site needs, when they are missing: the routing file in the web folder (without it every page except the home page
 * shows the host's own "This Page Does Not Exist"), and the "never serve this" files for the private folders. Existing good files are left alone.
 *
 * @return list<string> what was checked or written
 */
function repair_server_files(string $appRoot): array
{
    $log = [];
    $deny = "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n";
    $env = (string) @file_get_contents($appRoot . '/.env');
    $https = preg_match('/^APP_URL=(\S+)/m', $env, $m) && str_starts_with(trim($m[1], "\"'"), 'https://');
    $htaccess = __DIR__ . '/.htaccess';
    $current = is_file($htaccess) ? (string) @file_get_contents($htaccess) : '';
    if (!str_contains($current, 'RewriteRule ^ index.php')) {
        $rules = "# Front controller routing (Apache / LiteSpeed on Hostinger). Written by upgrade.php.\nOptions -Indexes -MultiViews\nDirectoryIndex index.php\n\n<IfModule mod_rewrite.c>\n    RewriteEngine On\n"
            . ($https ? "    # Send everything to HTTPS (the AutoSSL validation path stays reachable)\n    RewriteCond %{HTTPS} off\n    RewriteCond %{HTTP:X-Forwarded-Proto} !https\n    RewriteCond %{REQUEST_URI} !^/\\.well-known/\n    RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]\n\n" : '')
            . "    # Pass the Authorization header to PHP (kept for payment webhooks)\n    RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]\n\n"
            . "    # Real files (css, js, images) are served directly; everything else goes through index.php\n    RewriteCond %{REQUEST_FILENAME} !-f\n    RewriteCond %{REQUEST_FILENAME} !-d\n    RewriteRule ^ index.php [L]\n</IfModule>\n\n"
            . "# Never expose dotfiles or leftovers\n<FilesMatch \"(^\\.|~\$|\\.(bak|old|orig|sql|log|env|ini|sh|md|lock|json)\$)\">\n    <IfModule mod_authz_core.c>\n        Require all denied\n    </IfModule>\n    <IfModule !mod_authz_core.c>\n        Order allow,deny\n        Deny from all\n    </IfModule>\n</FilesMatch>\n";
        if ($current !== '') {
            @copy($htaccess, $htaccess . '.before-upgrade.bak');
        }
        $log[] = @file_put_contents($htaccess, $rules) === false
            ? 'WARNING: could not write public_html/.htaccess. Pages other than the home page will show "This Page Does Not Exist" until you create it (see the notes on this page).'
            : 'Restored public_html/.htaccess (page routing' . ($https ? ' and the HTTPS redirect' : '') . ').';
    } else {
        $log[] = 'Routing file public_html/.htaccess is present.';
    }
    $files = [
        $appRoot . '/.htaccess' => "# Private application folder: nothing here may be served.\n" . $deny,
        $appRoot . '/storage/.htaccess' => "# Private storage (uploads, logs, sessions, backups). Never serve anything from here.\n" . $deny . "Options -Indexes -ExecCGI\nRemoveHandler .php .phtml .php3 .php4 .php5 .php7 .phar\n",
        __DIR__ . '/uploads/cms/.htaccess' => "# Website pictures only: nothing in here may ever run as a script.\nOptions -Indexes -ExecCGI\nRemoveHandler .php .phtml .php3 .php4 .php5 .php7 .phar\n<FilesMatch \"\\.(php|phtml|phar|pl|py|cgi|sh)\$\">\n    Require all denied\n</FilesMatch>\n",
    ];
    foreach ($files as $path => $body) {
        if (!is_dir(dirname($path))) {
            @mkdir(dirname($path), 0755, true);
        }
        if (!is_file($path) && is_dir(dirname($path))) {
            $log[] = @file_put_contents($path, $body) === false ? 'WARNING: could not write ' . basename(dirname($path)) . '/.htaccess.' : 'Restored ' . basename(dirname($path)) . '/.htaccess (protects a private folder).';
        }
    }

    return $log;
}

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");

session_name('vertex_upgrade');
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Strict']);
session_start();
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(24));
}

$errors = [];
$result = null;
$pending = [];
$installed = is_file($appRoot . '/bootstrap.php') && is_file($appRoot . '/.env');
$unlocked = !empty($_SESSION['unlocked']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $installed) {
    $action = (string) ($_POST['action'] ?? '');
    if (!hash_equals((string) $_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))) {
        $errors[] = 'The form expired. Reload the page and try again.';
    } elseif ($action === 'unlock') {
        if (hash_equals(INSTALL_KEY_HASH, hash('sha256', trim((string) ($_POST['key'] ?? ''))))) {
            session_regenerate_id(true);
            $_SESSION['unlocked'] = true;
            $_SESSION['csrf'] = bin2hex(random_bytes(24));
            header('Location: upgrade.php');
            exit;
        }
        sleep(2);
        $errors[] = 'That installation key is not correct.';
    } elseif ($action === 'upgrade' && $unlocked) {
        try {
            require_once $appRoot . '/bootstrap.php';
            $ran = (new \Vertex\Services\Migrator(VERTEX_ROOT . '/database/migrations'))->migrate();
            $sync = \Vertex\Services\PermissionSync::run(false);
            $result = ['ran' => $ran, 'permissions' => $sync['permissions'] ?? 0, 'new' => $sync['new_permissions'] ?? 0, 'files' => repair_server_files($appRoot)];
            $_SESSION = [];
            session_destroy();
            if (is_file(__DIR__ . '/install.php')) { // the site is installed: the installer is no longer needed
                $result['files'][] = @unlink(__DIR__ . '/install.php') ? 'Removed install.php (this site is already installed).' : 'Please delete install.php by hand.';
            }
            $self = @unlink(__FILE__);
            $result['deleted'] = $self;
        } catch (\Throwable $e) {
            $errors[] = 'The update stopped: ' . $e->getMessage() . ' Nothing further was changed. Fix the cause (or send this message to support) and press the button again; completed steps are not repeated.';
        }
    }
}

if ($installed && $unlocked && $result === null) {
    try {
        require_once $appRoot . '/bootstrap.php';
        foreach ((new \Vertex\Services\Migrator(VERTEX_ROOT . '/database/migrations'))->status() as $row) {
            if ($row['status'] !== 'applied') {
                $pending[] = $row;
            }
        }
    } catch (\Throwable $e) {
        $errors[] = 'Could not read the database: ' . $e->getMessage();
    }
}
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Upgrade</title>
<style>body{font:16px/1.5 system-ui,sans-serif;background:#050818;color:#e8ecff;margin:0;padding:24px}main{max-width:620px;margin:40px auto;background:#0d1330;border:1px solid #243063;border-radius:14px;padding:28px}h1{margin-top:0;font-size:1.4rem}input{width:100%;padding:10px;border-radius:8px;border:1px solid #243063;background:#050818;color:inherit;box-sizing:border-box}button{margin-top:14px;padding:11px 20px;border:0;border-radius:8px;background:linear-gradient(90deg,#19d3ff,#7c5cff);color:#04101f;font-weight:700;cursor:pointer}.err{background:#3a1020;border:1px solid #a1264b;padding:10px 14px;border-radius:8px;margin:12px 0}.ok{background:#0e2e24;border:1px solid #1f8a62;padding:10px 14px;border-radius:8px;margin:12px 0}code{background:#050818;padding:1px 6px;border-radius:5px}li{margin:4px 0}</style></head>
<body><main>
<h1>Upgrade this site</h1>
<?php foreach ($errors as $e) : ?><div class="err"><?= h($e) ?></div><?php endforeach; ?>
<?php if (!$installed) : ?>
    <p>This site is not installed yet, so there is nothing to upgrade. Open <code>install.php</code> instead.</p>
<?php elseif ($result !== null) : ?>
    <div class="ok"><strong>Done.</strong>
        <?= $result['ran'] === [] ? 'The database was already up to date.' : 'Applied: ' . h(implode(', ', $result['ran'])) . '.' ?>
        <?= (int) $result['new'] > 0 ? ' ' . (int) $result['new'] . ' new permission(s) were added; the roles you edited were not changed.' : '' ?></div>
    <ul><?php foreach ($result['files'] as $l) : ?><li><?= h($l) ?></li><?php endforeach; ?></ul>
    <p><?= $result['deleted'] ? 'This upgrader deleted itself.' : 'Please delete <code>upgrade.php</code> from your web folder now (it could not delete itself).' ?></p>
    <p><a href="/login" style="color:#19d3ff">Go to the sign-in page</a>. Then open <strong>Administration &rarr; Website</strong> once to load the starting website content.</p>
<?php elseif (!$unlocked) : ?>
    <p>Enter the installation key that came with this release.</p>
    <form method="post"><input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>"><input type="hidden" name="action" value="unlock">
        <label for="key">Installation key</label><input id="key" name="key" autocomplete="off" required><button type="submit">Continue</button></form>
<?php else : ?>
    <?php if ($pending === [] && $errors === []) : ?>
        <p>The database is already up to date. You can still press the button to add any missing permissions.</p>
    <?php elseif ($pending !== []) : ?>
        <p>These database updates will be applied:</p><ul><?php foreach ($pending as $p) : ?><li><code><?= h($p['migration']) ?></code> (<?= h($p['status']) ?>)</li><?php endforeach; ?></ul>
        <p>Take a backup first if you can (hPanel &rarr; Databases &rarr; phpMyAdmin &rarr; Export). Existing data is not removed.</p>
    <?php endif; ?>
    <?php $ht = is_file(__DIR__ . '/.htaccess') && str_contains((string) @file_get_contents(__DIR__ . '/.htaccess'), 'RewriteRule ^ index.php'); ?>
    <p><?= $ht ? 'Page routing file (<code>.htaccess</code>): present.' : '<strong>Page routing file (<code>.htaccess</code>) is missing or incomplete.</strong> Without it every page except the home page shows the host\'s "This Page Does Not Exist". It will be restored when you press the button.' ?></p>
    <form method="post"><input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>"><input type="hidden" name="action" value="upgrade"><button type="submit">Apply updates</button></form>
<?php endif; ?>
</main></body></html>
