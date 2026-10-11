<?php

declare(strict_types=1);

namespace Vertex\Tests\Integration;

use Vertex\Core\Database;
use Vertex\Repositories\UserRepository;
use Vertex\Services\ProjectAssignmentService;

/**
 * Authorization through real HTTP: role gates, direct-URL access to other people's records, privilege-escalation
 * attempts with crafted requests, step-up confirmation, deactivation of a live session, and audit-log immutability.
 */
final class AccessControlHttpTest extends HttpTestCase
{
    private int $owner;
    private int $admin;
    private int $manager;
    private int $finance;
    private int $support;
    private int $dev;
    private int $dev2;
    private int $partner;
    private int $clientA;
    private int $clientB;
    private array $projA;
    private array $projB;

    protected function setUp(): void
    {
        parent::setUp();
        \Vertex\Services\PermissionSync::run(true);
        $this->owner = $this->makeUser('owner@x.test', ['super_admin']);
        $this->admin = $this->makeUser('admin@x.test', ['administrator']);
        $this->manager = $this->makeUser('mgr@x.test', ['manager']);
        $this->finance = $this->makeUser('fin@x.test', ['finance_staff']);
        $this->support = $this->makeUser('sup@x.test', ['support_staff']);
        $this->dev = $this->makeUser('dev@x.test', ['website_developer']);
        $this->dev2 = $this->makeUser('dev2@x.test', ['website_developer']);
        $this->partner = $this->makeUser('ref@x.test', ['referral_partner']);
        $this->clientA = $this->makeUser('ca@x.test', ['client']);
        $this->clientB = $this->makeUser('cb@x.test', ['client']);
        $this->projA = $this->makeProject($this->clientA, 'Alpha Website');
        $this->projB = $this->makeProject($this->clientB, 'Bravo Video');
        ProjectAssignmentService::assign($this->admin, $this->projA['id'], $this->manager, 'lead');
        ProjectAssignmentService::assign($this->admin, $this->projA['id'], $this->dev);
    }

    private function roles(int $id): array
    {
        return array_column((new UserRepository())->rolesOf($id), 'slug');
    }

    private function statusOf(int $id): string
    {
        return (string) Database::one('SELECT status FROM users WHERE id = ?', [$id])['status'];
    }

    // ---- direct URL access / IDOR -----------------------------------------------------------------------

    public function testClientCanOpenOwnProjectButGets404ForAnotherClientsProject(): void
    {
        $a = $this->loggedIn('ca@x.test');
        $this->assertSame(200, $a->get('/projects/' . $this->projA['public_id'])['status']);
        $this->assertStringContainsString('Alpha Website', $a->get('/projects/' . $this->projA['public_id'])['body']);

        $r = $a->get('/projects/' . $this->projB['public_id']);
        $this->assertSame(404, $r['status'], 'cross-client access by URL');
        $this->assertStringNotContainsString('Bravo Video', $r['body']);
    }

    public function testGuessingOrTamperingWithProjectIdsNeverWorks(): void
    {
        $a = $this->loggedIn('ca@x.test');
        $ids = ['1', '2', '0', '-1', (string) $this->projB['id'], '1 OR 1=1', "'", '../../etc/passwd', '..%2fadmin',  str_repeat('0', 32), strtoupper($this->projB['public_id']), $this->projB['public_id'] . 'x', substr($this->projB['public_id'], 0, 31)];
        foreach ($ids as $id) {
            $r = $a->get('/projects/' . rawurlencode($id));
            $this->assertSame(404, $r['status'], "id '{$id}'");
        }
        // Other project-scoped verbs under a foreign id are equally dead (403 from the permission gate or 404 from the policy).
        $r = $a->post('/projects/' . $this->projB['public_id'] . '/assignments', ['user' => $this->publicId($this->dev2)], '/account');
        $this->assertContains($r['status'], [403, 404]);
        $this->assertSame(404, $this->loggedIn('dev2@x.test')->get('/projects/' . $this->projB['public_id'])['status'], 'nothing was assigned');
    }

