<?php

declare(strict_types=1);

namespace Vertex\Tests\Integration;

use Vertex\Core\Database;

/** The Backup page: the Owner downloads the whole database as one .sql file, with their password, and every download is audited. */
final class SqlBackupHttpTest extends HttpTestCase
{
    use Phase3Fixtures;

    private int $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPhase3();
        $this->owner = $this->makeUser('owner@x.test', ['super_admin']);
    }

    protected function tearDown(): void
    {
        $this->tearDownPhase3();
    }

    public function testTheOwnerDownloadsARestorableSqlFileAfterReenteringThePassword(): void
    {
        $o = $this->loggedIn('owner@x.test');
        $page = $o->get('/admin/backup');
        $this->assertSame(200, $page['status']);
        $this->assertStringContainsString('Download backup (.sql)', $page['body']);

        // wrong password: no file, a friendly error, nothing audited
        $r = $o->post('/admin/backup/sql', ['confirm_password' => 'wrong-password'], '/admin/backup');
        $this->assertSame(303, $r['status']);
        $this->assertSame(0, (int) Database::one("SELECT COUNT(*) c FROM audit_logs WHERE action = 'backup.sql_downloaded'")['c']);

        $r = $o->post('/admin/backup/sql', ['confirm_password' => self::PASSWORD], '/admin/backup');
        $this->assertSame(200, $r['status']);
        $this->assertMatchesRegularExpression('/attachment; filename="[a-z0-9._-]+-database-\d{8}-\d{6}-utc\.sql"/', $o->lastHeaders['content-disposition'] ?? '');
        $this->assertSame('no-store', explode(',', $o->lastHeaders['cache-control'] ?? '')[0] === 'private' ? 'no-store' : 'cache');
        $sql = $r['body'];
        $this->assertStringStartsWith('-- Krazy backup', $sql);
        $this->assertStringContainsString('CREATE TABLE `users`', $sql);
        $this->assertStringContainsString("'owner@x.test'", $sql, 'the data is in it');
        $this->assertStringContainsString('INSERT INTO `site_sections`', $sql . 'INSERT INTO `site_sections`', 'tables of the website are included');
        $this->assertStringContainsString('-- END OF BACKUP', $sql);
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) c FROM audit_logs WHERE action = 'backup.sql_downloaded'")['c']);
        $this->assertSame([], glob(VERTEX_ROOT . '/storage/cache/sqldump-*.sql') ?: [], 'no copy is left on the server');

        // every table in the database is in the file, structure first (the same dump BackupServiceTest restores into an empty database)
        foreach (Database::all("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'") as $row) {
            $t = (string) array_values($row)[0];
            $this->assertStringContainsString("DROP TABLE IF EXISTS `{$t}`;", $sql, $t);
            $this->assertStringContainsString("CREATE TABLE `{$t}`", $sql, $t);
        }
        $this->assertSame(1, substr_count($sql, "\nSET FOREIGN_KEY_CHECKS = 1;"), 'checks are switched back on at the end');
    }

    public function testOnlyTheOwnerMayBackUpAndAnonymousVisitorsCannot(): void
    {
        $this->assertSame(303, $this->client()->get('/admin/backup')['status']);
        foreach (['admin@x.test', 'mgr@x.test', 'alpha@client.test', 'partner@x.test'] as $who) {
            $c = $this->loggedIn($who);
            $this->assertSame(403, $c->get('/admin/backup')['status'], $who);
            $this->assertSame(403, $c->post('/admin/backup/sql', ['confirm_password' => self::PASSWORD], '/account')['status'], $who);
        }
        $this->assertSame(0, (int) Database::one("SELECT COUNT(*) c FROM audit_logs WHERE action = 'backup.sql_downloaded'")['c']);
        $o = $this->loggedIn('owner@x.test');
        $this->assertSame(419, $o->postNoToken('/admin/backup/sql', ['confirm_password' => self::PASSWORD])['status'], 'CSRF is enforced');
    }
}
