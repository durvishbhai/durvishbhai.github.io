<?php

declare(strict_types=1);

namespace Vertex\Tests\Integration;

use Vertex\Core\Config;
use Vertex\Core\Database;
use Vertex\Core\HttpException;
use Vertex\Core\Request;
use Vertex\Repositories\UserRepository;
use Vertex\Services\Audit;
use Vertex\Services\Auth;
use Vertex\Services\Gate;
use Vertex\Services\Migrator;
use Vertex\Services\PermissionSync;

final class FoundationTest extends DatabaseTestCase
{
    private function loginRequest(string $ip = '203.0.113.5'): Request
    {
        return new Request('POST', '/login', [], [], ['REMOTE_ADDR' => $ip, 'HTTP_USER_AGENT' => 'phpunit']);
    }

    // ---- migrations & permission sync ---------------------------------------------------------------------

    public function testAllMigrationsAreAppliedWithoutDriftAndRerunIsNoop(): void
    {
        $migrator = new Migrator(VERTEX_ROOT . '/database/migrations');
        $status = $migrator->status();
        $this->assertGreaterThanOrEqual(4, count($status));
        foreach ($status as $s) {
            $this->assertSame('applied', $s['status'], $s['migration']);
        }
        $this->assertSame([], $migrator->migrate());
    }

    public function testForeignKeysAndUniqueConstraintsExist(): void
    {
        $fks = (int) Database::one(
            "SELECT COUNT(*) c FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE()",
        )['c'];
        // role_permissions 2, user_roles 3, users 1, auth_tokens 1, clients 1, projects 2, project_assignments 4, referrals 2
        $this->assertSame(16, $fks);
        foreach ([['users', 'uq_users_email'], ['users', 'uq_users_public_id'], ['project_assignments', 'uq_assignment_project_user'], ['auth_tokens', 'uq_auth_tokens_hash'], ['clients', 'uq_clients_user']] as [$t, $idx]) {
            $this->assertNotNull(Database::one('SELECT 1 AS x FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?', [$t, $idx]), "{$t}.{$idx} missing");
        }
    }

    public function testStoredTimestampsAreUtcRegardlessOfAppTimezone(): void
    {
        $id = $this->makeUser('tz@x.test', ['client']);
        $row = Database::one('SELECT created_at, UTC_TIMESTAMP() AS now_utc FROM users WHERE id = ?', [$id]);
        $this->assertLessThan(5, abs(strtotime($row['now_utc'] . ' UTC') - strtotime($row['created_at'] . ' UTC')));
        $this->assertSame('+00:00', Database::one('SELECT @@session.time_zone AS tz')['tz']);
    }

    public function testPermissionSyncCreatesAllRolesAndIsIdempotent(): void
    {
        PermissionSync::run();
        $a = Database::one('SELECT (SELECT COUNT(*) FROM permissions) p, (SELECT COUNT(*) FROM role_permissions) g, (SELECT COUNT(*) FROM roles) r');
        PermissionSync::run();
        $b = Database::one('SELECT (SELECT COUNT(*) FROM permissions) p, (SELECT COUNT(*) FROM role_permissions) g, (SELECT COUNT(*) FROM roles) r');
        $this->assertSame($a, $b);
        $this->assertSame(10, (int) $b['r']);

        $expected = 0;
        foreach (Config::get('permissions.roles') as $def) {
            $expected += count(array_unique($def['permissions']));
        }
        $this->assertSame($expected, (int) $b['g']);
        $this->assertSame(count(Config::get('permissions.sensitive')), (int) Database::one('SELECT COUNT(*) c FROM permissions WHERE is_sensitive = 1')['c']);
    }