    public function testWorkersSeeOnlyAssignedProjectsAndNotEachOthers(): void
    {
        $dev = $this->loggedIn('dev@x.test');
        $this->assertSame(200, $dev->get('/projects/' . $this->projA['public_id'])['status']);
        $this->assertSame(404, $dev->get('/projects/' . $this->projB['public_id'])['status']);
        $list = $dev->get('/projects')['body'];
        $this->assertStringContainsString('Alpha Website', $list);
        $this->assertStringNotContainsString('Bravo Video', $list);

        $dev2 = $this->loggedIn('dev2@x.test'); // same role, not assigned
        $this->assertSame(404, $dev2->get('/projects/' . $this->projA['public_id'])['status'], 'cross-worker isolation');
        $this->assertStringNotContainsString('Alpha Website', $dev2->get('/projects')['body']);
    }

    public function testManagerSeesOnlyAssignedAdminSeesAll(): void
    {
        $m = $this->loggedIn('mgr@x.test');
        $this->assertSame(200, $m->get('/projects/' . $this->projA['public_id'])['status']);
        $this->assertSame(404, $m->get('/projects/' . $this->projB['public_id'])['status'], 'managers are not admins');

        $a = $this->loggedIn('admin@x.test');
        $this->assertSame(200, $a->get('/projects/' . $this->projA['public_id'])['status']);
        $this->assertSame(200, $a->get('/projects/' . $this->projB['public_id'])['status']);
    }

    public function testFinanceStaffAndReferralPartnersHaveNoProjectAccessAtAll(): void
    {
        foreach (['fin@x.test', 'ref@x.test'] as $email) {
            $c = $this->loggedIn($email);
            $this->assertSame(403, $c->get('/projects')['status'], $email);
            $this->assertSame(404, $c->get('/projects/' . $this->projA['public_id'])['status'], $email);
        }
    }

    public function testRevokingAssignmentCutsAccessOnTheVeryNextRequest(): void
    {
        $dev = $this->loggedIn('dev@x.test');
        $this->assertSame(200, $dev->get('/projects/' . $this->projA['public_id'])['status']);
        ProjectAssignmentService::revoke($this->admin, $this->projA['id'], $this->dev);
        $this->assertSame(404, $dev->get('/projects/' . $this->projA['public_id'])['status']);
    }

    // ---- who may assign -------------------------------------------------------------------------------------

    public function testOnlyAdminsCanAssignProjectsThroughTheUi(): void
    {
        $form = ['user' => $this->publicId($this->dev2), 'project_role' => 'member'];
        foreach (['mgr@x.test', 'dev@x.test', 'ca@x.test'] as $email) {
            $c = $this->loggedIn($email);
            $r = $c->post('/projects/' . $this->projA['public_id'] . '/assignments', $form, '/account');
            $this->assertSame(403, $r['status'], "{$email} must not assign");
        }
        $this->assertSame(404, $this->loggedIn('dev2@x.test')->get('/projects/' . $this->projA['public_id'])['status']);

        $admin = $this->loggedIn('admin@x.test');
        $this->assertSame(303, $admin->post('/projects/' . $this->projA['public_id'] . '/assignments', $form, '/account')['status']);
        $this->assertSame(200, $this->loggedIn('dev2@x.test')->get('/projects/' . $this->projA['public_id'])['status']);

        $this->assertSame(303, $admin->post('/projects/' . $this->projA['public_id'] . '/assignments/' . $this->publicId($this->dev2) . '/revoke', [], '/account')['status']);
        $this->assertSame(404, $this->loggedIn('dev2@x.test')->get('/projects/' . $this->projA['public_id'])['status']);
    }

    public function testAssignmentFormIsOnlyRenderedForThoseWhoCanAssign(): void
    {
        $this->assertStringContainsString('/assignments', $this->loggedIn('admin@x.test')->get('/projects/' . $this->projA['public_id'])['body']);
        $this->assertStringNotContainsString('/assignments', $this->loggedIn('mgr@x.test')->get('/projects/' . $this->projA['public_id'])['body']);
    }

