<?php

declare(strict_types=1);

namespace Vertex\Tests\Integration;

use Vertex\Core\Database;
use Vertex\Services\Audit;
use Vertex\Services\Migrator;
use Vertex\Services\PermissionSync;

/**
 * "Do not drop existing tables or overwrite production records": builds a genuine Phase 0 database (only migration 0001
 * + Phase 0 style roles/users/audit rows), then applies the Phase 1 migrations and checks nothing was lost.
 */
final class MigrationUpgradeTest extends DatabaseTestCase
{
    protected static function prepareSchema(): void
    {
        self::dropAllTables();
    }

    protected function setUp(): void
    {
        // intentionally no truncation: this test owns the whole schema
    }

    public function testPhase1MigrationsPreserveExistingPhase0DataAndAreResumable(): void
    {
        $tmp = sys_get_temp_dir() . '/vtx-mig-' . bin2hex(random_bytes(4));
        mkdir($tmp);
        copy(VERTEX_ROOT . '/database/migrations/0001_foundation.sql', $tmp . '/0001_foundation.sql');
        $this->assertSame(['0001_foundation.sql'], (new Migrator($tmp))->migrate());

        // ---- Phase 0 style data ----
        $now = gmdate('Y-m-d H:i:s');
        foreach (['admin', 'manager', 'staff', 'freelancer', 'client'] as $slug) {
            Database::run('INSERT INTO roles (slug, name, description, is_system, created_at, updated_at) VALUES (?, ?, ?, 1, ?, ?)', [$slug, ucfirst($slug), 'phase0', $now, $now]);
        }
        $mk = function (string $email, string $role, string $status) use ($now): int {
            Database::run('INSERT INTO users (public_id, name, email, password_hash, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)', [uuid_hex(), $email, $email, password_hash('x', PASSWORD_DEFAULT), $status, $now, $now]);
            $id = Database::insertId();
            Database::run('INSERT INTO user_roles (user_id, role_id, created_at) SELECT ?, id, ? FROM roles WHERE slug = ?', [$id, $now, $role]);

            return $id;
        };
        $oldAdmin = $mk('old-admin@x.test', 'admin', 'active');
        $oldStaff = $mk('old-staff@x.test', 'staff', 'active');
        $oldMgr = $mk('old-mgr@x.test', 'manager', 'active');
        $oldSusp = $mk('old-susp@x.test', 'client', 'suspended');
        $oldAdminHash = Database::one('SELECT password_hash FROM users WHERE id = ?', [$oldAdmin])['password_hash'];
        Audit::record('phase0.event', 'user', $oldAdmin, [], ['k' => 'v'], [], $oldAdmin);
        Audit::record('phase0.event2', 'user', $oldAdmin);
        $this->assertTrue(Audit::verify()['ok']);

        // ---- upgrade (all migrations) ----
        $full = new Migrator(VERTEX_ROOT . '/database/migrations');
        $applied = $full->migrate();
        $this->assertSame(['0002_accounts_security.sql', '0003_roles_hierarchy.sql', '0004_project_access.sql', '0005_crm_clients.sql', '0006_projects_tasks.sql', '0007_priority_pricing.sql'], $applied);
        PermissionSync::run();

        // ---- nothing lost, privileges preserved ----
        $slugs = fn (int $id): array => array_column(Database::all('SELECT r.slug FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ?', [$id]), 'slug');
        $this->assertSame(['super_admin'], $slugs($oldAdmin), 'Phase 0 admin (full power) becomes the Owner role');
        $this->assertSame(['manager'], $slugs($oldStaff), 'staff merges into Manager / Salaried Staff');
        $this->assertSame(['manager'], $slugs($oldMgr));
        $this->assertNull(Database::one("SELECT id FROM roles WHERE slug IN ('admin','staff')"), 'legacy roles gone');
        $this->assertSame('deactivated', Database::one('SELECT status FROM users WHERE id = ?', [$oldSusp])['status'], 'suspended -> deactivated');
        $this->assertSame($oldAdminHash, Database::one('SELECT password_hash FROM users WHERE id = ?', [$oldAdmin])['password_hash'], 'password hashes untouched');
        $this->assertSame(4, (int) Database::one('SELECT COUNT(*) c FROM users')['c']);
        $this->assertSame(1, (int) Database::one('SELECT session_version v FROM users WHERE id = ?', [$oldAdmin])['v']);
        $this->assertSame(10, (int) Database::one('SELECT COUNT(*) c FROM roles')['c']);

        // audit history from Phase 0 is intact and the chain still verifies
        $r = Audit::verify();
        $this->assertTrue($r['ok'], (string) $r['reason']);
        $this->assertGreaterThanOrEqual(2, $r['checked']);
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) c FROM audit_logs WHERE action = 'phase0.event'")['c']);

        // super admin got the full catalogue; migrations are recorded and a rerun is a no-op
        $this->assertSame((int) Database::one('SELECT COUNT(*) c FROM permissions')['c'], (int) Database::one("SELECT COUNT(*) c FROM role_permissions rp JOIN roles r ON r.id = rp.role_id WHERE r.slug = 'super_admin'")['c']);
        $this->assertSame([], $full->migrate());
        foreach ($full->status() as $s) {
            $this->assertSame('applied', $s['status']);
        }

        // editing an applied migration is detected as drift and blocks migrating
        file_put_contents($tmp . '/0001_foundation.sql', "\n-- tampered\n", FILE_APPEND);
        $drift = (new Migrator($tmp))->status();
        $this->assertSame('drifted', $drift[0]['status']);
        $this->expectException(\RuntimeException::class);
        (new Migrator($tmp))->migrate();
    }

    public static function tearDownAfterClass(): void
    {
        // leave the schema fully migrated for any class that runs after this one
        self::dropAllTables();
        (new Migrator(VERTEX_ROOT . '/database/migrations'))->migrate();
        PermissionSync::run();
    }
}
