<?php

declare(strict_types=1);

namespace Vertex\Services;

use PDO;
use Vertex\Core\Config;
use Vertex\Core\Database;

/**
 * Portable backup and restore with no dependency on mysqldump, tar or shell access (shared hosts often have none of them).
 *
 * A backup is ONE zip file holding (1) `database.sql`, a consistent snapshot of every table (taken inside a single REPEATABLE READ transaction, so the
 * tables agree with each other), written as one statement per line; (2) `uploads/...` the private upload store; (3) `manifest.json` with the migration
 * list, row counts per table and a SHA-256 of every file. It does NOT contain `.env`: the APP_KEY and database password are secrets that belong in a
 * different place than the data they protect. Keep a copy of APP_KEY with the backup set: without it encrypted payout details cannot be read.
 *
 * Nothing here runs by itself. A backup exists only after someone (or cron) ran `backup:create`, and it is trustworthy only after `backup:verify` and a
 * test `backup:restore` into an empty database.
 */
final class BackupService
{
    private const FORMAT = 1;
    private const BATCH = 400;
    private const MAX_STATEMENT_BYTES = 524288;

    public static function dir(?string $override = null): string
    {
        return $override !== null && $override !== '' ? rtrim($override, '/') : (string) Config::get('backup.dir', VERTEX_ROOT . '/storage/backups');
    }