    // ---- role gates on admin screens -----------------------------------------------------------------------------

    public function testAdminScreensAreGatedByPermissionNotJustHiddenLinks(): void
    {
        $matrix = [
            '/admin/users' => ['owner@x.test' => 200, 'admin@x.test' => 200, 'mgr@x.test' => 403, 'fin@x.test' => 403, 'sup@x.test' => 403, 'dev@x.test' => 403, 'ref@x.test' => 403, 'ca@x.test' => 403],
            '/admin/users/create' => ['owner@x.test' => 200, 'admin@x.test' => 200, 'mgr@x.test' => 403, 'ca@x.test' => 403],
            '/admin/roles' => ['owner@x.test' => 200, 'admin@x.test' => 200, 'mgr@x.test' => 403, 'fin@x.test' => 403, 'ca@x.test' => 403],
            '/admin/roles/client' => ['owner@x.test' => 200, 'admin@x.test' => 200, 'mgr@x.test' => 403],
            '/admin/audit' => ['owner@x.test' => 200, 'admin@x.test' => 200, 'mgr@x.test' => 403, 'fin@x.test' => 403, 'sup@x.test' => 403, 'ca@x.test' => 403],
        ];
        foreach ($matrix as $path => $expect) {
            foreach ($expect as $email => $code) {
                $this->assertSame($code, $this->loggedIn($email)->get($path)['status'], "{$email} -> {$path}");
            }
        }
    }

    public function testNavigationOnlyShowsWhatTheUserMayUseAndServerStillEnforces(): void
    {
        $body = $this->loggedIn('mgr@x.test')->get('/dashboard')['body'];
        $this->assertStringNotContainsString('/admin/users', $body);
        $this->assertStringNotContainsString('/admin/audit', $body);
        $this->assertStringContainsString('/projects', $body);
        $adminBody = $this->loggedIn('admin@x.test')->get('/dashboard')['body'];
        $this->assertStringContainsString('/admin/users', $adminBody);
        $this->assertStringContainsString('/admin/audit', $adminBody);
    }

    public function testStatePostsFromUnauthorizedRolesAreForbiddenEvenWithAValidCsrfToken(): void
    {
        $mgr = $this->loggedIn('mgr@x.test');
        $target = $this->publicId($this->clientA);
        $this->assertSame(403, $mgr->post("/admin/users/{$target}/roles", ['roles' => ['administrator'], 'confirm_password' => self::PASSWORD], '/account')['status']);
        $this->assertSame(403, $mgr->post("/admin/users/{$target}/deactivate", ['reason' => 'x', 'confirm_password' => self::PASSWORD], '/account')['status']);
        $this->assertSame(403, $mgr->post("/admin/users/{$target}", ['name' => 'Hacked', 'email' => 'ca@x.test'], '/account')['status']);
        $this->assertSame(403, $mgr->post('/admin/roles/client', ['permissions' => ['projects.view_all'], 'confirm_password' => self::PASSWORD], '/account')['status']);
        $this->assertSame(403, $mgr->post('/admin/users', ['name' => 'N', 'email' => 'n@x.test', 'roles' => ['client']], '/account')['status']);
        $this->assertSame(['client'], $this->roles($this->clientA));
        $this->assertSame('active', $this->statusOf($this->clientA));
        $this->assertNull((new UserRepository())->findByEmail('n@x.test'));
    }

    // ---- privilege escalation with crafted requests ---------------------------------------------------------------

