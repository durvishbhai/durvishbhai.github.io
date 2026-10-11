<?php

declare(strict_types=1);

namespace Vertex\Tests\Integration;

use Vertex\Core\Database;
use Vertex\Services\Audit;
use Vertex\Services\Migrator;
use Vertex\Services\PermissionSync;

/**
 * Upgrade safety: a genuine Phase 1 database (migrations 0001-0004 plus real-shaped rows) is migrated to Phase 2.
 * Nothing may be lost or renamed; legacy project statuses are mapped; financial/audit history stays intact; a failing
 * migration is never recorded as applied.
 */
final class Phase2UpgradeTest extends DatabaseTestCase
{
    protected static function prepareSchema(): void
    {
        self::dropAllTables();
    }

    protected function setUp(): void
    {
        // this test owns the whole schema
    }

    public static function tearDownAfterClass(): void
    {
        self::dropAllTables();
        (new Migrator(VERTEX_ROOT . '/database/migrations'))->migrate();
        PermissionSync::run();
    }

    private function phase1Dir(): string
    {
        $tmp = sys_get_temp_dir() . '/vtx-p1-' . bin2hex(random_bytes(4));
        mkdir($tmp);
        foreach (['0001_foundation', '0002_accounts_security', '0003_roles_hierarchy', '0004_project_access'] as $m) {
            copy(VERTEX_ROOT . "/database/migrations/{$m}.sql", "{$tmp}/{$m}.sql");
        }

        return $tmp;
    }

