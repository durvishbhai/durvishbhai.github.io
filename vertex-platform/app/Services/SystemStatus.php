<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Config;
use Vertex\Core\Database;

/**
 * A read-only health report for administrators. It states only what it can verify and never prints a secret (no key, password, host or user name).
 * "Not configured" is shown as a warning, never as a green tick: a missing scheduler or backup must be visible, not assumed.
 */
final class SystemStatus
{
    /** @return list<array{group:string,label:string,state:'ok'|'warn'|'info',detail:string}> */
    public static function report(): array
    {
        $rows = [];
        $add = static function (string $group, string $label, string $state, string $detail) use (&$rows): void {
            $rows[] = ['group' => $group, 'label' => $label, 'state' => $state, 'detail' => $detail];
        };
        $env = (string) Config::get('app.env');
        $add('Application', 'Environment', $env === 'production' ? 'ok' : 'warn', $env === 'production' ? 'production' : "{$env} (not a production setting)");
        $add('Application', 'Debug mode', Config::get('app.debug') === true ? 'warn' : 'ok', Config::get('app.debug') === true ? 'ON: error pages show details' : 'off');
        $add('Application', 'HTTPS', str_starts_with((string) Config::get('app.url', ''), 'https://') ? 'ok' : 'warn', str_starts_with((string) Config::get('app.url', ''), 'https://') ? 'APP_URL is https' : 'APP_URL is not https');
        $add('Application', 'PHP', version_compare(PHP_VERSION, '8.2.0', '>=') ? 'ok' : 'warn', PHP_VERSION);
        try {
            $add('Database', 'Server', 'info', (string) Database::one('SELECT VERSION() AS v')['v']);
            $pending = array_filter((new Migrator(VERTEX_ROOT . '/database/migrations'))->status(), static fn (array $s): bool => $s['status'] !== 'applied');
            $add('Database', 'Migrations', $pending === [] ? 'ok' : 'warn', $pending === [] ? 'all applied' : count($pending) . ' not applied or changed: run migrate');
        } catch (\Throwable) {
            $add('Database', 'Connection', 'warn', 'not reachable');
        }
        $sch = RenewalScheduler::lastRun();
        $add('Scheduled task', 'Renewal stages, reminders, commission hold periods', RenewalScheduler::healthy() ? 'ok' : 'warn',
            $sch['at'] === null ? 'NEVER RUN: these features are not active until cron runs "php bin/console.php schedule:run"' : 'last run ' . $sch['at'] . ' UTC (' . $sch['hours'] . ' h ago)' . ($sch['ok'] ? '' : ', with errors'));
        $bk = BackupService::latest();
        $stale = (int) Config::get('backup.stale_hours', 36);
        $add('Backups', 'Newest backup', $bk['path'] !== null && $bk['hours'] <= $stale ? 'ok' : 'warn', $bk['path'] === null ? 'NO BACKUP EXISTS: backups are not operational' : basename($bk['path']) . ' (' . $bk['hours'] . ' h ago)' . ($bk['hours'] > $stale ? ', STALE' : ''));
        $add('Backups', 'Zip extension (needed to create backups)', class_exists(\ZipArchive::class) ? 'ok' : 'warn', class_exists(\ZipArchive::class) ? 'available' : 'missing');
        $driver = Mailer::driver();
        $add('Email', 'Delivery', $driver === 'smtp' ? 'ok' : 'warn', $driver === 'smtp' ? 'SMTP configured' : ($driver === 'none' ? 'not configured: invitations, password resets and email reminders are NOT sent' : "driver '{$driver}' is for development only"));
        $add('Email', 'Client renewal reminder emails', Config::get('renewals.client_email') ? 'info' : 'info', Config::get('renewals.client_email') ? 'enabled (sent only if SMTP works; every attempt is recorded)' : 'off: clients are reminded in the portal only');
        $scan = (string) Config::get('uploads.scanner.driver', 'none');
        $add('Files', 'Malware scanning', $scan !== 'none' ? 'ok' : 'warn', $scan !== 'none' ? "scanner driver '{$scan}'" : 'none: uploads are stored as "not scanned"');
        $root = FileStore::root();
        $add('Files', 'Upload folder', is_dir($root) && is_writable($root) ? 'ok' : 'warn', is_dir($root) && is_writable($root) ? 'writable' : 'missing or not writable');
        $add('Payments', 'Online payment gateway', 'info', 'Not built. Payments are recorded by staff; nothing is charged or confirmed automatically.');
        $add('Payments', 'Quotations and invoices', 'info', 'Not built. Numbers are recorded as references to documents issued elsewhere.');

        return $rows;
    }
}