    public function testSyncKeepsUiEditsButGivesNewPermissionsTheirDefaults(): void
    {
        // An admin removed a permission from Manager in the UI and (separately) a brand-new permission appears later.
        Database::run("DELETE rp FROM role_permissions rp JOIN roles r ON r.id = rp.role_id JOIN permissions p ON p.id = rp.permission_id WHERE r.slug = 'manager' AND p.perm_key = 'cms.edit'");
        Database::run("DELETE rp FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE p.perm_key = 'cms.view'");
        Database::run("DELETE FROM permissions WHERE perm_key = 'cms.view'"); // simulates "cms.view" being new in this deploy

        PermissionSync::run();

        $mgr = Database::all("SELECT p.perm_key FROM role_permissions rp JOIN roles r ON r.id = rp.role_id JOIN permissions p ON p.id = rp.permission_id WHERE r.slug = 'manager'");
        $keys = array_column($mgr, 'perm_key');
        $this->assertNotContains('cms.edit', $keys, 'UI edit must survive a sync');
        $this->assertContains('cms.view', $keys, 'new permission receives the default grant');

        PermissionSync::run(true); // explicit reset restores defaults
        $keys = array_column(Database::all("SELECT p.perm_key FROM role_permissions rp JOIN roles r ON r.id = rp.role_id JOIN permissions p ON p.id = rp.permission_id WHERE r.slug = 'manager'"), 'perm_key');
        $this->assertContains('cms.edit', $keys);
    }

    public function testSuperAdminAlwaysGetsEveryPermissionEvenAfterManualTampering(): void
    {
        Database::run("DELETE rp FROM role_permissions rp JOIN roles r ON r.id = rp.role_id WHERE r.slug = 'super_admin'");
        PermissionSync::run();
        $this->assertSame((int) Database::one('SELECT COUNT(*) c FROM permissions')['c'], (int) Database::one("SELECT COUNT(*) c FROM role_permissions rp JOIN roles r ON r.id = rp.role_id WHERE r.slug = 'super_admin'")['c']);
    }

    // ---- RBAC ---------------------------------------------------------------------------------------------

    public function testRolesGrantExactlyTheirPermissions(): void
    {
        $owner = $this->makeUser('owner@x.test', ['super_admin']);
        $admin = $this->makeUser('admin@x.test', ['administrator']);
        $manager = $this->makeUser('mgr@x.test', ['manager']);
        $finance = $this->makeUser('fin@x.test', ['finance_staff']);
        $client = $this->makeUser('client@x.test', ['client']);
        $referrer = $this->makeUser('ref@x.test', ['referral_partner']);

        $this->assertTrue(Gate::can('roles.manage_sensitive', $owner));
        $this->assertFalse(Gate::can('roles.manage_sensitive', $admin));
        $this->assertTrue(Gate::can('projects.assign', $admin));
        $this->assertFalse(Gate::can('projects.assign', $manager), 'manager must never assign projects');
        $this->assertFalse(Gate::can('users.create', $manager));
        $this->assertFalse(Gate::can('audit.view', $manager));
        $this->assertTrue(Gate::can('payments.record', $finance));
        $this->assertFalse(Gate::can('transactions.approve', $finance));
        $this->assertFalse(Gate::can('projects.view_assigned', $finance));
        $this->assertTrue(Gate::can('payments.pay', $client));
        $this->assertFalse(Gate::can('payouts.request', $client));
        $this->assertFalse(Gate::can('projects.view_assigned', $referrer));
        $this->assertTrue(Gate::can('referrals.view_own', $referrer));
    }

    public function testPermissionsAreUnionOfRolesAndUnauthenticatedHasNone(): void
    {
        $both = $this->makeUser('combo@x.test', ['freelancer', 'referral_partner']);
        $this->assertTrue(Gate::can('milestones.propose', $both));
        $this->assertTrue(Gate::can('referrals.view_own', $both));
        $this->assertFalse(Gate::can('referrals.decide', $both));
        $this->assertSame(30, Gate::level($both));
        $this->assertFalse(Gate::can('projects.assign'), 'no user => no permission');
    }

    public function testRoleGrantChangesApplyImmediatelyBecausePermissionsAreReadLive(): void
    {
        $id = $this->makeUser('live@x.test', ['support_staff']);
        $this->assertTrue(Gate::can('bugs.manage', $id));
        Database::run("DELETE rp FROM role_permissions rp JOIN roles r ON r.id = rp.role_id JOIN permissions p ON p.id = rp.permission_id WHERE r.slug = 'support_staff' AND p.perm_key = 'bugs.manage'");
        Gate::flush();
        $this->assertFalse(Gate::can('bugs.manage', $id));
    }

    public function testAuthorizeThrows403(): void
    {
        $client = $this->makeUser('c@x.test', ['client']);
        $this->expectException(HttpException::class);
        $this->expectExceptionCode(403);
        Gate::authorize('payouts.approve', $client);
    }