    public function testProfileUpdateWithCraftedFieldsCannotChangeRolesStatusOrEmail(): void
    {
        $c = $this->loggedIn('mgr@x.test');
        $r = $c->post('/account', [
            'name' => 'Still A Manager', 'phone' => '+91 90000 44444', 'timezone' => 'Asia/Tokyo',
            'role' => 'super_admin', 'roles' => ['super_admin', 'administrator'], 'roles[]' => 'super_admin',
            'status' => 'active', 'email' => 'attacker@x.test', 'level' => '100', 'user_id' => (string) $this->owner, 'permissions' => ['users.create'],
        ], '/account');
        $this->assertSame(303, $r['status']);
        $u = Database::one('SELECT * FROM users WHERE id = ?', [$this->manager]);
        $this->assertSame('Still A Manager', $u['name']);
        $this->assertSame('mgr@x.test', $u['email']);
        $this->assertSame('Asia/Tokyo', $u['timezone']);
        $this->assertSame(['manager'], $this->roles($this->manager));
        $this->assertSame(403, $c->get('/admin/users')['status'], 'still no admin access');
        $this->assertSame(['super_admin'], $this->roles($this->owner));
    }

    public function testCannotChangeOwnRolesOrStatusEvenAsAdministrator(): void
    {
        $c = $this->loggedIn('admin@x.test');
        $me = $this->publicId($this->admin);
        $this->assertSame(403, $c->post("/admin/users/{$me}/roles", ['roles' => ['administrator', 'manager'], 'confirm_password' => self::PASSWORD], '/account')['status']);
        $this->assertSame(403, $c->post("/admin/users/{$me}/deactivate", ['reason' => 'x', 'confirm_password' => self::PASSWORD], '/account')['status']);
        $this->assertSame(403, $c->post("/admin/users/{$me}", ['name' => 'X', 'email' => 'admin@x.test'], '/account')['status'], 'own record goes through /account only');
        $this->assertSame(['administrator'], $this->roles($this->admin));
        $this->assertSame('active', $this->statusOf($this->admin));
    }

    public function testAdministratorCannotPromoteToAdministratorOrOwnerAndCannotTouchTheOwner(): void
    {
        $c = $this->loggedIn('admin@x.test');
        $t = $this->publicId($this->manager);
        $this->assertSame(403, $c->post("/admin/users/{$t}/roles", ['roles' => ['administrator'], 'confirm_password' => self::PASSWORD], '/account')['status']);
        $this->assertSame(403, $c->post("/admin/users/{$t}/roles", ['roles' => ['super_admin'], 'confirm_password' => self::PASSWORD], '/account')['status']);
        $this->assertSame(['manager'], $this->roles($this->manager));

        $owner = $this->publicId($this->owner);
        $this->assertSame(403, $c->post("/admin/users/{$owner}/deactivate", ['reason' => 'coup', 'confirm_password' => self::PASSWORD], '/account')['status']);
        $this->assertSame(403, $c->post("/admin/users/{$owner}/roles", ['roles' => ['client'], 'confirm_password' => self::PASSWORD], '/account')['status']);
        $this->assertSame(403, $c->post("/admin/users/{$owner}", ['name' => 'Owned', 'email' => 'owner@x.test'], '/account')['status']);
        $this->assertSame(403, $c->post("/admin/users/{$owner}/archive", ['confirm_password' => self::PASSWORD], '/account')['status']);
        $this->assertSame('active', $this->statusOf($this->owner));
        $this->assertSame(['super_admin'], $this->roles($this->owner));
    }

