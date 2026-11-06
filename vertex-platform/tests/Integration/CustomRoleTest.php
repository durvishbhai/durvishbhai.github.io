<?php

declare(strict_types=1);

namespace Vertex\Tests\Integration;

use Vertex\Core\Database;
use Vertex\Core\HttpException;
use Vertex\Core\ValidationException;
use Vertex\Services\Gate;
use Vertex\Services\RoleService;
use Vertex\Services\UserService;

/** Module 17: the owner creates custom roles; nobody can use them to escalate. */
final class CustomRoleTest extends DatabaseTestCase
{
    private int $owner;
    private int $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = $this->makeUser('owner@x.test', ['super_admin']);
        $this->admin = $this->makeUser('admin@x.test', ['administrator']);
    }

    public function testOwnerCreatesARoleWithChosenPermissionsAndItIsAssignable(): void
    {
        $slug = RoleService::create($this->owner, 'Sales Executive', 'Works leads', 40, 'support_staff', ['quotes.view']);
        $this->assertSame('sales_executive', $slug);
        $role = RoleService::find($slug);
        $this->assertSame(0, (int) $role['is_system']);
        $keys = RoleService::grantedKeys((int) $role['id']);
        $this->assertContains('quotes.view', $keys);
        $this->assertContains('tickets.reply', $keys, 'copied from Support Staff');
        $this->assertContains($slug, UserService::assignableRoleSlugs($this->owner));
        $this->assertContains($slug, UserService::assignableRoleSlugs($this->admin));
        // a user can hold it and gets exactly those permissions
        $u = $this->makeUser('sales@x.test', [$slug]);
        $this->assertTrue(Gate::can('quotes.view', $u));
        $this->assertFalse(Gate::can('finance.view', $u));
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) c FROM audit_logs WHERE action = 'role.created'")['c']);
    }

    public function testAdministratorCannotCreateOrDeleteRoles(): void
    {
        $this->expectException(HttpException::class);
        RoleService::create($this->admin, 'Sneaky', '', 40, null, []);
    }

    public function testCannotOutrankSelfOrCollideWithABuiltInRole(): void
    {
        foreach ([[100, 'Boss'], [95, 'Boss'], [5, 'Tiny'], [40, 'Administrator'], [40, 'ab'], [40, '123']] as [$level, $name]) {
            try {
                RoleService::create($this->owner, $name, '', $level, null, []);
                $this->fail("created {$name} at {$level}");
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(0, (int) Database::one('SELECT COUNT(*) c FROM roles WHERE is_system = 0')['c']);
    }

    public function testPermissionsTheCreatorDoesNotHoldAreNeverGranted(): void
    {
        // give the administrator the ability to create roles to prove the "you cannot grant what you lack" rule
        Database::run("INSERT INTO role_permissions (role_id, permission_id) SELECT r.id, p.id FROM roles r, permissions p WHERE r.slug = 'administrator' AND p.perm_key IN ('roles.create')");
        Gate::flush();
        $slug = RoleService::create($this->admin, 'Helper Role', '', 40, null, ['settings.manage', 'roles.manage_sensitive', 'tickets.view_all']);
        $keys = RoleService::grantedKeys((int) RoleService::find($slug)['id']);
        $this->assertNotContains('settings.manage', $keys, 'the administrator does not hold it');
        $this->assertNotContains('roles.manage_sensitive', $keys);
    }

    public function testDeleteOnlyWhenUnusedAndNeverForBuiltInRoles(): void
    {
        $slug = RoleService::create($this->owner, 'Temp Role', '', 30, null, ['quotes.view']);
        $this->makeUser('t@x.test', [$slug]);
        try {
            RoleService::delete($this->owner, $slug);
            $this->fail('deleted a role that is in use');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('still hold', $e->errors[0] ?? '');
        }
        Database::run('DELETE FROM user_roles WHERE role_id = ?', [RoleService::find($slug)['id']]);
        RoleService::delete($this->owner, $slug);
        $this->assertNull(RoleService::find($slug));
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) c FROM audit_logs WHERE action = 'role.deleted'")['c']);
        $this->expectException(HttpException::class);
        RoleService::delete($this->owner, 'client');
    }

    public function testPermissionSyncKeepsCustomRolesAndTheirGrants(): void
    {
        $slug = RoleService::create($this->owner, 'Keeper', '', 30, null, ['quotes.view']);
        \Vertex\Services\PermissionSync::run();
        $this->assertContains('quotes.view', RoleService::grantedKeys((int) RoleService::find($slug)['id']));
        \Vertex\Services\PermissionSync::run(true);
        $this->assertNotNull(RoleService::find($slug), 'even --reset-roles never touches custom roles');
    }
}