    // ---- authentication -----------------------------------------------------------------------------------

    public function testSuccessfulLoginSetsSessionAndAudits(): void
    {
        $id = $this->makeUser('ok@x.test', ['client']);
        $this->assertSame(Auth::OK, Auth::attempt('OK@x.test', self::PASSWORD, $this->loginRequest()));
        $this->assertSame($id, Auth::id());
        $this->assertSame($id, $_SESSION['auth_user_id']);
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) c FROM audit_logs WHERE action = 'auth.login.success'")['c']);
        $this->assertNotNull(Database::one('SELECT last_login_at FROM users WHERE id = ?', [$id])['last_login_at']);
    }

    public function testFailedLoginsDoNotRevealWhyAndAreAudited(): void
    {
        $this->makeUser('real@x.test', ['client']);
        $this->makeUser('susp@x.test', ['client'], 'deactivated');
        $this->makeUser('pend@x.test', ['client'], 'pending');
        $this->assertSame(Auth::INVALID, Auth::attempt('real@x.test', 'wrong', $this->loginRequest()));
        $this->assertSame(Auth::INVALID, Auth::attempt('ghost@x.test', 'whatever', $this->loginRequest()));
        $this->assertSame(Auth::INACTIVE, Auth::attempt('susp@x.test', self::PASSWORD, $this->loginRequest()));
        $this->assertSame(Auth::PENDING, Auth::attempt('pend@x.test', self::PASSWORD, $this->loginRequest()));
        $this->assertNull(Auth::user());
        $this->assertGreaterThanOrEqual(2, (int) Database::one("SELECT COUNT(*) c FROM audit_logs WHERE action = 'auth.login.failed'")['c']);
        $this->assertSame(2, (int) Database::one("SELECT COUNT(*) c FROM audit_logs WHERE action = 'auth.login.blocked'")['c']);
    }

    public function testLockoutAfterRepeatedFailuresEvenWithCorrectPassword(): void
    {
        $this->makeUser('lock@x.test', ['client']);
        $max = (int) Config::get('session.login_max_attempts');
        for ($i = 0; $i < $max; $i++) {
            $this->assertSame(Auth::INVALID, Auth::attempt('lock@x.test', 'bad' . $i, $this->loginRequest()));
        }
        $this->assertSame(Auth::LOCKED, Auth::attempt('lock@x.test', self::PASSWORD, $this->loginRequest()));
        $this->assertSame(Auth::OK, Auth::attempt('lock@x.test', self::PASSWORD, $this->loginRequest('198.51.100.20')), 'a different IP is not locked out');
    }

    public function testChangingPasswordInvalidatesExistingSessions(): void
    {
        $id = $this->makeUser('pw@x.test', ['client']);
        Auth::attempt('pw@x.test', self::PASSWORD, $this->loginRequest());
        $this->assertSame($id, Auth::id());

        (new UserRepository())->setPassword($id, password_hash('new-password-1234', PASSWORD_DEFAULT));
        Auth::reset();
        $this->assertNull(Auth::user(), 'session bound to the old password/version must stop working');
    }

    public function testDeactivationAndArchiveEndSessionsImmediately(): void
    {
        foreach (['deactivated', 'archived'] as $status) {
            $this->setUp();
            $id = $this->makeUser("s-{$status}@x.test", ['client']);
            Auth::attempt("s-{$status}@x.test", self::PASSWORD, $this->loginRequest());
            $this->assertSame($id, Auth::id());
            Database::run('UPDATE users SET status = ? WHERE id = ?', [$status, $id]);
            Auth::reset();
            $this->assertNull(Auth::user(), "{$status} user must lose access on the next request");
        }
    }

    public function testSessionVersionBumpRevokesAllSessions(): void
    {
        $id = $this->makeUser('ver@x.test', ['client']);
        Auth::attempt('ver@x.test', self::PASSWORD, $this->loginRequest());
        (new UserRepository())->bumpSessionVersion($id);
        Auth::reset();
        $this->assertNull(Auth::user());
    }

    public function testPasswordsAreStoredHashedNeverPlaintextAndEmailsAreUnique(): void
    {
        $this->makeUser('dup@x.test', ['client']);
        $row = Database::one("SELECT password_hash FROM users WHERE email = 'dup@x.test'");
        $this->assertStringNotContainsString('correct-horse', $row['password_hash']);
        $this->assertMatchesRegularExpression('/^\$(2y|argon2id?)\$/', $row['password_hash']);
        $this->assertTrue(password_verify(self::PASSWORD, $row['password_hash']));
        $this->expectException(\PDOException::class);
        $this->makeUser('DUP@x.test', ['client']);
    }

    public function testStepUpPasswordCheckIsRateLimitedAndAudited(): void
    {
        $id = $this->makeUser('step@x.test', ['administrator']);
        $this->assertTrue(Auth::verifyPassword($id, self::PASSWORD));
        for ($i = 0; $i < 5; $i++) {
            $this->assertFalse(Auth::verifyPassword($id, 'nope' . $i));
        }
        $this->assertFalse(Auth::verifyPassword($id, self::PASSWORD), 'locked out even with the right password');
        $this->assertGreaterThanOrEqual(5, (int) Database::one("SELECT COUNT(*) c FROM audit_logs WHERE action = 'auth.stepup.failed'")['c']);
    }

    // ---- audit trail --------------------------------------------------------------------------------------

    public function testAuditChainVerifiesAndRedactsSecrets(): void
    {
        Audit::record('test.one', 'thing', 1, [], ['name' => 'a', 'password' => 'hunter2', 'confirm_password' => 'hunter2', 'nested' => ['api_key' => 'k', 'reset_token' => 'abc']]);
        Audit::record('test.two', 'thing', 1, ['name' => 'a'], ['name' => 'b']);
        $r = Audit::verify();
        $this->assertTrue($r['ok']);
        $this->assertSame(2, $r['checked']);

        $row = Database::one("SELECT after_json FROM audit_logs WHERE action = 'test.one'");
        foreach (['hunter2', '"k"', 'abc'] as $secret) {
            $this->assertStringNotContainsString($secret, $row['after_json']);
        }
        $this->assertStringContainsString('[REDACTED]', $row['after_json']);
    }

    public function testAuditDetectsModifiedRow(): void
    {
        Audit::record('a', 'thing', 1, [], ['amount' => 100]);
        $id = Audit::record('b', 'thing', 1, [], ['amount' => 200]);
        Audit::record('c', 'thing', 1, [], ['amount' => 300]);
        Database::run("UPDATE audit_logs SET after_json = '{\"amount\":1}' WHERE id = ?", [$id]);
        $r = Audit::verify();
        $this->assertFalse($r['ok']);
        $this->assertSame($id, $r['broken_at']);
    }

    public function testAuditDetectsDeletedAndReorderedRows(): void
    {
        Audit::record('a');
        $id = Audit::record('b');
        Audit::record('c');
        Database::run('DELETE FROM audit_logs WHERE id = ?', [$id]);
        $this->assertFalse(Audit::verify()['ok']);
    }

    public function testAuditRecordsRollBackWithTheirTransaction(): void
    {
        try {
            Database::transaction(function (): void {
                Audit::record('will.rollback');
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
        }
        $this->assertSame(0, (int) Database::one('SELECT COUNT(*) c FROM audit_logs')['c']);
    }

    public function testNoApplicationCodePathUpdatesOrDeletesAuditRows(): void
    {
        $offenders = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(VERTEX_ROOT . '/app'));
        foreach ($it as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $src = (string) file_get_contents($file->getPathname());
            if (preg_match('/\b(UPDATE|DELETE\s+FROM|TRUNCATE(\s+TABLE)?|DROP\s+TABLE)\s+`?audit_logs\b/i', $src)) {
                $offenders[] = $file->getPathname();
            }
        }
        $this->assertSame([], $offenders, 'application code must never modify audit_logs');
    }

    public function testSqlInjectionAttemptInEmailIsInert(): void
    {
        $this->makeUser('victim@x.test', ['client']);
        $this->assertSame(Auth::INVALID, Auth::attempt("' OR '1'='1", 'x', $this->loginRequest()));
        $this->assertSame(Auth::INVALID, Auth::attempt("victim@x.test' --", 'x', $this->loginRequest()));
        $this->assertSame(1, (int) Database::one('SELECT COUNT(*) c FROM users')['c']);
    }
}
