<?php

declare(strict_types=1);

/*
 * Vertex console. Usage: php bin/console.php <command> [options]
 *
 *   doctor                    Check PHP version, extensions, writable dirs, config and DB connectivity
 *   key:generate              Print a new APP_KEY (paste into .env)
 *   migrate                   Apply pending database migrations
 *   migrate:status            List migrations and detect drift
 *   permissions:sync          Add new permissions/roles from config; keeps UI edits. Add --reset-roles to restore config defaults
 *   permissions:matrix        Print the role/permission matrix as Markdown (generated from config; no DB needed)
 *   user:create-super-admin   Create the first Super Admin/Owner (--email=, --name=; password is prompted, never an argument)
 *   user:set-password         Set a user's password and activate a pending account (--email=; prompted)
 *   db:seed-dev               Insert FICTIONAL development data (APP_ENV=local only; prints a generated password once)
 *   audit:verify              Verify the audit-log hash chain
 *   schedule:run              The scheduled task for cron: renewal stages and reminders, commission hold periods (safe to run repeatedly)
 *   schedule:status           When the scheduled task last ran
 *   backup:create             Write a verified backup zip (database + uploads) to the backup directory (--dir=, --keep=N)
 *   backup:verify             Check a backup file's integrity (--file=)
 *   backup:restore            Restore a backup into an EMPTY database (--file=, --database=, optional --uploads-dir=)
 *   backup:status             When the newest backup was made
 */

if (PHP_SAPI !== 'cli') {
    exit('CLI only.');
}

require dirname(__DIR__) . '/bootstrap.php';

use Vertex\Core\Config;
use Vertex\Core\Database;
use Vertex\Repositories\UserRepository;
use Vertex\Services\Audit;
use Vertex\Services\DevSeeder;
use Vertex\Services\PasswordPolicy;
use Vertex\Services\Migrator;
use Vertex\Services\PermissionSync;

$command = $argv[1] ?? 'help';
$options = [];
foreach (array_slice($argv, 2) as $arg) {
    if (preg_match('/^--([a-z\-]+)=(.*)$/', $arg, $m)) {
        $options[$m[1]] = $m[2];
    }
}

function out(string $line = ''): void
{
    fwrite(STDOUT, $line . "\n");
}

function fail(string $line): never
{
    fwrite(STDERR, $line . "\n");
    exit(1);
}

function prompt_hidden(string $label): string
{
    fwrite(STDOUT, $label);
    $interactive = function_exists('posix_isatty') && posix_isatty(STDIN);
    if ($interactive) {
        shell_exec('stty -echo');
    }
    $value = rtrim((string) fgets(STDIN), "\r\n");
    if ($interactive) {
        shell_exec('stty echo');
        fwrite(STDOUT, "\n");
    }

    return $value;
}

