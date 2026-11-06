<?php

declare(strict_types=1);

namespace Vertex\Tests\Integration;

use Vertex\Core\Database;
use Vertex\Services\Migrator;
use Vertex\Services\SchemaExporter;

/**
 * The single database file shipped in the Hostinger package (vertex-database.sql) must produce exactly the database that running the migrations
 * produces. This imports the exported file into an empty database and compares every table definition, the migration ledger and the seeded
 * roles/permissions with the migrated original, so the file cannot silently drift from database/migrations.
 */
final class SchemaExportTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        // intentionally no truncation: this test owns the whole schema
    }

    /** @return array{tables:array<string,string>,seed:array<string,list<array<string,mixed>>>,ledger:list<array<string,mixed>>} */
    private function snapshot(): array
    {
        $pdo = Database::connection();
        $tables = [];
        foreach ($pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN) as $t) {
            $ddl = (string) $pdo->query("SHOW CREATE TABLE `{$t}`")->fetch(\PDO::FETCH_NUM)[1];
            // AUTO_INCREMENT counters differ by definition; "ON DELETE NO ACTION" is the default that MariaDB prints only for some ALTER-added keys
            $tables[(string) $t] = (string) preg_replace(['/ AUTO_INCREMENT=\d+/', '/ ON (DELETE|UPDATE) NO ACTION/'], '', $ddl);
        }
        ksort($tables);
        $seed = [];
        foreach (SchemaExporter::SEED_TABLES as $t) {
            $seed[$t] = $pdo->query("SELECT * FROM `{$t}` ORDER BY 1, 2")->fetchAll(\PDO::FETCH_ASSOC);
        }
        $ledger = $pdo->query('SELECT migration, checksum, batch FROM schema_migrations ORDER BY migration')->fetchAll(\PDO::FETCH_ASSOC);

        return ['tables' => $tables, 'seed' => $seed, 'ledger' => $ledger];
    }

    public function testExportedFileRebuildsTheMigratedDatabaseExactly(): void
    {
        $dir = VERTEX_ROOT . '/database/migrations';
        $sql = SchemaExporter::build($dir);
        $before = $this->snapshot();

        $this->assertStringContainsString('IMPORT THIS FILE INTO AN EMPTY DATABASE ONLY', $sql);
        $this->assertStringNotContainsString('INSERT INTO `users`', $sql, 'the file carries no user data');
        $this->assertStringNotContainsString('INSERT INTO `audit_logs`', $sql);
        $this->assertGreaterThan(70, count($before['tables']));

        self::dropAllTables();
        $this->assertSame([], Database::connection()->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN));
        foreach ((new Migrator($dir))->split($sql) as $statement) {
            Database::connection()->exec($statement);
        }

        $after = $this->snapshot();
        $this->assertSame($before['tables'], $after['tables'], 'every table definition is identical');
        $this->assertSame($before['seed'], $after['seed'], 'roles, permissions and grants are identical');
        $this->assertSame($before['ledger'], $after['ledger'], 'the migration ledger is identical');
        $this->assertSame([], (new Migrator($dir))->migrate(), 'nothing is pending after the import, so later updates only apply new migrations');
        foreach ((new Migrator($dir))->status() as $s) {
            $this->assertSame('applied', $s['status']);
        }
        $this->assertSame(0, (int) Database::one('SELECT COUNT(*) c FROM users')['c']);
        $this->assertSame(0, \Vertex\Services\PermissionSync::run()['new_permissions'], 'the exported permissions already match the code');
    }
}