    public function testPhase1DataSurvivesTheUpgradeAndLegacyStatusesAreMapped(): void
    {
        $tmp = $this->phase1Dir();
        (new Migrator($tmp))->migrate();
        PermissionSync::run();

        $now = '2026-02-01 10:00:00';
        $mkUser = function (string $name, string $email, string $role) use ($now): int {
            Database::run("INSERT INTO users (public_id, name, email, phone, password_hash, status, created_at, updated_at) VALUES (?, ?, ?, '+91 91234 56789', ?, 'active', ?, ?)", [uuid_hex(), $name, $email, password_hash('x', PASSWORD_DEFAULT), $now, $now]);
            $id = Database::insertId();
            Database::run('INSERT INTO user_roles (user_id, role_id, created_at) SELECT ?, id, ? FROM roles WHERE slug = ?', [$id, $now, $role]);

            return $id;
        };
        $admin = $mkUser('Asha Admin', 'asha@x.test', 'administrator');
        $dev = $mkUser('Dev One', 'dev@x.test', 'website_developer');
        $cu = $mkUser('Chitra Client', 'chitra@client.test', 'client');
        $cu2 = $mkUser('Bharat Biz', 'bharat@client.test', 'client');
        Database::run('INSERT INTO clients (public_id, user_id, company_name, created_at, updated_at) VALUES (?, ?, NULL, ?, ?)', [$c1 = uuid_hex(), $cu, $now, $now]);
        $client1 = Database::insertId();
        Database::run('INSERT INTO clients (public_id, user_id, company_name, created_at, updated_at) VALUES (?, ?, ?, ?, ?)', [$c2 = uuid_hex(), $cu2, 'Bharat Traders LLP', $now, $now]);
        $client2 = Database::insertId();
        $projects = [];
        foreach ([['Legacy active', 'active', null], ['Legacy draft', 'draft', null], ['Legacy hold', 'on_hold', null], ['Legacy done', 'completed', null], ['Legacy cancelled', 'cancelled', null], ['Legacy archived', 'archived', '2026-01-20 08:00:00']] as [$title, $status, $arch]) {
            Database::run('INSERT INTO projects (public_id, client_id, title, status, created_by, created_at, updated_at, archived_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [$pub = uuid_hex(), $title === 'Legacy hold' ? $client2 : $client1, $title, $status, $admin, $now, $now, $arch]);
            $projects[$title] = ['id' => Database::insertId(), 'public_id' => $pub, 'status' => $status];
        }
        Database::run("INSERT INTO project_assignments (project_id, user_id, project_role, assigned_by, assigned_at) VALUES (?, ?, 'lead', ?, ?)", [$projects['Legacy active']['id'], $dev, $admin, $now]);
        Database::run("INSERT INTO referrals (public_id, partner_user_id, client_id, referred_name, created_at, updated_at) VALUES (?, ?, ?, 'Referred Co', ?, ?)", [uuid_hex(), $admin, $client2, $now, $now]);
        Audit::record('phase1.event', 'project', $projects['Legacy active']['id'], [], ['k' => 'v'], [], $admin);
        $beforeUsers = Database::all('SELECT id, public_id, email, password_hash, status FROM users ORDER BY id');
        $beforeProjects = Database::all('SELECT id, public_id, client_id, title, created_by, created_at FROM projects ORDER BY id');
        $beforeAssign = Database::all('SELECT * FROM project_assignments ORDER BY id');
        $this->assertTrue(Audit::verify()['ok']);
        $auditBefore = Database::all('SELECT * FROM audit_logs ORDER BY id');

        // ---- upgrade to Phase 2 using the real migrations directory ----
        $ran = (new Migrator(VERTEX_ROOT . '/database/migrations'))->migrate();
        $this->assertSame(['0005_crm_clients.sql', '0006_projects_tasks.sql', '0007_priority_pricing.sql', '0008_deliverables_revisions.sql', '0009_support_warranty.sql'], $ran);
        PermissionSync::run();

        // identities, hashes and links untouched
        $this->assertSame($beforeUsers, Database::all('SELECT id, public_id, email, password_hash, status FROM users ORDER BY id'));
        $this->assertSame($beforeProjects, Database::all('SELECT id, public_id, client_id, title, created_by, created_at FROM projects ORDER BY id'));
        $this->assertSame($beforeAssign, Database::all('SELECT * FROM project_assignments ORDER BY id'), 'assignments are byte-identical');
        $this->assertSame(1, (int) Database::one('SELECT COUNT(*) c FROM referrals')['c']);
        $this->assertSame($client2, (int) Database::one('SELECT client_id FROM referrals')['client_id']);

        // legacy statuses mapped honestly; nothing silently completed or cancelled
        $st = static fn (string $t): array => Database::one('SELECT status, archived_at, code FROM projects WHERE title = ?', [$t]);
        $this->assertSame('in_progress', $st('Legacy active')['status']);
        $this->assertSame('draft', $st('Legacy draft')['status']);
        $this->assertSame('on_hold', $st('Legacy hold')['status']);
        $this->assertSame('completed', $st('Legacy done')['status']);
        $this->assertSame('cancelled', $st('Legacy cancelled')['status']);
        $arch = $st('Legacy archived');
        $this->assertSame('completed', $arch['status'], 'legacy archived becomes completed + archived_at');
        $this->assertSame('2026-01-20 08:00:00', $arch['archived_at'], 'its original archive time is kept');
        $codes = array_column(Database::all('SELECT code FROM projects'), 'code');
        $this->assertCount(6, array_unique($codes), 'every legacy project received a unique code');
        foreach ($codes as $c) {
            $this->assertMatchesRegularExpression('/^VIS-\d{4}-L\d{4}$/', $c, 'legacy codes are visibly marked');
        }
        $this->assertSame(0, (int) Database::one('SELECT COUNT(*) c FROM projects WHERE approved_at IS NOT NULL OR approved_amount_minor IS NOT NULL')['c'], 'no price baseline is invented for legacy projects');
        $this->assertSame(0, (int) Database::one('SELECT COUNT(*) c FROM price_revisions')['c']);

        // client records back-filled from the linked user, not invented
        $c = Database::one('SELECT * FROM clients WHERE id = ?', [$client1]);
        $this->assertSame(['Chitra Client', 'chitra@client.test', 'individual'], [$c['contact_name'], $c['email'], $c['client_type']]);
        $b = Database::one('SELECT * FROM clients WHERE id = ?', [$client2]);
        $this->assertSame(['Bharat Biz', 'business', 'Bharat Traders LLP', 'active'], [$b['contact_name'], $b['client_type'], $b['company_name'], $b['status']]);
        $this->assertSame($cu, (int) $c['user_id'], 'portal login link kept');
        $this->assertSame('9123456789', $b['phone_digits']);

        // access model still answers the same
        $this->assertSame(1, (int) Database::one('SELECT COUNT(*) c FROM project_assignments WHERE user_id = ? AND revoked_at IS NULL', [$dev])['c']);

        // audit history unchanged and the chain verifies
        $this->assertSame($auditBefore, array_slice(Database::all('SELECT * FROM audit_logs ORDER BY id'), 0, count($auditBefore)));
        $this->assertTrue(Audit::verify()['ok'], (string) Audit::verify()['reason']);

        // seeded configuration exists and is neutral
        $this->assertSame(8, (int) Database::one('SELECT COUNT(*) c FROM crm_stages')['c']);
        $this->assertSame(4, (int) Database::one("SELECT COUNT(*) c FROM priority_pricing_rules WHERE surcharge_type = 'none' AND current_flag = 1")['c']);

        // new foreign keys protect financial history from cascading deletes
        foreach (['price_revisions', 'price_change_requests'] as $table) {
            $delete = Database::one("SELECT DELETE_RULE r FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND REFERENCED_TABLE_NAME = 'projects'", [$table]);
            $this->assertSame('RESTRICT', $delete['r'], "{$table} must not cascade from projects");
        }
        // rerun is a no-op
        $this->assertSame([], (new Migrator(VERTEX_ROOT . '/database/migrations'))->migrate());
        array_map('unlink', glob($tmp . '/*') ?: []);
        rmdir($tmp);
    }

    public function testAFailingMigrationIsNeverRecordedAndLeavesEarlierWorkIntact(): void
    {
        self::dropAllTables();
        $tmp = $this->phase1Dir();
        (new Migrator($tmp))->migrate();
        $recorded = Database::all('SELECT migration FROM schema_migrations ORDER BY id');
        file_put_contents($tmp . '/0005_broken.sql', "CREATE TABLE ok_before_failure (id INT PRIMARY KEY);\nALTER TABLE table_that_does_not_exist ADD COLUMN x INT;\n");
        try {
            (new Migrator($tmp))->migrate();
            $this->fail('the broken migration must raise');
        } catch (\Throwable) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame($recorded, Database::all('SELECT migration FROM schema_migrations ORDER BY id'), 'a failed migration is not marked applied');
        $status = (new Migrator($tmp))->status();
        $this->assertSame('pending', end($status)['status'], 'it stays pending so the operator sees it');
        $this->assertNotNull(Database::one("SHOW TABLES LIKE 'users'"), 'earlier migrations are untouched by the failure');
        // The operator's path: restore a backup / fix and add a NEW forward migration (editing applied ones is blocked).
        unlink($tmp . '/0005_broken.sql');
        Database::run('DROP TABLE IF EXISTS ok_before_failure');
        $this->assertSame([], (new Migrator($tmp))->migrate());
        array_map('unlink', glob($tmp . '/*') ?: []);
        rmdir($tmp);
    }
}
