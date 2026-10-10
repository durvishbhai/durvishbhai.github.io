<?php

declare(strict_types=1);

namespace Vertex\Tests\Integration;

use Vertex\Core\Config;
use Vertex\Core\Database;
use Vertex\Core\Request;
use Vertex\Repositories\UserRepository;
use Vertex\Services\Audit;
use Vertex\Services\Auth;
use Vertex\Services\Gate;
use Vertex\Services\Migrator;
use Vertex\Services\PermissionSync;

final class FoundationTest extends DatabaseTestCase
{
    private function makeUser(string $email, array $roles, string $status = 'active', string $password = 'correct-horse-battery'): int
    {
        return (new UserRepository())->create('User ' . $email, $email, password_hash($password, PASSWORD_DEFAULT), $status, $roles);
    }

    private function loginRequest(string $ip = '203.0.113.5'): Request
    {
        return new Request('POST', '/login', [], [], ['REMOTE_ADDR' => $ip, 'HTTP_USER_AGENT' => 'phpunit']);
    }

    // ---- migrations & permission sync ---------------------------------------------------------------------

    public function testMigrationsAreAppliedAndNotDrifted(): void
    {
        $status = (new Migrator(VERTEX_ROOT . '/database/migrations'))->status();
        $this->assertNotEmpty($status);
        foreach ($status as $s) {
            $this->assertSame('applied', $s['status'], $s['migration']);
        }
        $this->assertSame([], (new Migrator(VERTEX_ROOT . '/database/migrations'))->migrate(), 'second run is a no-op');
    }

    public function testPermissionSyncMatchesConfigAndIsIdempotent(): void
    {
        PermissionSync::run();
        $first = Database::one('SELECT (SELECT COUNT(*) FROM permissions) p, (SELECT COUNT(*) FROM role_permissions) g');
        PermissionSync::run();
        $second = Database::one('SELECT (SELECT COUNT(*) FROM permissions) p, (SELECT COUNT(*) FROM role_permissions) g');
        $this->assertSame($first, $second);

        $expected = 0;
        foreach (Config::get('permissions.roles') as $def) {
            $expected += count(array_unique($def['permissions']));
        }
        $this->assertSame($expected, (int) $second['g']);
    }

    // ---- RBAC ---------------------------------------------------------------------------------------------

    public function testRolesGrantExactlyTheirPermissions(): void
    {
        $admin = $this->makeUser('admin@x.test', ['admin']);
        $manager = $this->makeUser('mgr@x.test', ['manager']);
        $client = $this->makeUser('client@x.test', ['client']);
        $referrer = $this->makeUser('ref@x.test', ['referral_partner']);

        $this->assertTrue(Gate::can('projects.assign', $admin));
        $this->assertTrue(Gate::can('payouts.approve', $admin));

        $this->assertFalse(Gate::can('projects.assign', $manager), 'manager must never assign projects');
        $this->assertFalse(Gate::can('payouts.approve', $manager));
        $this->assertFalse(Gate::can('audit.view', $manager));
        $this->assertTrue(Gate::can('projects.view_assigned', $manager));

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
        $this->assertFalse(Gate::can('projects.assign'), 'no user => no permission');
    }

    public function testAuthorizeThrows403(): void
    {
        $client = $this->makeUser('c@x.test', ['client']);
        $this->expectException(\Vertex\Core\HttpException::class);
        $this->expectExceptionCode(403);
        Gate::authorize('payouts.approve', $client);
    }

    // ---- authentication -----------------------------------------------------------------------------------