    /**
     * @return array{path:string,bytes:int,tables:int,rows:int,uploads:int,sha256:string}
     */
    public static function create(?string $dir = null, ?int $keep = null): array
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('The PHP zip extension is required for backups (enable it in your hosting control panel).');
        }
        $dir = self::dir($dir);
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create the backup directory {$dir}.");
        }
        if (!is_writable($dir)) {
            throw new \RuntimeException("The backup directory {$dir} is not writable.");
        }
        $stamp = gmdate('Ymd-His');
        $final = "{$dir}/vis-backup-{$stamp}.zip";
        $tmp = tempnam(sys_get_temp_dir(), 'vis-dump-');
        if ($tmp === false) {
            throw new \RuntimeException('Cannot create a temporary file.');
        }
        try {
            $info = self::dumpDatabase($tmp);
            $zip = new \ZipArchive();
            $partial = $final . '.partial';
            if ($zip->open($partial, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Cannot create the backup archive.');
            }
            $manifest = ['format' => self::FORMAT, 'created_at' => gmdate('c'), 'app' => (string) Config::get('app.name', 'Krazy'), 'migrations' => $info['migrations'], 'tables' => $info['tables'],
                'sql_sha256' => hash_file('sha256', $tmp), 'uploads' => []];
            $zip->addFile($tmp, 'database.sql');
            $zip->close();
            $uploads = self::addUploads($partial, $manifest);
            $zip = new \ZipArchive();
            $zip->open($partial);
            $zip->addFromString('manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
            $zip->close();
            if (!rename($partial, $final)) {
                throw new \RuntimeException('Cannot finish the backup file.');
            }
            @chmod($final, 0640);
        } finally {
            @unlink($tmp);
            if (isset($partial) && is_file($partial)) {
                @unlink($partial);
            }
        }
        if ($keep !== null && $keep > 0) {
            self::prune($dir, $keep);
        }

        return ['path' => $final, 'bytes' => (int) filesize($final), 'tables' => count($info['tables']), 'rows' => array_sum($info['tables']), 'uploads' => $uploads, 'sha256' => hash_file('sha256', $final)];
    }

    /**
     * A complete, restore-ready SQL file of the database (structure and data, one consistent snapshot) written to a private temporary file. The caller sends it to an
     * authorised person and then deletes it with unlink().
     *
     * @return array{path:string,tables:int,rows:int,bytes:int}
     */
    public static function sqlDump(): array
    {
        $dir = rtrim((string) Config::get('uploads.root', VERTEX_ROOT . '/storage/uploads'), '/');
        $dir = dirname($dir) . '/cache';
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException('The temporary folder is not writable.');
        }
        $path = $dir . '/sqldump-' . bin2hex(random_bytes(12)) . '.sql';
        try {
            $info = self::dumpDatabase($path);
        } catch (\Throwable $e) {
            @unlink($path);
            throw $e;
        }
        @chmod($path, 0600);

        return ['path' => $path, 'tables' => count($info['tables']), 'rows' => array_sum($info['tables']), 'bytes' => (int) filesize($path)];
    }

    // ------------------------------------------------------------------------------------------------ Administration -> Backup

    /** The biggest file this server accepts in an upload form (PHP's own limits; there is no application cap for backups). */
    public static function uploadLimit(): int
    {
        $bytes = static function (string $v): int {
            $v = trim($v);
            $n = (int) $v;
            return match (strtolower(substr($v, -1))) {
                'g' => $n * 1024 ** 3,
                'm' => $n * 1024 ** 2,
                'k' => $n * 1024,
                default => $n,
            };
        };
        $lim = array_filter([$bytes((string) ini_get('upload_max_filesize')), $bytes((string) ini_get('post_max_size'))], static fn (int $b): bool => $b > 0);

        return $lim === [] ? 0 : min($lim);
    }

    private static function cacheDir(): string
    {
        $dir = dirname(rtrim((string) Config::get('uploads.root', VERTEX_ROOT . '/storage/uploads'), '/')) . '/cache';
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException('The temporary folder is not writable.');
        }

        return $dir;
    }

    /**
     * Every uploaded file as one .zip: the private upload store (deliverables, payment proofs, enquiry attachments) and the website pictures, each with a SHA-256 in
     * manifest.json. Invoices, quotations, receipts and agreements are not files: they are produced from the database, so they are inside the .sql backup.
     *
     * @return array{path:string,files:int,bytes:int}
     */
    public static function filesZip(): array
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('The PHP zip extension is required (enable it in your hosting control panel).');
        }
        $path = self::cacheDir() . '/files-' . bin2hex(random_bytes(12)) . '.zip';
        try {
            $manifest = ['format' => self::FORMAT, 'type' => 'files', 'created_at' => gmdate('c'), 'app' => (string) Config::get('app.name', 'Krazy'), 'uploads' => []];
            $n = self::addUploads($path, $manifest);
            $zip = new \ZipArchive();
            if ($zip->open($path, \ZipArchive::CREATE) !== true) {
                throw new \RuntimeException('Cannot finish the archive.');
            }
            $zip->addFromString('manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
            $zip->close();
        } catch (\Throwable $e) {
            @unlink($path);
            throw $e;
        }
        @chmod($path, 0600);

        return ['path' => $path, 'files' => $n, 'bytes' => (int) filesize($path)];
    }

    private const BAD_EXTENSIONS = ['php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'pht', 'phps', 'phar', 'pl', 'py', 'cgi', 'sh', 'asp', 'aspx', 'jsp', 'htaccess', 'htpasswd', 'ini', 'exe', 'bat', 'cmd', 'com', 'js', 'mjs', 'html', 'htm', 'svg', 'xml'];

    /** @return array{0:string,1:string}|null [target root, relative path inside it] for a safe archive path, or null when it must be refused */
    private static function uploadTarget(string $rel): ?array
    {
        if ($rel === '' || strlen($rel) > 240 || str_contains($rel, "\0") || str_contains($rel, '\\') || str_starts_with($rel, '/')) {
            return null;
        }
        $segments = explode('/', $rel);
        foreach ($segments as $seg) {
            if (!preg_match('/^[A-Za-z0-9_\-][A-Za-z0-9_\-.]*$/', $seg) || str_contains($seg, '..')) {
                return null;
            }
        }
        $ext = strtolower((string) pathinfo((string) end($segments), PATHINFO_EXTENSION));
        if (in_array($ext, self::BAD_EXTENSIONS, true) || preg_match('/\.(php|phtml|phar)/i', $rel)) {
            return null;
        }
        if ($segments[0] === 'website-media') {
            if (count($segments) < 2 || !in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
                return null;
            }
            $public = (defined('VERTEX_PUBLIC') ? (string) VERTEX_PUBLIC : VERTEX_ROOT . '/public') . '/uploads/cms';

            return [$public, implode('/', array_slice($segments, 1))];
        }

        return [rtrim(FileStore::root(), '/'), $rel];
    }

    /**
     * Puts the files of a files backup back (a full .zip backup made by backup:create works too). Every path is checked, every file is verified against its checksum
     * before it replaces anything, website pictures must really be images, and nothing outside the two upload folders can be written. Files that are not in the
     * archive are left alone.
     *
     * @return array{restored:int,unchanged:int,problems:list<string>}
     */
    public static function restoreFiles(string $file): array
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('The PHP zip extension is required (enable it in your hosting control panel).');
        }
        clearstatcache(true, $file);
        $zip = new \ZipArchive();
        if (!is_file($file) || (int) filesize($file) === 0 || $zip->open($file) !== true) { // a 0-byte file is not an archive (and ZipArchive deprecates opening one)
            throw new \RuntimeException('This is not a valid .zip file.');
        }
        $raw = $zip->getFromName('manifest.json');
        $manifest = $raw === false ? null : json_decode($raw, true);
        if (!is_array($manifest) || (int) ($manifest['format'] ?? 0) !== self::FORMAT || !isset($manifest['uploads']) || !is_array($manifest['uploads'])) {
            $zip->close();
            throw new \RuntimeException('This .zip is not a backup made by this system (manifest.json is missing or unreadable).');
        }
        $total = 0;
        foreach ($manifest['uploads'] as $rel => $hash) {
            $st = $zip->statName('uploads/' . $rel);
            $total += $st === false ? 0 : (int) $st['size'];
        }
        if (count($manifest['uploads']) > 200000 || $total > 6 * 1024 ** 3) {
            $zip->close();
            throw new \RuntimeException('The archive is larger than the restore limit (200,000 files or 6 GB).');
        }
        $restored = 0;
        $same = 0;
        $problems = [];
        foreach ($manifest['uploads'] as $rel => $hash) {
            $rel = (string) $rel;
            $t = self::uploadTarget($rel);
            if ($t === null || !preg_match('/^[a-f0-9]{64}$/', (string) $hash)) {
                $problems[] = 'Refused an unsafe or unexpected file: ' . mb_substr($rel, 0, 80);
                continue;
            }
            [$root, $sub] = $t;
            $target = $root . '/' . $sub;
            if (is_file($target) && hash_equals((string) $hash, (string) hash_file('sha256', $target))) {
                $same++;
                continue;
            }
            $in = $zip->getStream('uploads/' . $rel);
            if ($in === false) {
                $problems[] = 'Missing in the archive: ' . $rel;
                continue;
            }
            if (!is_dir(dirname($target)) && !@mkdir(dirname($target), 0750, true) && !is_dir(dirname($target))) {
                fclose($in);
                $problems[] = 'Cannot create the folder for ' . $rel;
                continue;
            }
            $part = $target . '.part-' . bin2hex(random_bytes(4));
            $out = fopen($part, 'wb');
            if ($out === false) {
                fclose($in);
                $problems[] = 'Cannot write ' . $rel;
                continue;
            }
            stream_copy_to_stream($in, $out);
            fclose($in);
            fclose($out);
            $ok = hash_equals((string) $hash, (string) hash_file('sha256', $part));
            if ($ok && $root !== rtrim(FileStore::root(), '/') && @getimagesize($part) === false) {
                $ok = false; // a website picture must really be an image
            }
            if (!$ok) {
                @unlink($part);
                $problems[] = 'Damaged or not an image, skipped: ' . $rel;
                continue;
            }
            @chmod($part, 0640);
            if (!rename($part, $target)) {
                @unlink($part);
                $problems[] = 'Cannot place ' . $rel;
                continue;
            }
            $restored++;
        }
        $zip->close();

        return ['restored' => $restored, 'unchanged' => $same, 'problems' => $problems];
    }

    /**
     * Reads a backup .sql made by this system and checks every line BEFORE anything is touched: the header, the end marker, and that each statement is one of the few
     * kinds a backup contains (SET, DROP TABLE IF EXISTS, CREATE TABLE, INSERT ... VALUES). Anything else (GRANT, CREATE USER, DROP DATABASE, LOAD DATA, ...) refuses the file.
     *
     * @return array{statements:int,tables:list<string>}
     */
    public static function inspectSql(string $file): array
    {
        $in = is_file($file) ? fopen($file, 'rb') : false;
        if ($in === false) {
            throw new \RuntimeException('The file cannot be read.');
        }
        $first = true;
        $n = 0;
        $line = 0;
        $tables = [];
        $end = false;
        try {
            while (($l = fgets($in)) !== false) {
                $line++;
                $l = rtrim($l, "\r\n");
                if ($first) {
                    $first = false;
                    if (!preg_match('/^-- Krazy backup, format ' . self::FORMAT . ',/', $l)) {
                        throw new \RuntimeException('This is not a backup made by this system (the first line is not the Krazy backup header). Files exported from phpMyAdmin can be imported there instead.');
                    }
                    continue;
                }
                if ($l === '' || (str_starts_with($l, '--') && $l !== '-- END OF BACKUP')) {
                    continue;
                }
                if ($l === '-- END OF BACKUP') {
                    $end = true;
                    continue;
                }
                if ($end) {
                    throw new \RuntimeException("Unexpected content after the end of the backup (line {$line}).");
                }
                if (preg_match('/^SET (NAMES utf8mb4|time_zone = \'\+00:00\'|FOREIGN_KEY_CHECKS = [01]|UNIQUE_CHECKS = [01]);$/', $l)) {
                    continue;
                }
                if (preg_match('/^DROP TABLE IF EXISTS `([A-Za-z0-9_]{1,64})`;$/', $l, $m)) {
                    $tables[$m[1]] = true;
                    $n++;
                    continue;
                }
                if (preg_match('/^CREATE TABLE `([A-Za-z0-9_]{1,64})` \(.*;$/', $l) || preg_match('/^INSERT INTO `[A-Za-z0-9_]{1,64}` \(`[^`]+`.*\) VALUES \(.*\);$/', $l)) {
                    $n++;
                    continue;
                }
                throw new \RuntimeException("The file contains a statement a backup never contains (line {$line}). Nothing was changed.");
            }
        } finally {
            fclose($in);
        }
        if ($line < 3 || !$end) {
            throw new \RuntimeException('The backup is incomplete (its end marker is missing): it was probably cut off while it was saved. Nothing was changed.');
        }
        if (!isset($tables['users']) || !isset($tables['schema_migrations'])) {
            throw new \RuntimeException('The backup does not contain the core tables (users, schema_migrations). Nothing was changed.');
        }

        return ['statements' => $n, 'tables' => array_keys($tables)];
    }

    /** True when the line holds exactly one statement: outside quoted text the only semicolon is the last character. */
    private static function oneStatement(string $line): bool
    {
        $bare = preg_replace("/'(?:[^'\\\\]|\\\\.|'')*'/s", "''", $line);
        $bare = preg_replace('/`[^`]*`/', '``', (string) $bare);

        return $bare !== null && substr_count($bare, ';') === 1 && str_ends_with($bare, ';');
    }

    /** Executes an inspected backup on its own connection: stacked statements are switched off, so one line can only ever run as one statement. */
    private static function applySql(string $file): void
    {
        $c = (array) Config::get('database');
        $pdo = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $c['host'], $c['port'], $c['database'], $c['charset']), (string) $c['username'], (string) $c['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::MYSQL_ATTR_MULTI_STATEMENTS => false,
        ]);
        $pdo->exec("SET NAMES utf8mb4");
        $pdo->exec("SET time_zone = '+00:00'");
        $pdo->exec("SET sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $keep = [];
        foreach (self::inspectSql($file)['tables'] as $t) {
            $keep[$t] = true;
        }
        // tables that exist now but are not part of the backup (the site was upgraded after it was made) are removed, so the restored database is exactly the backup
        foreach ($pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN) as $t) {
            if (!isset($keep[(string) $t])) {
                $pdo->exec('DROP TABLE IF EXISTS `' . str_replace('`', '``', (string) $t) . '`');
            }
        }
        $in = fopen($file, 'rb');
        try {
            while (($l = fgets($in)) !== false) {
                $l = rtrim($l, "\r\n");
                if ($l === '' || str_starts_with($l, '--')) {
                    continue;
                }
                $pdo->exec($l);
            }
        } finally {
            fclose($in);
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    /**
     * Replaces the whole database with a backup .sql. Safety: the file is fully checked first; a copy of the CURRENT database is saved in storage/backups before anything
     * changes; if the restore fails half way, that copy is put back. Afterwards pending migrations and permissions are brought up to date.
     *
     * @return array{statements:int,tables:int,safety_copy:string,migrations:list<string>,rolled_back:bool}
     */
    public static function restoreSql(string $file): array
    {
        @set_time_limit(0);
        @ignore_user_abort(true);
        $info = self::inspectSql($file);
        $lock = fopen(self::cacheDir() . '/restore.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            throw new \RuntimeException('Another restore is running. Wait for it to finish.');
        }
        try {
            $dir = self::dir();
            if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
                throw new \RuntimeException('The backup folder cannot be created, so no safety copy can be kept. Nothing was changed.');
            }
            $dump = self::sqlDump();
            $safety = $dir . '/pre-restore-' . gmdate('Ymd-His') . '.sql';
            if (!@rename($dump['path'], $safety) && !(@copy($dump['path'], $safety) && @unlink($dump['path']))) {
                @unlink($dump['path']);
                throw new \RuntimeException('The safety copy of the current data could not be saved. Nothing was changed.');
            }
            @chmod($safety, 0600);
            $old = glob($dir . '/pre-restore-*.sql') ?: [];
            rsort($old);
            foreach (array_slice($old, 5) as $f) {
                @unlink($f);
            }
            $rolled = false;
            try {
                self::applySql($file);
            } catch (\Throwable $e) {
                \Vertex\Core\Logger::error('SQL restore failed, putting the previous data back', ['error' => $e->getMessage()]);
                try {
                    self::applySql($safety);
                    $rolled = true;
                } catch (\Throwable $e2) {
                    \Vertex\Core\Logger::error('Rolling back the SQL restore failed', ['error' => $e2->getMessage()]);
                    throw new \RuntimeException('The restore failed and the previous data could not be put back automatically. Your safety copy is saved as ' . basename($safety) . ' in vertex/storage/backups: import it with phpMyAdmin.');
                }
                throw new \RuntimeException('The restore failed (' . $e->getMessage() . '). Your previous data was put back unchanged.');
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        Database::disconnect();
        $migrations = [];
        try {
            $migrations = (new Migrator(VERTEX_ROOT . '/database/migrations'))->migrate();
            PermissionSync::run(false);
        } catch (\Throwable $e) {
            \Vertex\Core\Logger::error('Post-restore migration failed', ['error' => $e->getMessage()]);
        }

        return ['statements' => $info['statements'], 'tables' => count($info['tables']), 'safety_copy' => basename($safety), 'migrations' => $migrations, 'rolled_back' => false];
    }

    /** @return array{tables:array<string,int>,migrations:list<string>} */
    private static function dumpDatabase(string $file): array
    {
        $c = (array) Config::get('database');
        $pdo = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $c['host'], $c['port'], $c['database'], $c['charset']), (string) $c['username'], (string) $c['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
        $pdo->exec("SET time_zone = '+00:00'");
        $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
        $out = fopen($file, 'wb');
        if ($out === false) {
            throw new \RuntimeException('Cannot write the dump.');
        }
        $counts = [];
        try {
            fwrite($out, "-- Krazy backup, format " . self::FORMAT . ", " . gmdate('c') . "\nSET NAMES utf8mb4;\nSET time_zone = '+00:00';\nSET FOREIGN_KEY_CHECKS = 0;\nSET UNIQUE_CHECKS = 0;\n");
            $tables = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($tables as $table) {
                $q = '`' . str_replace('`', '``', (string) $table) . '`';
                $create = (string) $pdo->query("SHOW CREATE TABLE {$q}")->fetch(PDO::FETCH_NUM)[1];
                fwrite($out, "DROP TABLE IF EXISTS {$q};\n" . preg_replace('/\s*\n\s*/', ' ', $create) . ";\n");
                $n = 0;
                $stmt = $pdo->query("SELECT * FROM {$q}", PDO::FETCH_ASSOC);
                $cols = null;
                $batch = [];
                $size = 0;
                while (($row = $stmt->fetch()) !== false) {
                    $cols ??= '(' . implode(', ', array_map(static fn ($k): string => '`' . str_replace('`', '``', (string) $k) . '`', array_keys($row))) . ')';
                    $batch[] = '(' . implode(', ', array_map(static function ($v) use ($pdo): string {
                        if ($v === null) {
                            return 'NULL';
                        }
                        if (is_int($v) || is_float($v)) {
                            return (string) $v;
                        }
                        if (is_bool($v)) {
                            return $v ? '1' : '0';
                        }

                        return (string) $pdo->quote((string) $v); // escapes quotes, backslashes and line breaks, so one statement is always exactly one line
                    }, array_values($row))) . ')';
                    $n++;
                    $size += strlen((string) end($batch));
                    // keep every statement well under the server's max_allowed_packet (older servers default to 1-4 MB)
                    if (count($batch) >= self::BATCH || $size >= self::MAX_STATEMENT_BYTES) {
                        fwrite($out, "INSERT INTO {$q} {$cols} VALUES " . implode(', ', $batch) . ";\n");
                        $batch = [];
                        $size = 0;
                    }
                }
                if ($batch !== []) {
                    fwrite($out, "INSERT INTO {$q} {$cols} VALUES " . implode(', ', $batch) . ";\n");
                }
                $counts[(string) $table] = $n;
            }
            fwrite($out, "SET FOREIGN_KEY_CHECKS = 1;\n-- END OF BACKUP\n");
            $migrations = in_array('schema_migrations', $tables, true) ? array_map('strval', $pdo->query('SELECT migration FROM schema_migrations ORDER BY migration')->fetchAll(PDO::FETCH_COLUMN)) : [];
        } finally {
            fclose($out);
            $pdo->exec('ROLLBACK');
        }

        return ['tables' => $counts, 'migrations' => $migrations];
    }

    /** Adds the upload store to the archive in batches (a zip can hold many files open at once) and records a hash of each in the manifest. */
    private static function addUploads(string $zipPath, array &$manifest): int
    {
        // The private upload store, and the website's public pictures (public_html/uploads/cms) under the prefix "website-media/".
        $public = (defined('VERTEX_PUBLIC') ? (string) VERTEX_PUBLIC : VERTEX_ROOT . '/public') . '/uploads/cms';
        $files = [];
        foreach ([[FileStore::root(), ''], [$public, 'website-media/']] as [$root, $prefix]) {
            if (!is_dir($root)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::LEAVES_ONLY);
            foreach ($it as $f) {
                if ($f->isFile() && !$f->isLink() && ($prefix === '' || !str_starts_with($f->getFilename(), '.'))) {
                    $files[$prefix . ltrim(substr($f->getPathname(), strlen($root)), '/\\')] = $f->getPathname();
                }
            }
        }
        ksort($files);
        $n = 0;
        foreach (array_chunk($files, 150, true) as $chunk) {
            $zip = new \ZipArchive();
            if ($zip->open($zipPath, \ZipArchive::CREATE) !== true) {
                throw new \RuntimeException('Cannot reopen the backup archive.');
            }
            foreach ($chunk as $rel => $path) {
                $zip->addFile($path, 'uploads/' . $rel);
                $manifest['uploads'][$rel] = hash_file('sha256', $path);
                $n++;
            }
            $zip->close();
        }

        return $n;
    }

    private static function prune(string $dir, int $keep): void
    {
        $all = glob($dir . '/vis-backup-*.zip') ?: [];
        rsort($all);
        foreach (array_slice($all, $keep) as $old) {
            @unlink($old);
        }
    }

    /** @return array{ok:bool,problems:list<string>,manifest:?array<string,mixed>} */
    public static function verify(string $file): array
    {
        $problems = [];
        if (!class_exists(\ZipArchive::class)) {
            return ['ok' => false, 'problems' => ['The PHP zip extension is not available.'], 'manifest' => null];
        }
        $zip = new \ZipArchive();
        if (!is_file($file) || $zip->open($file, \ZipArchive::CHECKCONS) !== true) {
            return ['ok' => false, 'problems' => ['The file is missing or is not a valid backup archive.'], 'manifest' => null];
        }
        $raw = $zip->getFromName('manifest.json');
        $manifest = $raw === false ? null : json_decode($raw, true);
        if (!is_array($manifest) || ($manifest['format'] ?? null) !== self::FORMAT) {
            $zip->close();

            return ['ok' => false, 'problems' => ['The manifest is missing or from an unknown format.'], 'manifest' => null];
        }
        $sql = $zip->getStream('database.sql');
        if ($sql === false) {
            $problems[] = 'database.sql is missing.';
        } else {
            $ctx = hash_init('sha256');
            $tail = '';
            while (!feof($sql)) {
                $chunk = (string) fread($sql, 1 << 20);
                hash_update($ctx, $chunk);
                $tail = substr($tail . $chunk, -64);
            }
            fclose($sql);
            if (!hash_equals((string) $manifest['sql_sha256'], hash_final($ctx))) {
                $problems[] = 'database.sql does not match its recorded checksum (the file is damaged or was edited).';
            }
            if (!str_contains($tail, '-- END OF BACKUP')) {
                $problems[] = 'database.sql is incomplete (no end marker).';
            }
        }
        foreach ((array) ($manifest['uploads'] ?? []) as $rel => $hash) {
            $name = 'uploads/' . $rel;
            $s = $zip->getStream($name);
            if ($s === false) {
                $problems[] = "Upload missing from the archive: {$rel}";
                continue;
            }
            $ctx = hash_init('sha256');
            while (!feof($s)) {
                hash_update($ctx, (string) fread($s, 1 << 20));
            }
            fclose($s);
            if (!hash_equals((string) $hash, hash_final($ctx))) {
                $problems[] = "Upload does not match its checksum: {$rel}";
            }
        }
        $zip->close();

        return ['ok' => $problems === [], 'problems' => $problems, 'manifest' => $manifest];
    }

    /**
     * Restores into an EMPTY database (it refuses to touch one that already has tables: restore to a new database first, check it, then switch .env).
     * Uploads go to $uploadsDir, which must not exist or be empty. Returns the checks it ran.
     *
     * @return array{tables:int,rows:int,uploads:int,row_counts_match:bool,audit_chain:string,problems:list<string>}
     */
    public static function restore(string $file, string $database, ?string $uploadsDir = null): array
    {
        $check = self::verify($file);
        if (!$check['ok']) {
            throw new \RuntimeException('The backup failed verification: ' . implode(' ', $check['problems']));
        }
        $manifest = (array) $check['manifest'];
        $c = (array) Config::get('database');
        $pdo = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $c['host'], $c['port'], $database, $c['charset']), (string) $c['username'], (string) $c['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        if ($pdo->query('SHOW TABLES')->fetchAll() !== []) {
            throw new \RuntimeException("The database '{$database}' is not empty. Restore into a new, empty database.");
        }
        if ($uploadsDir !== null && is_dir($uploadsDir) && (glob(rtrim($uploadsDir, '/') . '/*') ?: []) !== []) {
            throw new \RuntimeException("The uploads directory '{$uploadsDir}' is not empty.");
        }
        $zip = new \ZipArchive();
        $zip->open($file);
        $stream = $zip->getStream('database.sql');
        $pdo->exec("SET NAMES utf8mb4; SET time_zone = '+00:00'");
        $pdo->exec("SET sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        while (($line = fgets($stream)) !== false) {
            $line = rtrim($line, "\r\n");
            if ($line === '' || str_starts_with($line, '--')) {
                continue;
            }
            $pdo->exec($line);
        }
        fclose($stream);

        $problems = [];
        $rows = 0;
        $match = true;
        foreach ((array) $manifest['tables'] as $table => $expected) {
            $q = '`' . str_replace('`', '``', (string) $table) . '`';
            $got = (int) $pdo->query("SELECT COUNT(*) FROM {$q}")->fetchColumn();
            $rows += $got;
            if ($got !== (int) $expected) {
                $match = false;
                $problems[] = "Row count differs for {$table}: expected {$expected}, restored {$got}.";
            }
        }
        $uploads = 0;
        if ($uploadsDir !== null) {
            $uploads = self::extractUploads($zip, (array) ($manifest['uploads'] ?? []), rtrim($uploadsDir, '/'), $problems);
        }
        $zip->close();

        // The audit hash chain of the RESTORED data, checked through the normal verifier pointed at the restored database.
        $orig = (string) $c['database'];
        Config::set('database.database', $database);
        Database::disconnect();
        try {
            $v = Audit::verify();
            $chain = $v['ok'] ? "intact ({$v['checked']} rows verified)" : 'BROKEN at row ' . ($v['broken_at'] ?? '?');
            if (!$v['ok']) {
                $problems[] = 'The audit chain of the restored data does not verify.';
            }
        } catch (\Throwable $e) {
            $chain = 'could not be verified: ' . $e->getMessage();
            $problems[] = $chain;
        } finally {
            Config::set('database.database', $orig);
            Database::disconnect();
        }

        return ['tables' => count((array) $manifest['tables']), 'rows' => $rows, 'uploads' => $uploads, 'row_counts_match' => $match, 'audit_chain' => $chain, 'problems' => $problems];
    }

    /** @param array<string,string> $expected @param list<string> $problems */
    private static function extractUploads(\ZipArchive $zip, array $expected, string $dir, array &$problems): int
    {
        $n = 0;
        foreach ($expected as $rel => $hash) {
            $rel = (string) $rel;
            if ($rel === '' || str_contains($rel, '..') || str_starts_with($rel, '/') || str_contains($rel, "\0") || str_contains($rel, '\\')) {
                $problems[] = 'Refused an unsafe path in the archive.';
                continue;
            }
            $target = $dir . '/' . $rel;
            if (!is_dir(dirname($target)) && !@mkdir(dirname($target), 0750, true) && !is_dir(dirname($target))) {
                $problems[] = "Cannot create a folder for {$rel}.";
                continue;
            }
            $in = $zip->getStream('uploads/' . $rel);
            $out = $in === false ? false : fopen($target, 'wb');
            if ($in === false || $out === false) {
                $problems[] = "Cannot restore {$rel}.";
                continue;
            }
            stream_copy_to_stream($in, $out);
            fclose($in);
            fclose($out);
            @chmod($target, 0640);
            if (!hash_equals((string) $hash, (string) hash_file('sha256', $target))) {
                $problems[] = "Restored file does not match its checksum: {$rel}";
            }
            $n++;
        }

        return $n;
    }

    /** @return array{path:?string,at:?string,hours:?int,count:int} the newest backup file in the backup directory */
    public static function latest(?string $dir = null): array
    {
        $all = glob(self::dir($dir) . '/vis-backup-*.zip') ?: [];
        rsort($all);
        if ($all === []) {
            return ['path' => null, 'at' => null, 'hours' => null, 'count' => 0];
        }
        $t = (int) filemtime($all[0]);

        return ['path' => $all[0], 'at' => gmdate('Y-m-d H:i:s', $t), 'hours' => (int) floor((time() - $t) / 3600), 'count' => count($all)];
    }
}
