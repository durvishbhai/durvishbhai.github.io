<?php

declare(strict_types=1);

use Vertex\Core\Env;

/*
 * Backups (Phase 7). Nothing runs by itself: `php bin/console.php backup:create` must be scheduled (cron) and a restore must be tested before anyone
 * says backups are operational. See docs/15-PHASE7-RENEWALS-OPERATIONS.md.
 */
return [
    // Keep this OUTSIDE the web root and copy the files off the server too. Default: <app>/storage/backups (denied by storage/.htaccess).
    'dir' => (string) (Env::get('BACKUP_DIR', '') ?: dirname(__DIR__) . '/storage/backups'),
    // backup:create --keep defaults to this many files; 0 = never delete
    'keep' => (int) Env::get('BACKUP_KEEP', 14),
    // `doctor` and the dashboard call a backup stale after this many hours
    'stale_hours' => (int) Env::get('BACKUP_STALE_HOURS', 36),
];
