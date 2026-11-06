<?php

declare(strict_types=1);

namespace Vertex\Tests\Integration;

use Vertex\Core\Config;
use Vertex\Core\Database;
use Vertex\Services\BackupService;
use Vertex\Services\FileStore;

/** Backup & restore of the database (.sql) and of the uploaded files (.zip): what is accepted, what is refused, and that data comes back. All data is fictional. */
final class BackupRestoreTest extends HttpTestCase
{
    private string $tmp;
    private string $oldBackupDir;
    private string $oldUploads;

    protected function setUp(): void
    {
        parent::setUp();
        \Vertex\Services\PermissionSync::run(true);
        $this->makeUser('owner@x.test', ['super_admin']);
        $this->makeUser('admin@x.test', ['administrator']);
        $this->tmp = sys_get_temp_dir() . '/kis-backup-test-' . bin2hex(random_bytes(4));
        mkdir($this->tmp . '/uploads', 0777, true);
        $this->oldBackupDir = (string) Config::get('backup.dir');
        $this->oldUploads = (string) Config::get('uploads.root', '');
        Config::set('backup.dir', $this->tmp . '/backups');
        Config::set('uploads.root', $this->tmp . '/uploads');
    }

    protected function tearDown(): void
    {
        Config::set('backup.dir', $this->oldBackupDir);
        Config::set('uploads.root', $this->oldUploads);
        exec('rm -rf ' . escapeshellarg($this->tmp));
    }

    private function clients(): int
    {
        return (int) Database::one('SELECT COUNT(*) c FROM clients')['c'];
    }

    private function dump(): string
    {
        $d = BackupService::sqlDump();
        $f = $this->tmp . '/backup.sql';
        rename($d['path'], $f);

        return $f;
    }

    private function refuses(string $file, string $needle): void
    {
        try {
            BackupService::inspectSql($file);
            $this->fail('The file should have been refused.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsStringIgnoringCase($needle, $e->getMessage());
        }
    }

    // ------------------------------------------------------------------------------------------------ database

    public function testARealBackupIsAcceptedAndOtherFilesAreRefusedBeforeAnythingChanges(): void
    {
        $good = $this->dump();
        $info = BackupService::inspectSql($good);
        $this->assertGreaterThan(100, count($info['tables']));
        $this->assertContains('users', $info['tables']);

        $sql = (string) file_get_contents($good);
        $variants = [
            'not a backup' => ["-- phpMyAdmin SQL Dump\n" . substr($sql, strpos($sql, "\n") + 1), 'not a backup made by this system'],
            'cut off' => [str_replace("-- END OF BACKUP\n", '', $sql), 'incomplete'],
            'grant' => [str_replace("-- END OF BACKUP\n", "GRANT ALL ON *.* TO 'x'@'%';\n-- END OF BACKUP\n", $sql), 'statement a backup never contains'],
            'drop database' => [str_replace("-- END OF BACKUP\n", "DROP DATABASE vertex;\n-- END OF BACKUP\n", $sql), 'statement a backup never contains'],
            'stacked' => [str_replace("-- END OF BACKUP\n", "INSERT INTO `clients` (`id`) VALUES (1); DROP TABLE `users`;\n-- END OF BACKUP\n", $sql), 'statement a backup never contains'],
            'insert select' => [str_replace("-- END OF BACKUP\n", "INSERT INTO `clients` (`id`) SELECT id FROM users;\n-- END OF BACKUP\n", $sql), 'statement a backup never contains'],
            'schema qualified' => [str_replace("-- END OF BACKUP\n", "DROP TABLE IF EXISTS `mysql`.`user`;\n-- END OF BACKUP\n", $sql), 'statement a backup never contains'],
            'after the end' => [$sql . "SELECT 1;\n", 'after the end'],
        ];
        foreach ($variants as $name => [$content, $needle]) {
            $f = $this->tmp . '/bad.sql';
            file_put_contents($f, $content);
            $this->refuses($f, $needle);
        }
        // text that merely contains a semicolon or a keyword inside a value is data, not a second statement
        $f = $this->tmp . '/ok.sql';
        file_put_contents($f, str_replace("-- END OF BACKUP\n", "INSERT INTO `clients` (`id`, `company_name`) VALUES (999, 'A; DROP TABLE users; -- GRANT it''s fine');\n-- END OF BACKUP\n", $sql));
        $this->assertGreaterThan(0, BackupService::inspectSql($f)['statements']);
    }

    public function testRestoreBringsTheDataBackAndKeepsASafetyCopy(): void
    {
        $this->makeLooseClient('Before Backup Ltd', 'before@x.test');
        $file = $this->dump();
        $this->makeLooseClient('After Backup Ltd', 'after@x.test');
        Database::run('CREATE TABLE zz_added_later (id INT)');
        $this->assertSame(2, $this->clients());

        $r = BackupService::restoreSql($file);

        $this->assertSame(1, $this->clients(), 'the client created after the backup is gone');
        $this->assertSame('Before Backup Ltd', Database::one('SELECT company_name FROM clients')['company_name'] ?? Database::one('SELECT contact_name AS company_name FROM clients')['company_name']);
        $this->assertNull(Database::one("SHOW TABLES LIKE 'zz_added_later'"), 'a table the backup does not know is removed');
        $this->assertGreaterThan(100, $r['tables']);
        $safety = $this->tmp . '/backups/' . $r['safety_copy'];
        $this->assertFileExists($safety);
        $this->assertSame(0600, fileperms($safety) & 0777);
        // the safety copy is itself a valid backup holding the data from just before the restore
        $this->assertContains('clients', BackupService::inspectSql($safety)['tables']);
        $this->assertStringContainsString('After Backup Ltd', (string) file_get_contents($safety) . 'x');
    }

    public function testAFailedRestorePutsThePreviousDataBack(): void
    {
        $this->makeLooseClient('Kept Ltd', 'kept@x.test');
        $good = (string) file_get_contents($this->dump());
        $bad = $this->tmp . '/bad-exec.sql';
        // passes the line check but cannot run: the column does not exist
        file_put_contents($bad, str_replace("-- END OF BACKUP\n", "INSERT INTO `clients` (`no_such_column`) VALUES (1);\n-- END OF BACKUP\n", $good));
        $this->makeLooseClient('Added After Dump', 'later@x.test');
        $this->assertSame(2, $this->clients());
        try {
            BackupService::restoreSql($bad);
            $this->fail('The restore should have failed.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('previous data was put back', $e->getMessage());
        }
        $this->assertSame(2, $this->clients(), 'nothing was lost: the data from just before the attempt is back');
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) c FROM clients WHERE email = 'later@x.test'")['c']);
    }

    // ------------------------------------------------------------------------------------------------ files

    private function put(string $rel, string $data): string
    {
        $path = $this->tmp . '/uploads/' . $rel;
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, $data);

        return $path;
    }

