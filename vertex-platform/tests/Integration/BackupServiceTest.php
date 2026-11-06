<?php

declare(strict_types=1);

namespace Vertex\Tests\Integration;

use Vertex\Core\Config;
use Vertex\Core\Database;
use Vertex\Services\Audit;
use Vertex\Services\BackupService;

/** Backup, verification and restore into an empty database: the whole disaster-recovery path, with hostile data in the rows. */
final class BackupServiceTest extends DatabaseTestCase
{
    use Phase3Fixtures;

    private string $dir;
    private string $uploads;
    private string $origUploads;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPhase3();
        $this->dir = sys_get_temp_dir() . '/vis-bk-' . bin2hex(random_bytes(4));
        $this->uploads = sys_get_temp_dir() . '/vis-up-' . bin2hex(random_bytes(4));
        mkdir($this->uploads . '/2026/06', 0750, true);
        $this->origUploads = (string) Config::get('uploads.root');
        Config::set('uploads.root', $this->uploads);
        file_put_contents($this->uploads . '/2026/06/' . str_repeat('a', 40), "binary\x00\xff data");
        file_put_contents($this->uploads . '/2026/06/' . str_repeat('b', 40), 'second file');
    }

    protected function tearDown(): void
    {
        Config::set('uploads.root', $this->origUploads);
        foreach ([$this->dir, $this->uploads, $this->uploads . '-restored'] as $d) {
            if (is_dir($d)) {
                $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($d, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
                foreach ($it as $f) {
                    $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
                }
                @rmdir($d);
            }
        }
        $this->tearDownPhase3();
    }

    private function nasty(): array
    {
        return ["Line one\nLine two; DROP TABLE users; --\n", "O'Brien \"quoted\" back\\slash \\' \\\\", "Emoji 🙂 and ünïcödé\tTab", "';\n-- END OF BACKUP\nSELECT 1;", ''];
    }

    private function tableCounts(): array
    {
        $out = [];
        foreach (Database::all("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'") as $r) {
            $t = (string) array_values($r)[0];
            $out[$t] = (int) Database::one("SELECT COUNT(*) AS c FROM `{$t}`")['c'];
        }
        ksort($out);

        return $out;
    }

    public function testBackupVerifyAndRestoreIntoAnEmptyDatabaseRoundTripsEveryTableUploadsAndTheAuditChain(): void
    {
        foreach ($this->nasty() as $i => $text) {
            Database::run('INSERT INTO project_notes (project_id, author_id, body, created_at) VALUES (?, ?, ?, UTC_TIMESTAMP())', [$this->alpha['id'], $this->admin, $text === '' ? 'empty-string-marker' : $text]);
        }
        Audit::record('test.event', 'project', (int) $this->alpha['id'], [], ['k' => 'v'], [], $this->admin);
        $before = $this->tableCounts();
        $notes = Database::all('SELECT body FROM project_notes ORDER BY id');
        $hashes = Database::one("SELECT SHA2(GROUP_CONCAT(email ORDER BY id), 256) AS h FROM users")['h'];

        $b = BackupService::create($this->dir);
        $this->assertFileExists($b['path']);
        $this->assertSame(2, $b['uploads']);
        $this->assertSame(array_sum($before), $b['rows']);
        $this->assertStringNotContainsString('DB_PASSWORD', (string) file_get_contents($b['path']), 'the backup never contains the .env secrets');
        $v = BackupService::verify($b['path']);
        $this->assertTrue($v['ok'], implode(' ', $v['problems']));

        // disaster: every table is gone
        self::dropAllTables();
        $r = BackupService::restore($b['path'], (string) Config::get('database.database'), $this->uploads . '-restored');
        $this->assertSame([], $r['problems']);
        $this->assertTrue($r['row_counts_match']);
        $this->assertStringContainsString('intact', $r['audit_chain']);
        $this->assertSame(2, $r['uploads']);
        $this->assertSame($before, $this->tableCounts(), 'every table has the same number of rows');
        $this->assertSame($notes, Database::all('SELECT body FROM project_notes ORDER BY id'), 'quotes, backslashes, newlines, semicolons and emoji survive byte for byte');
        $this->assertSame($hashes, Database::one("SELECT SHA2(GROUP_CONCAT(email ORDER BY id), 256) AS h FROM users")['h']);
        $this->assertTrue(Audit::verify()['ok'], 'the restored audit chain verifies');
        $this->assertSame("binary\x00\xff data", file_get_contents($this->uploads . '-restored/2026/06/' . str_repeat('a', 40)));
        $this->assertSame('second file', file_get_contents($this->uploads . '-restored/2026/06/' . str_repeat('b', 40)));
        // the restored schema is complete: the migrator sees nothing left to do
        $status = (new \Vertex\Services\Migrator(VERTEX_ROOT . '/database/migrations'))->status();
        $this->assertSame([], array_filter($status, static fn (array $s): bool => $s['status'] !== 'applied'));
    }

    public function testRestoreRefusesANonEmptyDatabaseAndNonEmptyUploadsFolder(): void
    {
        $b = BackupService::create($this->dir);
        try {
            BackupService::restore($b['path'], (string) Config::get('database.database'));
            $this->fail('restoring over live data must be refused');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('not empty', $e->getMessage());
        }
        self::dropAllTables();
        try {
            BackupService::restore($b['path'], (string) Config::get('database.database'), $this->uploads);
            $this->fail('restoring uploads over existing files must be refused');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('not empty', $e->getMessage());
        }
        self::prepareSchema();
    }

    public function testATamperedOrTruncatedBackupFailsVerificationAndIsNeverRestored(): void
    {
        $b = BackupService::create($this->dir);
        // 1. a changed upload inside the archive
        $copy = $this->dir . '/tampered.zip';
        copy($b['path'], $copy);
        $zip = new \ZipArchive();
        $zip->open($copy);
        $zip->addFromString('uploads/2026/06/' . str_repeat('b', 40), 'EDITED');
        $zip->close();
        $v = BackupService::verify($copy);
        $this->assertFalse($v['ok']);
        $this->assertStringContainsString('checksum', implode(' ', $v['problems']));
        // 2. an edited dump
        $copy2 = $this->dir . '/edited-sql.zip';
        copy($b['path'], $copy2);
        $zip = new \ZipArchive();
        $zip->open($copy2);
        $sql = (string) $zip->getFromName('database.sql');
        $zip->addFromString('database.sql', str_replace('INSERT INTO', 'INSERT  INTO', $sql));
        $zip->close();
        $this->assertFalse(BackupService::verify($copy2)['ok']);
        // 3. a truncated file and a random file
        file_put_contents($this->dir . '/trunc.zip', substr((string) file_get_contents($b['path']), 0, 200));
        $this->assertFalse(BackupService::verify($this->dir . '/trunc.zip')['ok']);
        file_put_contents($this->dir . '/random.zip', random_bytes(500));
        $this->assertFalse(BackupService::verify($this->dir . '/random.zip')['ok']);
        $this->assertFalse(BackupService::verify($this->dir . '/missing.zip')['ok']);
        self::dropAllTables();
        try {
            BackupService::restore($copy2, (string) Config::get('database.database'));
            $this->fail('a damaged backup must not be restored');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('failed verification', $e->getMessage());
        }
        $this->assertSame([], Database::all('SHOW TABLES'), 'nothing was written to the database');
        self::prepareSchema();
    }

    public function testAnEditedAuditRowIsDetectedAfterRestore(): void
    {
        Audit::record('test.one', 'project', 1, [], [], [], $this->admin);
        Audit::record('test.two', 'project', 1, [], [], [], $this->admin);
        $b = BackupService::create($this->dir);
        // damage one audit row BEFORE backing up again: the backup faithfully carries the damage and the restore reports it
        Database::run("UPDATE audit_logs SET action = 'test.forged' WHERE action = 'test.one'");
        $b2 = BackupService::create($this->dir);
        self::dropAllTables();
        $r = BackupService::restore($b2['path'], (string) Config::get('database.database'));
        $this->assertStringContainsString('BROKEN', $r['audit_chain']);
        $this->assertNotSame([], $r['problems']);
        unset($b);
        self::prepareSchema();
    }

    public function testRetentionKeepsOnlyTheNewestBackupsAndStatusReportsHonestly(): void
    {
        $this->assertNull(BackupService::latest($this->dir)['path'], 'no backup yet: nothing is claimed');
        $names = [];
        foreach (['20260101-000000', '20260102-000000', '20260103-000000'] as $stamp) {
            @mkdir($this->dir, 0750, true);
            file_put_contents($this->dir . "/vis-backup-{$stamp}.zip", 'x');
            $names[] = $stamp;
        }
        BackupService::create($this->dir, 2);
        $left = array_map('basename', glob($this->dir . '/vis-backup-*.zip'));
        $this->assertCount(2, $left, 'only the two newest are kept');
        $this->assertNotContains('vis-backup-20260101-000000.zip', $left);
        $latest = BackupService::latest($this->dir);
        $this->assertNotNull($latest['path']);
        $this->assertSame(2, $latest['count']);
        $this->assertLessThan(1, $latest['hours']);
    }

    public function testAnUnsafePathInTheManifestIsRefusedOnRestore(): void
    {
        $b = BackupService::create($this->dir);
        $zip = new \ZipArchive();
        $zip->open($b['path']);
        $m = json_decode((string) $zip->getFromName('manifest.json'), true);
        $m['uploads']['../../evil.php'] = hash('sha256', 'x');
        $zip->addFromString('manifest.json', json_encode($m));
        $zip->addFromString('uploads/../../evil.php', 'x');
        $zip->close();
        $v = BackupService::verify($b['path']);
        if ($v['ok']) {
            self::dropAllTables();
            $r = BackupService::restore($b['path'], (string) Config::get('database.database'), $this->uploads . '-restored');
            $this->assertStringContainsString('unsafe', implode(' ', $r['problems']));
            $this->assertFileDoesNotExist(dirname($this->uploads) . '/evil.php');
            self::prepareSchema();
        } else {
            $this->addToAssertionCount(1);
        }
    }
}
