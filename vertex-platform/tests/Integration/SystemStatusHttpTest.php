<?php

declare(strict_types=1);

namespace Vertex\Tests\Integration;

use Vertex\Core\Config;
use Vertex\Services\BackupService;
use Vertex\Services\RenewalScheduler;

/** The administrator health page tells the truth about what is and is not running, to the right people only, and shows no secrets. */
final class SystemStatusHttpTest extends HttpTestCase
{
    use Phase3Fixtures;

    private static string $emptyBackups = '';

    protected static function extraEnv(): array
    {
        self::$emptyBackups = sys_get_temp_dir() . '/vis-sys-bk-' . bin2hex(random_bytes(4));
        mkdir(self::$emptyBackups, 0750, true);

        return ['BACKUP_DIR' => self::$emptyBackups];
    }

    public static function tearDownAfterClass(): void
    {
        parent::tearDownAfterClass();
        foreach (glob(self::$emptyBackups . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir(self::$emptyBackups);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPhase3();
    }

    protected function tearDown(): void
    {
        $this->tearDownPhase3();
    }

    public function testOnlyAdministratorsSeeItAndItAdmitsWhatIsNotRunning(): void
    {
        foreach (['mgr@x.test', 'sup@x.test', 'dev@x.test', 'alpha@client.test', 'partner@x.test'] as $who) {
            $this->assertSame(403, $this->loggedIn($who)->get('/admin/system')['status'], $who);
        }
        $this->assertSame(303, $this->client()->get('/admin/system')['status']);
        $a = $this->loggedIn('admin@x.test')->get('/admin/system');
        $this->assertSame(200, $a['status']);
        $this->assertStringContainsString('NEVER RUN', $a['body'], 'no scheduler run yet: the page must say so');
        $this->assertStringContainsString('NO BACKUP EXISTS', $a['body'], 'no backup yet: the page must say so');
        $this->assertStringContainsString('Not built', $a['body'], 'unbuilt payment features are stated, not hidden');
        $this->assertStringNotContainsString((string) Config::get('database.password'), $a['body']);
        $this->assertStringNotContainsString((string) Config::get('app.key'), $a['body']);
        RenewalScheduler::run();
        $this->assertStringNotContainsString('NEVER RUN', $this->loggedIn('admin@x.test')->get('/admin/system')['body']);
        $this->assertStringContainsString('last run', $this->loggedIn('admin@x.test')->get('/admin/system')['body']);
    }

    public function testAFreshBackupIsReportedOnlyOnceItExists(): void
    {
        $this->assertNull(BackupService::latest(self::$emptyBackups)['path']);
        file_put_contents(self::$emptyBackups . '/vis-backup-' . gmdate('Ymd-His') . '.zip', 'placeholder');
        $body = $this->loggedIn('admin@x.test')->get('/admin/system')['body'];
        $this->assertStringContainsString('vis-backup-', $body);
        $this->assertStringNotContainsString('NO BACKUP EXISTS', $body);
    }
}
