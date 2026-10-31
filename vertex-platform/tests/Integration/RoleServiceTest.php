<?php

declare(strict_types=1);

namespace Vertex\Tests\Integration;

use Vertex\Core\Database;
use Vertex\Core\HttpException;
use Vertex\Core\ValidationException;
use Vertex\Services\Gate;
use Vertex\Services\PermissionSync;
use Vertex\Services\RoleService;

final class RoleServiceTest extends DatabaseTestCase
{
    private int $owner;
    private int $admin;

    protected function setUp(): void
    {
        parent::setUp();
        PermissionSync::run(true); // role grants are not truncated between tests: restore defaults for isolation
        $this->owner = $this->makeUser('owner@x.test', ['super_admin']);
        $this->admin = $this->makeUser('admin@x.test', ['administrator']);
    }

    /** @return list<string> */
    private function granted(string $slug): array
    {
        return RoleService::grantedKeys((int) RoleService::find($slug)['id']);
    }

    private function expect403(callable $fn, string $why): void
    {
        try {
            $fn();
            $this->fail("Expected 403: {$why}");
        } catch (HttpException $e) {
            $this->assertSame(403, $e->status, $why);
        }
    }

    public function testAdministratorCanChangeNonSensitivePermissionsOfLowerRole(): void
    {
        $before = $this->granted('support_staff');
        $this->assertContains('appointments.manage', $before);
        RoleService::setPermissions($this->admin, 'support_staff', array_values(array_diff($before, ['appointments.manage'])));

        $after = $this->granted('support_staff');
        $this->assertNotContains('appointments.manage', $after);
        $audit = Database::one("SELECT * FROM audit_logs WHERE action = 'role.permissions_changed'");
        $this->assertSame($this->admin, (int) $audit['actor_id']);
        $this->assertStringContainsString('appointments.manage', $audit['meta_json']);
        $this->assertStringContainsString('"permissions"', $audit['before_json']);
    }

    public function testAdministratorCannotGrantOrRevokeSensitivePermissions(): void
    {
        $support = $this->granted('support_staff');
        $this->expect403(fn () => RoleService::setPermissions($this->admin, 'support_staff', array_merge($support, ['payments.record'])), 'grant sensitive');
        $mgr = $this->granted('manager');
        $this->expect403(fn () => RoleService::setPermissions($this->admin, 'manager', array_merge($mgr, ['projects.assign'])), 'grant projects.assign to manager');
        $fin = $this->granted('finance_staff');
        $this->expect403(fn () => RoleService::setPermissions($this->admin, 'finance_staff', array_values(array_diff($fin, ['payments.record']))), 'revoke sensitive');
        $this->assertSame($support, $this->granted('support_staff'));
        $this->assertNotContains('projects.assign', $this->granted('manager'));
    }

    public function testOwnerCanChangeSensitivePermissions(): void
    {
        $mgr = $this->granted('manager');
        RoleService::setPermissions($this->owner, 'manager', array_merge($mgr, ['payments.record']));
        $this->assertContains('payments.record', $this->granted('manager'));
    }

    public function testNobodyCanEditLockedOrEqualOrHigherRoles(): void
    {
        $this->expect403(fn () => RoleService::setPermissions($this->owner, 'super_admin', []), 'locked role');
        $admins = $this->granted('administrator');
        $this->expect403(fn () => RoleService::setPermissions($this->admin, 'administrator', array_values(array_diff($admins, ['audit.view']))), 'own level');
        $this->expect403(fn () => RoleService::setPermissions($this->admin, 'super_admin', []), 'higher level');
        $this->assertEqualsCanonicalizing(array_column(Database::all('SELECT perm_key FROM permissions'), 'perm_key'), $this->granted('super_admin'), 'owner role untouched');
    }

    public function testOwnerMayEditTheAdministratorRoleBecauseItIsStrictlyLower(): void
    {
        $admins = $this->granted('administrator');
        RoleService::setPermissions($this->owner, 'administrator', array_values(array_diff($admins, ['legal.manage'])));
        $this->assertNotContains('legal.manage', $this->granted('administrator'));
    }

    public function testPermissionManagementItselfRequiresThePermission(): void
    {
        $mgr = $this->makeUser('mgr@x.test', ['manager']);
        $this->expect403(fn () => RoleService::setPermissions($mgr, 'client', []), 'manager has no roles.manage');
    }

    public function testActorCannotGrantAPermissionTheyDoNotHold(): void
    {
        // Give an administrator-like custom situation: strip a non-sensitive permission from Administrator, then try to hand it out.
        Database::run("DELETE rp FROM role_permissions rp JOIN roles r ON r.id = rp.role_id JOIN permissions p ON p.id = rp.permission_id WHERE r.slug = 'administrator' AND p.perm_key = 'cms.publish'");
        Gate::flush();
        $this->assertFalse(Gate::can('cms.publish', $this->admin));
        $mgr = $this->granted('manager');
        $this->expect403(fn () => RoleService::setPermissions($this->admin, 'manager', array_merge($mgr, ['cms.publish'])), 'cannot grant what you lack');
    }

    public function testUnknownPermissionKeysAreRejected(): void
    {
        $this->expectException(ValidationException::class);
        RoleService::setPermissions($this->admin, 'client', ['projects.view_own', 'root.everything']);
    }

    public function testChangesApplyImmediatelyToUsersHoldingTheRole(): void
    {
        $sup = $this->makeUser('sup@x.test', ['support_staff']);
        $this->assertTrue(Gate::can('bugs.manage', $sup));
        RoleService::setPermissions($this->admin, 'support_staff', array_values(array_diff($this->granted('support_staff'), ['bugs.manage'])));
        $this->assertFalse(Gate::can('bugs.manage', $sup));
    }

    public function testPreserveUneditableKeepsSensitiveGrantsForNonOwners(): void
    {
        // The form omits disabled (sensitive) boxes; saving must not silently strip them.
        $fin = $this->granted('finance_staff');
        $submitted = array_values(array_filter($fin, static fn (string $k): bool => !in_array($k, ['finance.view', 'payments.record'], true)));
        $merged = RoleService::preserveUneditable($this->admin, 'finance_staff', $submitted);
        $this->assertContains('finance.view', $merged);
        $this->assertContains('payments.record', $merged);
        RoleService::setPermissions($this->admin, 'finance_staff', $merged); // no change => no exception
        $this->assertEqualsCanonicalizing($fin, $this->granted('finance_staff'));
    }

    public function testNoOpSaveWritesNoAuditEntry(): void
    {
        RoleService::setPermissions($this->admin, 'client', $this->granted('client'));
        $this->assertSame(0, (int) Database::one("SELECT COUNT(*) c FROM audit_logs WHERE action = 'role.permissions_changed'")['c']);
    }
}