    public function testAdministratorCannotGrantSensitivePermissionsThroughTheRoleEditor(): void
    {
        $c = $this->loggedIn('admin@x.test');
        $supportPerms = array_column(Database::all("SELECT p.perm_key FROM role_permissions rp JOIN roles r ON r.id = rp.role_id JOIN permissions p ON p.id = rp.permission_id WHERE r.slug = 'support_staff'"), 'perm_key');
        $r = $c->post('/admin/roles/support_staff', ['permissions' => array_merge($supportPerms, ['payments.record', 'projects.assign']), 'confirm_password' => self::PASSWORD], '/account');
        $this->assertSame(403, $r['status']);
        $this->assertSame(0, (int) Database::one("SELECT COUNT(*) c FROM role_permissions rp JOIN roles r ON r.id = rp.role_id JOIN permissions p ON p.id = rp.permission_id WHERE r.slug = 'support_staff' AND p.perm_key IN ('payments.record','projects.assign')")['c']);
        $blocked = Database::one("SELECT actor_id, meta_json FROM audit_logs WHERE action = 'security.sensitive_grant_blocked'");
        $this->assertSame($this->admin, (int) $blocked['actor_id'], 'the attempt itself is audited');
        $this->assertStringContainsString('payments.record', $blocked['meta_json']);

        // ...but a harmless non-sensitive edit of a lower role works, and the editor does not strip sensitive grants it could not see
        $finPerms = array_column(Database::all("SELECT p.perm_key FROM role_permissions rp JOIN roles r ON r.id = rp.role_id JOIN permissions p ON p.id = rp.permission_id WHERE r.slug = 'finance_staff'"), 'perm_key');
        $sensitiveBefore = array_values(array_intersect($finPerms, ['finance.view', 'payments.record']));
        $submitted = array_values(array_diff($finPerms, ['finance.view', 'payments.record', 'commissions.view_all'])); // boxes the admin could tick; sensitive ones are disabled in the UI
        $ok = $c->post('/admin/roles/finance_staff', ['permissions' => $submitted, 'confirm_password' => self::PASSWORD], '/account');
        $this->assertSame(303, $ok['status']);
        $after = array_column(Database::all("SELECT p.perm_key FROM role_permissions rp JOIN roles r ON r.id = rp.role_id JOIN permissions p ON p.id = rp.permission_id WHERE r.slug = 'finance_staff'"), 'perm_key');
        $this->assertNotContains('commissions.view_all', $after);
        foreach ($sensitiveBefore as $k) {
            $this->assertContains($k, $after, "{$k} must survive an edit by someone who cannot touch sensitive grants");
        }
    }

    public function testOwnerCannotEditTheLockedOwnerRoleAndEditorShowsItReadOnly(): void
    {
        $c = $this->loggedIn('owner@x.test');
        $this->assertSame(403, $c->post('/admin/roles/super_admin', ['permissions' => [], 'confirm_password' => self::PASSWORD], '/account')['status']);
        $page = $c->get('/admin/roles/super_admin')['body'];
        $this->assertStringContainsString('locked', $page);
        $this->assertStringNotContainsString('Save permissions', $page);
    }

    // ---- step-up confirmation ------------------------------------------------------------------------------------

    public function testSensitiveActionsRequireTheActorsPasswordOnTheServer(): void
    {
        $c = $this->loggedIn('admin@x.test');
        $t = $this->publicId($this->dev2);

        foreach ([[], ['confirm_password' => ''], ['confirm_password' => 'wrong-password']] as $extra) {
            $r = $c->post("/admin/users/{$t}/deactivate", ['reason' => 'test'] + $extra, '/account');
            $this->assertSame(303, $r['status']);
            $this->assertSame('active', $this->statusOf($this->dev2), 'no deactivation without the correct password');
        }
        $this->assertStringContainsString('password was missing or incorrect', $c->get('/admin/users/' . $t)['body']);

        $r = $c->post("/admin/users/{$t}/deactivate", ['reason' => 'Contract ended', 'confirm_password' => self::PASSWORD], '/account');
        $this->assertSame(303, $r['status']);
        $this->assertSame('deactivated', $this->statusOf($this->dev2));

        $c->post("/admin/users/{$t}/roles", ['roles' => ['freelancer']], '/account');
        $this->assertSame(['website_developer'], $this->roles($this->dev2), 'role change also needs the password');
    }

    // ---- deactivation of a live session ------------------------------------------------------------------------

