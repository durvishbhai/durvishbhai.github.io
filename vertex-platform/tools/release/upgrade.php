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
            $result = ['ran' => $ran, 'permissions' => $sync['permissions'] ?? 0, 'new' => $sync['new_permissions'] ?? 0];
            $_SESSION = [];
            session_destroy();
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
    <form method="post"><input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>"><input type="hidden" name="action" value="upgrade"><button type="submit">Apply updates</button></form>
<?php endif; ?>
</main></body></html>
