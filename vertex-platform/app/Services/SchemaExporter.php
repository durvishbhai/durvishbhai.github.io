<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Database;

/**
 * Builds ONE importable SQL file for a brand-new installation (for phpMyAdmin or the web installer): every migration in order, the migration ledger
 * (so a later `migrate` knows what is applied) and the initial roles and permissions. It carries NO user data, no audit entries and no secrets.
 *
 * Run it against a scratch database that was just migrated and synced (`migrate` + `permissions:sync`). A test installs the generated file into an empty
 * database and proves the result is identical to migrating that database, so the file cannot silently drift from the migrations.
 */
final class SchemaExporter
{
    /** Tables whose rows are written by `permissions:sync` and must exist on a fresh install. */
    public const SEED_TABLES = ['permissions', 'roles', 'role_permissions'];

    public static function build(string $migrationsDir): string
    {
        $files = glob(rtrim($migrationsDir, '/') . '/*.sql') ?: [];
        sort($files);
        if ($files === []) {
            throw new \RuntimeException('No migrations found.');
        }
        $pdo = Database::connection();
        $out = [];
        $out[] = '-- Vertex Integrated Solutions: database structure and initial roles/permissions.';
        $out[] = '-- Generated ' . gmdate('Y-m-d H:i') . ' UTC from ' . count($files) . ' migrations. Contains no user data, no audit log and no secrets.';
        $out[] = '-- IMPORT THIS FILE INTO AN EMPTY DATABASE ONLY (phpMyAdmin -> Import, or the web installer does it for you).';
        $out[] = '-- It creates tables and fails safely ("table already exists") if the database is not empty. Never import it over live data.';
        $out[] = 'SET NAMES utf8mb4;';
        $out[] = 'SET FOREIGN_KEY_CHECKS = 0;';
        $ledger = [];
        foreach ($files as $f) {
            $name = basename($f);
            $sql = rtrim((string) file_get_contents($f));
            $out[] = '';
            $out[] = "-- ===== {$name} =====";
            $out[] = $sql;
            $ledger[] = '(' . implode(', ', [$pdo->quote($name), $pdo->quote(hash_file('sha256', $f)), '1', 'UTC_TIMESTAMP()']) . ')';
        }
        $out[] = '';
        $out[] = '-- ===== migration ledger =====';
        $out[] = 'CREATE TABLE IF NOT EXISTS schema_migrations (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, migration VARCHAR(190) NOT NULL, checksum CHAR(64) NOT NULL, batch INT UNSIGNED NOT NULL, applied_at DATETIME NOT NULL, UNIQUE KEY uq_schema_migrations_name (migration)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;';
        $out[] = 'INSERT INTO schema_migrations (migration, checksum, batch, applied_at) VALUES ' . implode(', ', $ledger) . ';';
        $out[] = '';
        $out[] = '-- ===== initial roles and permissions =====';
        foreach (self::SEED_TABLES as $table) {
            $rows = $pdo->query("SELECT * FROM `{$table}` ORDER BY 1" . ($table === 'role_permissions' ? ', 2' : ''))->fetchAll(\PDO::FETCH_ASSOC);
            if ($rows === []) {
                throw new \RuntimeException("The scratch database has no rows in {$table}: run permissions:sync first.");
            }
            $cols = '(' . implode(', ', array_map(static fn ($c): string => "`{$c}`", array_keys($rows[0]))) . ')';
            foreach (array_chunk($rows, 100) as $chunk) {
                $values = array_map(static fn (array $r): string => '(' . implode(', ', array_map(static fn ($v): string => $v === null ? 'NULL' : (is_int($v) || is_float($v) ? (string) $v : $pdo->quote((string) $v)), array_values($r))) . ')', $chunk);
                $out[] = "INSERT INTO `{$table}` {$cols} VALUES " . implode(', ', $values) . ';';
            }
        }
        $out[] = '';
        $out[] = 'SET FOREIGN_KEY_CHECKS = 1;';
        $out[] = '-- END OF SCHEMA';

        return implode("\n", $out) . "\n";
    }
}