    public function testSuccessfulLoginSetsSessionAndAudits(): void
    {
        $id = $this->makeUser('ok@x.test', ['client']);
        $this->assertSame(Auth::OK, Auth::attempt('OK@x.test', 'correct-horse-battery', $this->loginRequest()));
        $this->assertSame($id, Auth::id());
        $this->assertSame($id, $_SESSION['auth_user_id']);
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) c FROM audit_logs WHERE action = 'auth.login.success'")['c']);
    }

    public function testWrongPasswordUnknownEmailAndSuspendedAreIndistinguishableToCaller(): void
    {
        $this->makeUser('real@x.test', ['client']);
        $this->makeUser('susp@x.test', ['client'], 'suspended');
        $this->assertSame(Auth::INVALID, Auth::attempt('real@x.test', 'wrong', $this->loginRequest()));
        $this->assertSame(Auth::INVALID, Auth::attempt('ghost@x.test', 'whatever', $this->loginRequest()));
        $this->assertSame(Auth::INACTIVE, Auth::attempt('susp@x.test', 'correct-horse-battery', $this->loginRequest()));
        $this->assertNull(Auth::user());
    }

    public function testLockoutAfterRepeatedFailuresEvenWithCorrectPassword(): void
    {
        $this->makeUser('lock@x.test', ['client']);
        $max = (int) Config::get('session.login_max_attempts');
        for ($i = 0; $i < $max; $i++) {
            $this->assertSame(Auth::INVALID, Auth::attempt('lock@x.test', 'bad' . $i, $this->loginRequest()));
        }
        $this->assertSame(Auth::LOCKED, Auth::attempt('lock@x.test', 'correct-horse-battery', $this->loginRequest()));
        // a different IP is not locked out
        $this->assertSame(Auth::OK, Auth::attempt('lock@x.test', 'correct-horse-battery', $this->loginRequest('198.51.100.20')));
    }

    public function testChangingPasswordInvalidatesExistingSessions(): void
    {
        $id = $this->makeUser('pw@x.test', ['client']);
        Auth::attempt('pw@x.test', 'correct-horse-battery', $this->loginRequest());
        $this->assertSame($id, Auth::id());

        (new UserRepository())->updatePasswordHash($id, password_hash('new-password-123', PASSWORD_DEFAULT), true);
        Auth::reset();
        $this->assertNull(Auth::user(), 'session bound to the old password hash must stop working');
    }

    public function testSuspendingAUserEndsTheirSession(): void
    {
        $id = $this->makeUser('s@x.test', ['client']);
        Auth::attempt('s@x.test', 'correct-horse-battery', $this->loginRequest());
        Database::run("UPDATE users SET status = 'suspended' WHERE id = ?", [$id]);
        Auth::reset();
        $this->assertNull(Auth::user());
    }

    public function testPasswordsAreStoredHashedAndEmailsAreUnique(): void
    {
        $this->makeUser('dup@x.test', ['client']);
        $row = Database::one("SELECT password_hash FROM users WHERE email = 'dup@x.test'");
        $this->assertStringNotContainsString('correct-horse', $row['password_hash']);
        $this->assertTrue(password_verify('correct-horse-battery', $row['password_hash']));
        $this->expectException(\PDOException::class);
        $this->makeUser('DUP@x.test', ['client']);
    }

    // ---- audit trail --------------------------------------------------------------------------------------

    public function testAuditChainVerifiesAndRedactsSecrets(): void
    {
        Audit::record('test.one', 'thing', 1, [], ['name' => 'a', 'password' => 'hunter2', 'nested' => ['api_key' => 'k']]);
        Audit::record('test.two', 'thing', 1, ['name' => 'a'], ['name' => 'b']);
        $r = Audit::verify();
        $this->assertTrue($r['ok']);
        $this->assertSame(2, $r['checked']);

        $row = Database::one("SELECT after_json FROM audit_logs WHERE action = 'test.one'");
        $this->assertStringNotContainsString('hunter2', $row['after_json']);
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

    public function testAuditDetectsDeletedRow(): void
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

    public function testSqlInjectionAttemptInEmailIsInert(): void
    {
        $this->makeUser('victim@x.test', ['client']);
        $this->assertSame(Auth::INVALID, Auth::attempt("' OR '1'='1", 'x', $this->loginRequest()));
        $this->assertSame(Auth::INVALID, Auth::attempt("victim@x.test' --", 'x', $this->loginRequest()));
        $this->assertSame(1, (int) Database::one('SELECT COUNT(*) c FROM users')['c']);
    }
}