    public function testDeactivatingAUserKillsTheirLiveSessionImmediately(): void
    {
        $victim = $this->loggedIn('dev@x.test');
        $this->assertSame(200, $victim->get('/dashboard')['status']);
        $this->assertSame(200, $victim->get('/projects/' . $this->projA['public_id'])['status']);

        $admin = $this->loggedIn('admin@x.test');
        $r = $admin->post('/admin/users/' . $this->publicId($this->dev) . '/deactivate', ['reason' => 'Security incident', 'confirm_password' => self::PASSWORD], '/account');
        $this->assertSame(303, $r['status']);

        $this->assertSame(303, $victim->get('/dashboard')['status'], 'next request is already unauthenticated');
        $this->assertSame('/login', $victim->get('/projects/' . $this->projA['public_id'])['location']);
        $fresh = $this->client();
        $fresh->login('dev@x.test', self::PASSWORD);
        $this->assertSame(303, $fresh->get('/dashboard')['status'], 'cannot log back in');

        // reactivation restores the ability to sign in
        $admin->post('/admin/users/' . $this->publicId($this->dev) . '/reactivate', ['confirm_password' => self::PASSWORD], '/account');
        $this->assertSame(200, $this->loggedIn('dev@x.test')->get('/dashboard')['status']);
    }

    public function testChangingARolesPermissionsOrAUsersRolesTakesEffectOnTheNextRequest(): void
    {
        $mgr = $this->loggedIn('mgr@x.test');
        $this->assertSame(403, $mgr->get('/admin/users')['status']);
        $owner = $this->loggedIn('owner@x.test');
        $managerPerms = array_column(Database::all("SELECT p.perm_key FROM role_permissions rp JOIN roles r ON r.id = rp.role_id JOIN permissions p ON p.id = rp.permission_id WHERE r.slug = 'manager'"), 'perm_key');
        $r = $owner->post('/admin/roles/manager', ['permissions' => array_merge($managerPerms, ['users.view']), 'confirm_password' => self::PASSWORD], '/account');
        $this->assertSame(303, $r['status']);
        $this->assertSame(200, $mgr->get('/admin/users')['status'], 'live session picks up the new grant without re-login');
        $this->assertSame(403, $mgr->get('/admin/users/create')['status'], 'but only that grant');
    }

    // ---- audit log: viewing, filtering, immutability --------------------------------------------------------------------

    public function testAuditLogIsRecordedViewableFilterableAndImmutable(): void
    {
        $admin = $this->loggedIn('admin@x.test');
        $admin->post('/admin/users/' . $this->publicId($this->dev2) . '/deactivate', ['reason' => 'Because', 'confirm_password' => self::PASSWORD], '/account');
        $admin->post('/admin/users/' . $this->publicId($this->dev2) . '/reactivate', ['confirm_password' => self::PASSWORD], '/account');

        $page = $admin->get('/admin/audit')['body'];
        foreach (['auth.login.success', 'user.deactivated', 'user.reactivated', 'project.member_assigned'] as $action) {
            $this->assertStringContainsString($action, $page, "{$action} should be visible");
        }
        $filtered = $admin->get('/admin/audit?action=deactivated')['body'];
        $this->assertStringContainsString('user.deactivated', $filtered);
        $this->assertStringNotContainsString('user.reactivated', $filtered);
        $this->assertStringNotContainsString('auth.login.success', $filtered);
        $this->assertStringContainsString('Because', $admin->get('/admin/audit?action=user.deactivated')['body'], 'details show the reason');

        $this->assertStringNotContainsString('hunter2', $page);
        $this->assertStringNotContainsString(self::PASSWORD, $page);
        foreach ($this->auditHtmlForms($page) as $action) {
            $this->assertSame('/admin/audit/verify', $action, 'the only form on the audit page is the integrity check');
        }

        // no route can modify history
        foreach (['DELETE', 'PUT', 'PATCH'] as $method) {
            $r = $admin->request($method, '/admin/audit/1', ['_token' => $admin->token('/account')]);
            $this->assertContains($r['status'], [404, 405, 419], "{$method} /admin/audit/1");
        }
        $this->assertContains($admin->post('/admin/audit/1', ['_method' => 'DELETE'], '/account')['status'], [404, 405]);
        $before = (int) Database::one('SELECT COUNT(*) c FROM audit_logs')['c'];
        $this->assertGreaterThan(0, $before);
        $this->assertTrue(\Vertex\Services\Audit::verify()['ok']);

        $v = $admin->post('/admin/audit/verify', [], '/account');
        $this->assertSame(303, $v['status']);
        $this->assertStringContainsString('chain intact', $admin->get('/admin/audit')['body']);
    }