    public function testTheFilesBackupHoldsEveryUploadWithChecksums(): void
    {
        $this->put('2026/11/' . str_repeat('a', 40), 'deliverable one');
        $this->put('2026/10/' . str_repeat('b', 40), 'payment proof');
        $d = BackupService::filesZip();
        $this->assertSame(2, $d['files']);
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($d['path']));
        $m = json_decode((string) $zip->getFromName('manifest.json'), true);
        $this->assertSame('files', $m['type']);
        $this->assertSame(hash('sha256', 'deliverable one'), $m['uploads']['2026/11/' . str_repeat('a', 40)]);
        $this->assertSame('deliverable one', $zip->getFromName('uploads/2026/11/' . str_repeat('a', 40)));
        $zip->close();
        $this->assertSame(0600, fileperms($d['path']) & 0777);
        unlink($d['path']);
    }

    public function testRestoringFilesPutsMissingAndChangedFilesBackAndLeavesOthersAlone(): void
    {
        $a = $this->put('2026/11/' . str_repeat('a', 40), 'original A');
        $b = $this->put('2026/11/' . str_repeat('b', 40), 'original B');
        $z = BackupService::filesZip();
        unlink($a);                                   // lost
        file_put_contents($b, 'damaged B');           // changed
        $extra = $this->put('2026/11/' . str_repeat('c', 40), 'created after the backup');
        $r = BackupService::restoreFiles($z['path']);
        $this->assertSame(['restored' => 2, 'unchanged' => 0, 'problems' => []], $r);
        $this->assertSame('original A', file_get_contents($a));
        $this->assertSame('original B', file_get_contents($b));
        $this->assertSame('created after the backup', file_get_contents($extra), 'a file that is not in the backup is not deleted');
        $r2 = BackupService::restoreFiles($z['path']);
        $this->assertSame(2, $r2['unchanged'], 'running it again changes nothing');
        unlink($z['path']);
    }

    public function testRestoringFilesRefusesUnsafePathsAndDamagedFiles(): void
    {
        $zipPath = $this->tmp . '/evil.zip';
        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE);
        $good = str_repeat('d', 40);
        $entries = ['2026/11/' . $good => 'fine', '../escape' => 'x', '2026/11/shell.php' => '<?php echo 1;', '.htaccess' => 'x', 'website-media/2026/a.svg' => '<svg/>', 'website-media/2026/fake.png' => 'not an image', '2026/11/tampered' => 'changed'];
        $manifest = ['format' => 1, 'type' => 'files', 'uploads' => []];
        foreach ($entries as $rel => $data) {
            $zip->addFromString('uploads/' . $rel, $data);
            $manifest['uploads'][$rel] = hash('sha256', $rel === '2026/11/tampered' ? 'what the manifest promised' : $data);
        }
        $zip->addFromString('manifest.json', json_encode($manifest));
        $zip->close();
        $r = BackupService::restoreFiles($zipPath);
        $this->assertSame(1, $r['restored'], 'only the one good file is restored');
        $this->assertFileExists($this->tmp . '/uploads/2026/11/' . $good);
        $this->assertFileDoesNotExist($this->tmp . '/uploads/2026/11/shell.php');
        $this->assertFileDoesNotExist($this->tmp . '/escape');
        $this->assertFileDoesNotExist($this->tmp . '/uploads/2026/11/tampered');
        $this->assertCount(6, $r['problems']);
        foreach (['not a zip at all', ''] as $junk) {
            file_put_contents($this->tmp . '/junk.zip', $junk);
            try {
                BackupService::restoreFiles($this->tmp . '/junk.zip');
                $this->fail('A file that is not a zip must be refused.');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('not a valid .zip', $e->getMessage());
            }
        }
    }

    public function testWebsitePicturesGoToThePublicFolderOnlyWhenTheyAreImages(): void
    {
        $m = new \ReflectionMethod(BackupService::class, 'uploadTarget');
        $m->setAccessible(true);
        $this->assertStringEndsWith('/uploads/cms', $m->invoke(null, 'website-media/2026/abc.png')[0]);
        $this->assertSame('2026/abc.png', $m->invoke(null, 'website-media/2026/abc.png')[1]);
        foreach (['website-media/2026/a.svg', 'website-media/2026/a.html', 'website-media/a.php', 'website-media', '../x', 'a/../b', '/etc/passwd', 'a\\b', "a\0b", '.env', 'x/.htaccess', 'x/shell.php.jpg', 'a//b', str_repeat('a', 300)] as $bad) {
            $this->assertNull($m->invoke(null, $bad), $bad);
        }
        $this->assertSame([rtrim(FileStore::root(), '/'), '2026/11/' . str_repeat('a', 40)], $m->invoke(null, '2026/11/' . str_repeat('a', 40)));
    }

    // ------------------------------------------------------------------------------------------------ pages

    public function testOnlyTheOwnerSeesTheTabsAndTheRestoreRequiresPasswordAndTheWord(): void
    {
        $o = $this->loggedIn('owner@x.test');
        $db = $o->get('/admin/backup');
        $this->assertSame(200, $db['status']);
        foreach (['Database (.sql)', 'Files (.zip)', 'Download database backup (.sql)', 'Restore database', 'Type the word RESTORE'] as $needle) {
            $this->assertStringContainsString($needle, $db['body']);
        }
        $files = $o->get('/admin/backup?tab=files');
        foreach (['Download files backup (.zip)', 'Restore files', 'deliverables'] as $needle) {
            $this->assertStringContainsString($needle, $files['body']);
        }
        $this->makeLooseClient('Safe Ltd', 'safe@x.test');
        // no password / no word / no file: nothing happens
        $o->post('/admin/backup/restore-sql', ['confirm_password' => 'wrong', 'confirm_text' => 'RESTORE'], '/admin/backup');
        $o->post('/admin/backup/restore-sql', ['confirm_password' => self::PASSWORD, 'confirm_text' => 'nope'], '/admin/backup');
        $r = $o->post('/admin/backup/restore-sql', ['confirm_password' => self::PASSWORD, 'confirm_text' => 'RESTORE'], '/admin/backup');
        $this->assertSame(303, $r['status']);
        $this->assertSame(1, $this->clients());
        $this->assertSame(303, $o->post('/admin/backup/restore-files', ['confirm_password' => self::PASSWORD, 'confirm_text' => 'RESTORE'], '/admin/backup?tab=files')['status']);
        $a = $this->loggedIn('admin@x.test');
        foreach (['/admin/backup', '/admin/backup?tab=files'] as $path) {
            $this->assertSame(403, $a->get($path)['status'], $path);
        }
        foreach (['/admin/backup/files', '/admin/backup/restore-sql', '/admin/backup/restore-files'] as $path) {
            $this->assertSame(403, $a->request('POST', $path, ['_token' => $a->token('/account'), 'confirm_password' => self::PASSWORD, 'confirm_text' => 'RESTORE'])['status'], $path);
        }
    }

    public function testTheFilesBackupDownloadsAsAZipForTheOwner(): void
    {
        $o = $this->loggedIn('owner@x.test');
        $wrong = $o->post('/admin/backup/files', ['confirm_password' => 'wrong'], '/admin/backup?tab=files');
        $this->assertSame(303, $wrong['status']);
        $ok = $o->post('/admin/backup/files', ['confirm_password' => self::PASSWORD], '/admin/backup?tab=files');
        $this->assertSame(200, $ok['status']);
        $this->assertStringStartsWith('PK', $ok['body']);
        $this->assertStringContainsString('-files-', (string) ($o->lastHeaders['content-disposition'] ?? $o->lastHeaders['Content-Disposition'] ?? ''));
        $this->assertGreaterThan(0, (int) Database::one("SELECT COUNT(*) c FROM audit_logs WHERE action = 'backup.files_downloaded'")['c']);
    }
}