switch ($command) {
    case 'doctor':
        $problems = 0;
        $check = static function (string $label, bool $ok, string $hint = '') use (&$problems): void {
            out(sprintf('  [%s] %s%s', $ok ? ' ok ' : 'FAIL', $label, $ok || $hint === '' ? '' : " — {$hint}"));
            $problems += $ok ? 0 : 1;
        };
        out('Vertex doctor');
        $check('PHP >= 8.2 (found ' . PHP_VERSION . ')', PHP_VERSION_ID >= 80200);
        foreach (['pdo', 'pdo_mysql', 'mbstring', 'openssl', 'json', 'ctype', 'fileinfo'] as $ext) {
            $check("ext-{$ext}", extension_loaded($ext), 'enable in cPanel > Select PHP Version > Extensions');
        }
        foreach (['gd' => 'PDF/image work (later phases)', 'intl' => 'currency/number formatting (later phases)', 'zip' => 'backups (later phases)', 'curl' => 'payment gateway & email APIs (later phases)'] as $ext => $why) {
            out(sprintf('  [%s] ext-%s (%s)', extension_loaded($ext) ? ' ok ' : 'warn', $ext, $why));
        }
        foreach (['storage/logs', 'storage/sessions', 'storage/cache', 'storage/uploads'] as $dir) {
            $check("{$dir} writable", is_dir(VERTEX_ROOT . "/{$dir}") && is_writable(VERTEX_ROOT . "/{$dir}"));
        }
        $check('.env present', is_file(VERTEX_ROOT . '/.env'), 'copy .env.example to .env');
        $check('APP_KEY set (>= 32 chars)', strlen((string) Config::get('app.key')) >= 32, 'php bin/console.php key:generate');
        $check('APP_DEBUG is false in production', !(Config::get('app.env') === 'production' && Config::get('app.debug') === true));
        try {
            Database::one('SELECT 1');
            $check('database connection', true);
        } catch (Throwable $e) {
            $check('database connection', false, $e->getMessage());
        }
        $isProd = Config::get('app.env') === 'production';
        $warn = static function (string $label, bool $ok, string $hint = '') use (&$warnings): void {
            out(sprintf('  [%s] %s%s', $ok ? ' ok ' : 'warn', $label, $ok || $hint === '' ? '' : " — {$hint}"));
            $warnings += $ok ? 0 : 1;
        };
        $warnings = 0;
        out('Production readiness (warnings do not fail the command but must be reviewed before launch):');
        $url = (string) Config::get('app.url', '');
        $warn('APP_ENV is production', $isProd, 'this is a ' . (string) Config::get('app.env') . ' environment');
        $warn('APP_URL uses https://', str_starts_with($url, 'https://'), 'cookies and links must be HTTPS in production');
        $warn('APP_DEBUG is off', Config::get('app.debug') !== true);
        $warn('Mail driver is smtp (log/none cannot send real email)', \Vertex\Services\Mailer::driver() === 'smtp', 'invitations, resets and reminder emails will not be delivered');
        $warn('Uploads are scanned (UPLOAD_SCANNER is not none)', (string) Config::get('uploads.scanner.driver', 'none') !== 'none', 'uploads are stored without a malware scan');
        $docroot = realpath(VERTEX_ROOT . '/public') ?: '';
        $warn('storage/ is outside the public folder', $docroot !== '' && !str_starts_with((string) realpath(VERTEX_ROOT . '/storage'), $docroot));
        $envPerm = is_file(VERTEX_ROOT . '/.env') ? (fileperms(VERTEX_ROOT . '/.env') & 0007) : 0;
        $warn('.env is not world-readable', $envPerm === 0, 'chmod 600 .env');
        try {
            $sch = \Vertex\Services\RenewalScheduler::lastRun();
            $warn('Scheduled task has run in the last 36 hours', \Vertex\Services\RenewalScheduler::healthy(), $sch['at'] === null ? 'it has never run: add the cron job' : 'last run ' . $sch['hours'] . ' hours ago');
            $demo = (int) Database::one("SELECT COUNT(*) AS c FROM users WHERE email LIKE '%@example.test'")['c'];
            $warn('No development seed accounts (@example.test) exist', $demo === 0, "{$demo} seeded account(s): remove them before launch");
            $owners = (int) Database::one("SELECT COUNT(*) AS c FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id WHERE r.slug = 'super_admin' AND u.status = 'active'")['c'];
            $warn('At least one active Super Admin exists', $owners >= 1, 'php bin/console.php user:create-super-admin');
        } catch (Throwable) {
            $warn('Database checks', false, 'migrate first');
        }
        $bk = \Vertex\Services\BackupService::latest();
        $warn('A backup exists and is recent', $bk['path'] !== null && $bk['hours'] <= (int) Config::get('backup.stale_hours', 36), $bk['path'] === null ? 'no backup has ever been made: backups are NOT operational' : 'newest backup is ' . $bk['hours'] . ' hours old');
        out($warnings === 0 ? 'No production warnings.' : "{$warnings} production warning(s).");
        out($problems === 0 ? 'All required checks passed.' : "{$problems} required check(s) failed.");
        exit($problems === 0 ? 0 : 1);

    case 'schedule:run':
        $r = \Vertex\Services\RenewalScheduler::run();
        if (!$r['ran']) {
            out('Another run is in progress; nothing done.');
            break;
        }
        out(sprintf('Scheduler: %d status move(s), %d reminder(s), %d commission release(s), %d error(s).', $r['advanced'], $r['reminders'], $r['commission_releases'], $r['errors']));
        exit($r['errors'] === 0 ? 0 : 1);

    case 'schedule:status':
        $r = \Vertex\Services\RenewalScheduler::lastRun();
        out($r['at'] === null ? 'The scheduled task has NEVER run. Renewal stages and reminders are NOT active until cron runs it.' : sprintf('Last run %s UTC (%d hour(s) ago), %s. %s', $r['at'], $r['hours'], $r['ok'] ? 'ok' : 'with errors', \Vertex\Services\RenewalScheduler::healthy() ? '' : 'It is overdue: check the cron job.'));
        break;

    case 'backup:create':
        try {
            $keep = isset($options['keep']) ? (int) $options['keep'] : (int) Config::get('backup.keep', 0);
            $b = \Vertex\Services\BackupService::create($options['dir'] ?? null, $keep > 0 ? $keep : null);
            $v = \Vertex\Services\BackupService::verify($b['path']);
        } catch (Throwable $e) {
            fail('Backup FAILED: ' . $e->getMessage());
        }
        out(sprintf('Backup written: %s (%d bytes, %d tables, %d rows, %d upload files).', $b['path'], $b['bytes'], $b['tables'], $b['rows'], $b['uploads']));
        out('SHA-256: ' . $b['sha256']);
        if (!$v['ok']) {
            fail('Verification FAILED: ' . implode(' ', $v['problems']));
        }
        out('Verification passed. Copy this file off the server; a backup that exists only on this server is not a backup. Keep APP_KEY with it (it is NOT inside the file).');
        break;

    case 'backup:verify':
        $f = $options['file'] ?? fail('Usage: backup:verify --file=PATH');
        $v = \Vertex\Services\BackupService::verify($f);
        if (!$v['ok']) {
            fail('INVALID: ' . implode(' ', $v['problems']));
        }
        out(sprintf('Backup is intact: %s, %d tables, %d upload files, created %s.', basename($f), count((array) $v['manifest']['tables']), count((array) $v['manifest']['uploads']), $v['manifest']['created_at']));
        break;

    case 'backup:restore':
        $f = $options['file'] ?? fail('Usage: backup:restore --file=PATH --database=EMPTY_DB_NAME [--uploads-dir=PATH]');
        $db = $options['database'] ?? fail('Usage: backup:restore --file=PATH --database=EMPTY_DB_NAME [--uploads-dir=PATH]');
        try {
            $r = \Vertex\Services\BackupService::restore($f, $db, $options['uploads-dir'] ?? null);
        } catch (Throwable $e) {
            fail('Restore FAILED: ' . $e->getMessage());
        }
        out(sprintf('Restored into %s: %d tables, %d rows, %d upload files. Row counts %s the backup. Audit chain %s.', $db, $r['tables'], $r['rows'], $r['uploads'], $r['row_counts_match'] ? 'MATCH' : 'DO NOT MATCH', $r['audit_chain']));
        foreach ($r['problems'] as $p) {
            out('  PROBLEM: ' . $p);
        }
        exit($r['problems'] === [] ? 0 : 1);

    case 'backup:status':
        $l = \Vertex\Services\BackupService::latest();
        out($l['path'] === null ? 'There is NO backup in ' . \Vertex\Services\BackupService::dir() . '. Backups are not operational.' : sprintf('Newest backup: %s (%d hour(s) ago); %d file(s) kept. %s', basename($l['path']), $l['hours'], $l['count'], $l['hours'] > (int) Config::get('backup.stale_hours', 36) ? 'It is STALE: check the cron job.' : ''));
        break;

    case 'key:generate':
        out('APP_KEY=base64:' . base64_encode(random_bytes(32)));
        out('# Paste the line above into .env. Changing the key later signs out all users.');
        break;

    case 'migrate':
        $ran = (new Migrator(VERTEX_ROOT . '/database/migrations'))->migrate();
        out($ran === [] ? 'Nothing to migrate.' : 'Applied: ' . implode(', ', $ran));
        break;

    case 'migrate:status':
        foreach ((new Migrator(VERTEX_ROOT . '/database/migrations'))->status() as $row) {
            out(sprintf('  %-10s %s', $row['status'], $row['migration']));
        }
        break;

    case 'permissions:sync':
        $reset = in_array('--reset-roles', $argv, true);
        if ($reset) {
            fwrite(STDOUT, "This restores the config default grants for every non-locked system role, discarding edits made in the admin UI.\nType RESET to continue: ");
            if (trim((string) fgets(STDIN)) !== 'RESET') {
                fail('Aborted.');
            }
        }
        $r = PermissionSync::run($reset);
        out("Synced {$r['roles']} roles, {$r['permissions']} permissions ({$r['new_permissions']} new), {$r['grants']} grants in total." . ($reset ? ' Role defaults restored.' : ''));
        break;

    case 'permissions:matrix':
        $roles = Config::get('permissions.roles');
        $slugs = array_keys($roles);
        out('| Permission | ' . implode(' | ', array_map(static fn ($s) => $roles[$s]['name'], $slugs)) . ' |');
        out('|---|' . str_repeat(':---:|', count($slugs)));
        foreach (Config::get('permissions.catalogue') as $module => $items) {
            out('| **' . ucfirst($module) . '** |' . str_repeat(' |', count($slugs)));
            foreach ($items as $key => $desc) {
                $cells = array_map(static fn ($s) => in_array($key, $roles[$s]['permissions'], true) ? '✔' : '', $slugs);
                out("| `{$key}` — {$desc} | " . implode(' | ', $cells) . ' |');
            }
        }
        break;

    case 'user:create-super-admin':
    case 'user:create-admin': // Phase 0 name, kept as an alias
        $email = mb_strtolower(trim($options['email'] ?? ''));
        $name = trim($options['name'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $name === '') {
            fail('Usage: php bin/console.php user:create-super-admin --email=you@example.com --name="Full Name"');
        }
        $repo = new UserRepository();
        if ($repo->findByEmail($email) !== null) {
            fail("A user with {$email} already exists.");
        }
        $existing = (int) Database::one("SELECT COUNT(*) AS c FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE r.slug = 'super_admin'")['c'];
        if ($existing > 0 && !in_array('--additional', $argv, true)) {
            fail('A Super Admin already exists. Pass --additional only if you really need another owner account.');
        }
        $password = prompt_hidden('Password (min 12 chars): ');
        $problems = PasswordPolicy::check($password, $email, $name);
        if ($problems !== []) {
            fail(implode("\n", $problems));
        }
        if (!hash_equals($password, prompt_hidden('Repeat password: '))) {
            fail('Passwords do not match.');
        }
        $id = $repo->create($name, $email, password_hash($password, PASSWORD_DEFAULT), 'active', ['super_admin'], null, null, true);
        Audit::record('user.created', 'user', $id, [], ['email' => $email, 'roles' => ['super_admin'], 'status' => 'active'], ['via' => 'console'], null, 'cli');
        out("Super Admin created (id {$id}). Sign in, then change nothing else here: manage everyone else in the admin UI.");
        break;

    case 'user:set-password':
        $email = mb_strtolower(trim($options['email'] ?? ''));
        $repo = new UserRepository();
        $user = $repo->findByEmail($email);
        if ($user === null) {
            fail('No user with that email.');
        }
        if (in_array($user['status'], ['deactivated', 'archived'], true)) {
            fail("Account is {$user['status']}; reactivate it in the admin UI first.");
        }
        $password = prompt_hidden('New password (min 12 chars): ');
        $problems = PasswordPolicy::check($password, $email, (string) $user['name']);
        if ($problems !== []) {
            fail(implode("\n", $problems));
        }
        if (!hash_equals($password, prompt_hidden('Repeat password: '))) {
            fail('Passwords do not match.');
        }
        Database::transaction(static function () use ($repo, $user, $password): void {
            $repo->setPassword((int) $user['id'], password_hash($password, PASSWORD_DEFAULT));
            Database::run("UPDATE users SET status = IF(status = 'pending', 'active', status) WHERE id = ?", [$user['id']]);
            Audit::record('user.password_set_console', 'user', (int) $user['id'], [], [], ['via' => 'console'], null, 'cli');
        });
        out('Password set; all existing sessions for this user were signed out.');
        break;

    case 'db:seed-dev':
        $r = DevSeeder::run();
        out("Seeded fictional data: {$r['created']} users, {$r['projects']} projects (existing rows were left alone).");
        out('All seeded users are @example.test and share this one-time password (not stored anywhere):');
        out('  ' . $r['password']);
        break;

    case 'audit:verify':
        $r = Audit::verify();
        if ($r['ok']) {
            out("Audit chain intact ({$r['checked']} rows verified).");
            break;
        }
        fail("AUDIT CHAIN BROKEN at row {$r['broken_at']} after {$r['checked']} valid rows: {$r['reason']}");

    default:
        preg_match('#/\*(.*?)\*/#s', (string) file_get_contents(__FILE__), $help);
        out(trim(preg_replace('#^ \* ?#m', '', $help[1] ?? '')));
        exit($command === 'help' ? 0 : 1);
}