    public function testAuditVerifyReportsTamperingInTheUi(): void
    {
        $admin = $this->loggedIn('admin@x.test');
        $id = (int) Database::one("SELECT id FROM audit_logs WHERE action = 'auth.login.success' ORDER BY id LIMIT 1")['id'];
        Database::run("UPDATE audit_logs SET action = 'auth.login.forged' WHERE id = ?", [$id]);
        $admin->post('/admin/audit/verify', [], '/account');
        $this->assertStringContainsString('INTEGRITY FAILURE', $admin->get('/admin/audit')['body']);
    }

    // ---- user list: pagination, search, filters -------------------------------------------------------------------------

    public function testUserListPaginatesSearchesAndFilters(): void
    {
        for ($i = 1; $i <= 30; $i++) {
            $this->makeUser(sprintf('bulk%02d@x.test', $i), ['client']);
        }
        $this->makeUser('special%name@x.test', ['support_staff']);
        $a = $this->loggedIn('admin@x.test');

        $p1 = $a->get('/admin/users')['body'];
        $this->assertSame(20, substr_count($p1, '/admin/users/') - substr_count($p1, '/admin/users/create'), 'page size 20');
        $this->assertStringContainsString('Page 1 of 3', $p1);
        $p3 = $a->get('/admin/users?page=3')['body'];
        $this->assertStringContainsString('Page 3 of 3', $p3);
        $this->assertStringContainsString('Previous', $p3);

        $found = $a->get('/admin/users?q=bulk07')['body'];
        $this->assertStringContainsString('bulk07@x.test', $found);
        $this->assertStringNotContainsString('bulk08@x.test', $found);

        $wild = $a->get('/admin/users?q=' . rawurlencode('%'))['body'];
        $this->assertStringContainsString('special%name@x.test', $wild);
        $this->assertStringNotContainsString('bulk07@x.test', $wild, 'LIKE wildcards in the search box are escaped');

        $roleOnly = $a->get('/admin/users?role=support_staff')['body'];
        $this->assertStringContainsString('sup@x.test', $roleOnly);
        $this->assertStringNotContainsString('bulk07@x.test', $roleOnly);
        $this->assertStringContainsString('No users match', $a->get('/admin/users?q=zzzzzz')['body']);
        $this->assertSame(200, $a->get('/admin/users?page=-5&status=bogus&role=%27')['status'], 'bad filters are ignored, not fatal');
    }

    public function testUserDetailPageNeverContainsPasswordHashesOrTokens(): void
    {
        $a = $this->loggedIn('admin@x.test');
        $hash = (string) Database::one('SELECT password_hash FROM users WHERE id = ?', [$this->dev])['password_hash'];
        $body = $a->get('/admin/users/' . $this->publicId($this->dev))['body'] . $a->get('/admin/users')['body'];
        $this->assertStringNotContainsString($hash, $body);
        $this->assertStringNotContainsString('password_hash', $body);
        $this->assertSame(404, $a->get('/admin/users/' . str_repeat('f', 32))['status']);
        $this->assertSame(404, $a->get('/admin/users/1')['status'], 'sequential ids are not accepted');
    }

    /** @return list<string> form actions in an HTML page, excluding logout */
    private function auditHtmlForms(string $html): array
    {
        preg_match_all('/<form[^>]*action="([^"]*)"/', $html, $m);

        return array_values(array_filter($m[1], static fn (string $a): bool => !in_array($a, ['/logout', '/admin/audit'], true)));
    }
}
