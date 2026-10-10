<?php

declare(strict_types=1);

/*
 * Vertex console. Usage: php bin/console.php <command> [options]
 *
 *   doctor                    Check PHP version, extensions, writable dirs, config and DB connectivity
 *   key:generate              Print a new APP_KEY (paste into .env)
 *   migrate                   Apply pending database migrations
 *   migrate:status            List migrations and detect drift
 *   permissions:sync          Sync roles/permissions from config/permissions.php into the database
 *   permissions:matrix        Print the role/permission matrix as Markdown (generated from config; no DB needed)
 *   user:create-admin         Create the first Admin (--email=, --name=; password is prompted, never an argument)
 *   audit:verify              Verify the audit-log hash chain
 */

if (PHP_SAPI !== 'cli') {
    exit('CLI only.');
}

require dirname(__DIR__) . '/bootstrap.php';

use Vertex\Core\Config;
use Vertex\Core\Database;
use Vertex\Repositories\UserRepository;
use Vertex\Services\Audit;
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
        out($problems === 0 ? 'All required checks passed.' : "{$problems} required check(s) failed.");
        exit($problems === 0 ? 0 : 1);

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
        $r = PermissionSync::run();
        out("Synced {$r['roles']} roles, {$r['permissions']} permissions, {$r['grants']} grants.");
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

    case 'user:create-admin':
        $email = mb_strtolower(trim($options['email'] ?? ''));
        $name = trim($options['name'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $name === '') {
            fail('Usage: php bin/console.php user:create-admin --email=you@example.com --name="Full Name"');
        }
        $repo = new UserRepository();
        if ($repo->findByEmail($email) !== null) {
            fail("A user with {$email} already exists.");
        }
        $password = prompt_hidden('Password (min 12 chars): ');
        if (mb_strlen($password) < 12) {
            fail('Password must be at least 12 characters.');
        }
        if (!hash_equals($password, prompt_hidden('Repeat password: '))) {
            fail('Passwords do not match.');
        }
        $id = $repo->create($name, $email, password_hash($password, PASSWORD_DEFAULT), 'active', ['admin']);
        Audit::record('user.created', 'user', $id, [], ['email' => $email, 'roles' => ['admin']], ['via' => 'console'], null, 'cli');
        out("Admin created (id {$id}).");
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
