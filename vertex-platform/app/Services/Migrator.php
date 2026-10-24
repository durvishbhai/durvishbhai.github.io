<?php

declare(strict_types=1);

namespace Vertex\Services;

use RuntimeException;
use Vertex\Core\Database;

/**
 * Forward-only SQL migrations from database/migrations/NNNN_name.sql.
 *  - Applied files are recorded with a SHA-256 checksum; editing an applied file is reported as drift and blocks migrating.
 *  - There are no "down" migrations by design: this system holds financial records. Roll back by restoring a backup
 *    and shipping a new forward migration.
 *  - MySQL/MariaDB DDL is not transactional, so keep each file small and idempotent where practical.
 *  - Files must not contain stored programs/triggers (statements are split on ";" at line end).
 */
final class Migrator
{
    public function __construct(private string $dir)
    {
    }

    public function ensureTable(): void
    {
        Database::run(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                migration VARCHAR(190) NOT NULL,
                checksum CHAR(64) NOT NULL,
                batch INT UNSIGNED NOT NULL,
                applied_at DATETIME NOT NULL,
                UNIQUE KEY uq_schema_migrations_name (migration)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
    }

    /** @return list<array{migration:string,status:string,checksum:string}> status: applied|pending|drifted|missing */
    public function status(): array
    {
        $this->ensureTable();
        $applied = [];
        foreach (Database::all('SELECT migration, checksum FROM schema_migrations ORDER BY migration') as $r) {
            $applied[$r['migration']] = $r['checksum'];
        }
        $out = [];
        foreach ($this->files() as $name => $path) {
            $sum = hash_file('sha256', $path);
            if (!isset($applied[$name])) {
                $out[] = ['migration' => $name, 'status' => 'pending', 'checksum' => $sum];
            } else {
                $out[] = ['migration' => $name, 'status' => hash_equals($applied[$name], $sum) ? 'applied' : 'drifted', 'checksum' => $sum];
                unset($applied[$name]);
            }
        }
        foreach ($applied as $name => $sum) {
            $out[] = ['migration' => $name, 'status' => 'missing', 'checksum' => $sum];
        }

        return $out;
    }

    /** @return list<string> names applied in this run */
    public function migrate(): array
    {
        $status = $this->status();
        foreach ($status as $s) {
            if ($s['status'] === 'drifted') {
                throw new RuntimeException("Migration {$s['migration']} was modified after being applied. Restore the original file and add a new migration instead.");
            }
        }
        $batch = (int) (Database::one('SELECT COALESCE(MAX(batch), 0) AS b FROM schema_migrations')['b'] ?? 0) + 1;
        $files = $this->files();
        $ran = [];
        foreach ($status as $s) {
            if ($s['status'] !== 'pending') {
                continue;
            }
            $sql = (string) file_get_contents($files[$s['migration']]);
            foreach ($this->split($sql) as $statement) {
                Database::connection()->exec($statement);
            }
            Database::run(
                'INSERT INTO schema_migrations (migration, checksum, batch, applied_at) VALUES (?, ?, ?, UTC_TIMESTAMP())',
                [$s['migration'], $s['checksum'], $batch],
            );
            $ran[] = $s['migration'];
        }

        return $ran;
    }

    /** @return array<string,string> name => path, sorted */
    private function files(): array
    {
        $files = glob(rtrim($this->dir, '/') . '/*.sql') ?: [];
        sort($files);
        $out = [];
        foreach ($files as $f) {
            $out[basename($f)] = $f;
        }

        return $out;
    }

    /** @return list<string> */
    public function split(string $sql): array
    {
        $clean = [];
        foreach (preg_split('/\R/', $sql) ?: [] as $line) {
            if (preg_match('/^\s*--/', $line)) {
                continue;
            }
            $clean[] = $line;
        }
        $statements = [];
        $buffer = '';
        foreach ($clean as $line) {
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
}
