<?php

declare(strict_types=1);

namespace Vertex\Tests\Integration;

use Vertex\Core\Config;
use Vertex\Core\Database;
use Vertex\Core\HttpException;
use Vertex\Core\ValidationException;
use Vertex\Repositories\UserRepository;
use Vertex\Services\Gate;
use Vertex\Services\Mailer;
use Vertex\Services\Registration;
use Vertex\Services\UserService;

/** Privilege-escalation and account-lifecycle rules, tested directly against the service layer. */
final class UserServiceTest extends DatabaseTestCase
{
    private int $owner;
    private int $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('mail.driver', 'array');
        Config::set('app.url', 'https://portal.example.test');
        $this->owner = $this->makeUser('owner@x.test', ['super_admin']);
        $this->admin = $this->makeUser('admin@x.test', ['administrator']);
    }

    protected function tearDown(): void
    {
        Config::set('auth.registration_mode', '');
        Config::set('auth.registration_enabled', false);
        Config::set('mail.driver', 'none');
        Config::set('app.url', '');
    }

    private function roles(int $id): array
    {
        return array_column((new UserRepository())->rolesOf($id), 'slug');
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

    // ---- creating users --------------------------------------------------------------------------------

    public function testAdminCreatesUserPendingWithInviteAndNoKnownPassword(): void
    {
        $res = UserService::adminCreate($this->admin, ['name' => 'New Dev', 'email' => 'NewDev@x.test', 'phone' => '+91 90000 11111', 'roles' => ['website_developer']]);
        $u = Database::one('SELECT * FROM users WHERE id = ?', [$res['user_id']]);
        $this->assertSame('pending', $u['status']);
        $this->assertSame('newdev@x.test', $u['email']);
        $this->assertNull($u['email_verified_at']);
        $this->assertSame(['website_developer'], $this->roles($res['user_id']));
        $this->assertTrue($res['invite_sent']);
        $this->assertCount(1, Mailer::$sent);
        $this->assertStringContainsString('/invite/', Mailer::$sent[0]['body']);
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) c FROM audit_logs WHERE action = 'user.created'")['c']);
    }

    public function testDuplicateEmailIsRejectedCaseInsensitively(): void
    {
        UserService::adminCreate($this->admin, ['name' => 'A', 'email' => 'dup@x.test', 'phone' => '+91 90000 11111', 'roles' => ['client']]);
        $this->expectException(ValidationException::class);
        UserService::adminCreate($this->admin, ['name' => 'B', 'email' => 'DUP@X.test', 'phone' => '+91 90000 11111', 'roles' => ['client']]);
    }

    public function testCreationRequiresPermissionAndRolesAndPhoneWhereRequired(): void
    {
        $mgr = $this->makeUser('mgr@x.test', ['manager']);
        $this->expect403(fn () => UserService::adminCreate($mgr, ['name' => 'A', 'email' => 'a@x.test', 'roles' => ['client']]), 'manager cannot create users');

        $this->expectException(ValidationException::class);
        UserService::adminCreate($this->admin, ['name' => 'Needs Phone', 'email' => 'np@x.test', 'phone' => '', 'roles' => ['freelancer']]);
    }

    public function testAdministratorCannotCreateAdministratorsOrOwners(): void
    {
        $this->expect403(fn () => UserService::adminCreate($this->admin, ['name' => 'A', 'email' => 'a1@x.test', 'roles' => ['administrator']]), 'admin must not mint admins');
        $this->expect403(fn () => UserService::adminCreate($this->admin, ['name' => 'A', 'email' => 'a2@x.test', 'roles' => ['super_admin']]), 'admin must not mint owners');
        $this->assertSame(0, (int) Database::one("SELECT COUNT(*) c FROM users WHERE email IN ('a1@x.test','a2@x.test')")['c']);
    }

    public function testNobodyCanCreateSuperAdminThroughTheService_EvenTheOwner(): void
    {
        $this->expect403(fn () => UserService::adminCreate($this->owner, ['name' => 'A', 'email' => 'o2@x.test', 'roles' => ['super_admin']]), 'owner role is console-only');
    }

    public function testOwnerCanCreateAdministrators(): void
    {
        $res = UserService::adminCreate($this->owner, ['name' => 'Second Admin', 'email' => 'adm2@x.test', 'roles' => ['administrator']]);
        $this->assertSame(['administrator'], $this->roles($res['user_id']));
    }

    public function testCreatingAClientAlsoCreatesTheClientProfile(): void
    {
        $res = UserService::adminCreate($this->admin, ['name' => 'Cli', 'email' => 'cli@x.test', 'phone' => '+91 90000 11111', 'roles' => ['client']]);
        $this->assertNotNull(Database::one('SELECT 1 AS x FROM clients WHERE user_id = ?', [$res['user_id']]));
    }

    // ---- role changes / escalation ---------------------------------------------------------------------

    public function testUserCannotChangeOwnRoles(): void
    {
        $this->expect403(fn () => UserService::setRoles($this->admin, $this->admin, ['administrator', 'client']), 'self role change');
        $mgr = $this->makeUser('mgr@x.test', ['manager']);
        $this->expect403(fn () => UserService::setRoles($mgr, $mgr, ['super_admin']), 'manager self-escalation');
        $this->assertSame(['manager'], $this->roles($mgr));
    }

    public function testManagerAndOtherNonAdminsCannotChangeAnyRoles(): void
    {
        $mgr = $this->makeUser('mgr@x.test', ['manager']);
        $target = $this->makeUser('t@x.test', ['client']);
        foreach (['manager', 'finance_staff', 'support_staff', 'freelancer', 'client'] as $slug) {
            $actor = $this->makeUser("actor-{$slug}@x.test", [$slug]);
            $this->expect403(fn () => UserService::setRoles($actor, $target, ['manager']), "{$slug} must not assign roles");
        }
        $this->assertSame(['client'], $this->roles($target));
    }

    public function testAdministratorCanAssignLowerRolesButNotEqualOrHigher(): void
    {
        $target = $this->makeUser('t@x.test', ['freelancer']);
        UserService::setRoles($this->admin, $target, ['manager', 'freelancer']);
        $this->assertEqualsCanonicalizing(['manager', 'freelancer'], $this->roles($target));

        $this->expect403(fn () => UserService::setRoles($this->admin, $target, ['administrator']), 'cannot grant own level');
        $this->expect403(fn () => UserService::setRoles($this->admin, $target, ['super_admin']), 'cannot grant owner');
        $this->assertEqualsCanonicalizing(['manager', 'freelancer'], $this->roles($target));
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) c FROM audit_logs WHERE action = 'user.roles_changed'")['c']);
    }

    public function testAdministratorCannotTouchOtherAdministratorsOrTheOwner(): void
    {
        $admin2 = $this->makeUser('admin2@x.test', ['administrator']);
        $this->expect403(fn () => UserService::setRoles($this->admin, $admin2, ['client']), 'demote peer');
        $this->expect403(fn () => UserService::deactivate($this->admin, $admin2, 'x'), 'deactivate peer');
        $this->expect403(fn () => UserService::deactivate($this->admin, $this->owner, 'x'), 'deactivate owner');
        $this->expect403(fn () => UserService::adminUpdate($this->admin, $this->owner, ['name' => 'Hacked', 'email' => 'owner@x.test']), 'edit owner');
        $this->expect403(fn () => UserService::archive($this->admin, $this->owner), 'archive owner');
        $this->assertSame('active', Database::one('SELECT status FROM users WHERE id = ?', [$this->owner])['status']);
    }

    public function testRoleChangeRequiresAtLeastOneRoleAndRejectsUnknownRoles(): void
    {
        $t = $this->makeUser('t@x.test', ['client']);
        try {
            UserService::setRoles($this->admin, $t, []);
            $this->fail('expected ValidationException');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }
        $this->expectException(ValidationException::class);
        UserService::setRoles($this->admin, $t, ['client', 'galactic_overlord']);
    }

    public function testRolesRequiringPhoneCannotBeAssignedToUsersWithoutOne(): void
    {
        $t = $this->makeUser('nophone@x.test', ['support_staff'], 'active', self::PASSWORD, null);
        $this->expectException(ValidationException::class);
        UserService::setRoles($this->admin, $t, ['freelancer']);
    }

    // ---- profile self-service cannot escalate ----------------------------------------------------------

    public function testOwnProfileUpdateIgnoresRoleStatusAndEmailFields(): void
    {
        $mgr = $this->makeUser('mgr@x.test', ['manager']);
        UserService::updateOwnProfile($mgr, [
            'name' => 'Renamed', 'phone' => '+91 90000 22222', 'timezone' => 'Asia/Kolkata',
            // crafted extras that must be ignored by construction:
            'role' => 'super_admin', 'roles' => ['super_admin'], 'status' => 'active', 'email' => 'evil@x.test', 'level' => 100, 'permissions' => ['*'],
        ]);
        $u = Database::one('SELECT * FROM users WHERE id = ?', [$mgr]);
        $this->assertSame('Renamed', $u['name']);
        $this->assertSame('mgr@x.test', $u['email']);
        $this->assertSame(['manager'], $this->roles($mgr));
        $this->assertFalse(Gate::can('users.create', $mgr));
    }

    public function testOwnProfileRejectsBadTimezoneAndMissingRequiredPhone(): void
    {
        $fl = $this->makeUser('fl@x.test', ['freelancer']);
        try {
            UserService::updateOwnProfile($fl, ['name' => 'F', 'phone' => '+91 90000 22222', 'timezone' => 'Mars/Olympus']);
            $this->fail('bad timezone accepted');
        } catch (ValidationException) {
        }
        $this->expectException(ValidationException::class);
        UserService::updateOwnProfile($fl, ['name' => 'F', 'phone' => '', 'timezone' => '']);
    }

    // ---- deactivation / revocation ---------------------------------------------------------------------

    public function testDeactivationRevokesAccessRecordsWhoWhyAndKillsTokens(): void
    {
        $t = $this->makeUser('t@x.test', ['freelancer']);
        $before = (int) Database::one('SELECT session_version v FROM users WHERE id = ?', [$t])['v'];
        UserService::adminSendReset($this->admin, $t);
        $this->assertCount(1, Mailer::$sent);

        UserService::deactivate($this->admin, $t, 'Left the company');
        $u = Database::one('SELECT * FROM users WHERE id = ?', [$t]);
        $this->assertSame('deactivated', $u['status']);
        $this->assertSame($this->admin, (int) $u['deactivated_by']);
        $this->assertSame('Left the company', $u['deactivation_reason']);
        $this->assertSame($before + 1, (int) $u['session_version']);
        $this->assertSame(0, (int) Database::one('SELECT COUNT(*) c FROM auth_tokens WHERE user_id = ? AND used_at IS NULL', [$t])['c']);

        $audit = Database::one("SELECT * FROM audit_logs WHERE action = 'user.deactivated'");
        $this->assertSame($this->admin, (int) $audit['actor_id']);
        $this->assertStringContainsString('Left the company', $audit['meta_json']);
    }

    public function testDeactivationNeedsAReasonAndPermission(): void
    {
        $t = $this->makeUser('t@x.test', ['freelancer']);
        try {
            UserService::deactivate($this->admin, $t, '   ');
            $this->fail('reason required');
        } catch (ValidationException) {
        }
        $mgr = $this->makeUser('mgr@x.test', ['manager']);
        $this->expect403(fn () => UserService::deactivate($mgr, $t, 'because'), 'manager cannot deactivate');
        $this->assertSame('active', Database::one('SELECT status FROM users WHERE id = ?', [$t])['status']);
    }

    public function testCannotDeactivateOrArchiveSelf(): void
    {
        $this->expect403(fn () => UserService::deactivate($this->admin, $this->admin, 'oops'), 'self deactivate');
        $this->expect403(fn () => UserService::archive($this->admin, $this->admin), 'self archive');
    }

    public function testReactivateRestoresAccessAndArchiveIsTerminalForLogin(): void
    {
        $t = $this->makeUser('t@x.test', ['freelancer']);
        UserService::deactivate($this->admin, $t, 'pause');
        UserService::reactivate($this->admin, $t);
        $this->assertSame('active', Database::one('SELECT status FROM users WHERE id = ?', [$t])['status']);
        UserService::archive($this->admin, $t);
        $this->assertSame('archived', Database::one('SELECT status FROM users WHERE id = ?', [$t])['status']);
        $this->expectException(ValidationException::class);
        UserService::reactivate($this->admin, $t); // archived accounts are not reactivated through this path
    }

    // ---- email / password flows ------------------------------------------------------------------------

    public function testRegistrationCreatesPendingClientOnlyAndIsIndistinguishableForExistingEmail(): void
    {
        Config::set('auth.registration_enabled', true);
        $in = ['name' => 'Rita Reg', 'email' => 'rita@x.test', 'phone' => '+91 90000 33333', 'password' => 'a-long-unique-passphrase-9', 'password_confirmation' => 'a-long-unique-passphrase-9',
            'role' => 'super_admin', 'roles' => ['super_admin'], 'status' => 'active'];
        UserService::register($in, '203.0.113.9');
        $u = (new UserRepository())->findByEmail('rita@x.test');
        $this->assertSame('pending', $u['status']);
        $this->assertSame(['client'], $this->roles((int) $u['id']), 'crafted role fields must be ignored');
        $this->assertCount(1, Mailer::$sent);
        $this->assertStringContainsString('/verify-email/', Mailer::$sent[0]['body']);

        Mailer::reset();
        UserService::register($in, '203.0.113.9'); // same email again: no exception, no second account, "already registered" mail
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) c FROM users WHERE email = 'rita@x.test'")['c']);
        $this->assertCount(1, Mailer::$sent);
        $this->assertSame('You already have a Krazy Integrated Solutions account', Mailer::$sent[0]['subject']);
        $this->assertSame('rita@x.test', Mailer::$sent[0]['to']);
        Config::set('auth.registration_enabled', false);
    }

    public function testRegistrationWithApprovalNeedsNoEmailAndWaitsForTheTeam(): void
    {
        Config::set('auth.registration_mode', 'approval');
        Config::set('mail.driver', 'none'); // works without any email setup
        $this->assertSame('approval', Registration::mode());
        $in = ['name' => 'Aman Approval', 'email' => 'aman@x.test', 'phone' => '+91 90000 33333', 'password' => 'a-long-unique-passphrase-9', 'password_confirmation' => 'a-long-unique-passphrase-9', 'roles' => ['super_admin']];
        UserService::register($in, '203.0.113.20');
        $u = (new UserRepository())->findByEmail('aman@x.test');
        $this->assertSame('pending', $u['status']);
        $this->assertSame(1, (int) $u['self_registered']);
        $this->assertSame(['client'], $this->roles((int) $u['id']));
        $this->assertNull($u['email_verified_at']);
        $this->assertSame(0, (int) Database::one('SELECT COUNT(*) c FROM auth_tokens WHERE user_id = ?', [$u['id']])['c'], 'no email-confirmation link is issued');
        $this->assertGreaterThan(0, (int) Database::one("SELECT COUNT(*) c FROM notifications WHERE event_type = 'user.registration_pending'")['c'], 'the team is told');
        UserService::register($in, '203.0.113.20'); // the same email again: nothing changes, nothing is revealed
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) c FROM users WHERE email = 'aman@x.test'")['c']);

        // only a person who may edit users can approve, and only self-registered pending accounts
        $manager = $this->makeUser('mgr@x.test', ['manager']);
        $other = $this->makeUser('other-client@x.test', ['client']);
        foreach ([$manager, $other] as $who) {
            try {
                UserService::approveRegistration($who, (int) $u['id']);
                $this->fail('must be refused');
            } catch (HttpException $e) {
                $this->assertContains($e->status, [403, 404]);
            }
        }
        $this->assertSame('pending', (new UserRepository())->findByEmail('aman@x.test')['status']);
        UserService::approveRegistration($this->admin, (int) $u['id']);
        $this->assertSame('active', (new UserRepository())->findByEmail('aman@x.test')['status']);
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) c FROM audit_logs WHERE action = 'user.registration_approved'")['c']);
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) c FROM notifications WHERE user_id = ? AND event_type = 'user.registration_approved'", [$u['id']])['c']);
        try {
            UserService::approveRegistration($this->admin, (int) $u['id']);
            $this->fail('already active');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('waiting for approval', implode(' ', $e->errors));
        }
        // an invited (team-created) pending account is not approvable this way
        $invited = UserService::adminCreate($this->admin, ['name' => 'Ivy Invited', 'email' => 'ivy@x.test', 'phone' => '+91 90000 44444', 'roles' => ['client']])['user_id'];
        try {
            UserService::approveRegistration($this->admin, (int) $invited);
            $this->fail('an invitation is not a self-registration');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }
        Config::set('auth.registration_mode', '');
        Config::set('mail.driver', 'array');
    }

    public function testTheEmailModeStillNeedsWorkingEmailAndOffMeansOff(): void
    {
        Config::set('auth.registration_mode', 'email');
        Config::set('mail.driver', 'none');
        $this->assertSame('off', Registration::mode(), 'email mode without email is not offered');
        Config::set('auth.registration_mode', 'off');
        Config::set('auth.registration_enabled', true); // the older switch must not override an explicit Off
        Config::set('mail.driver', 'array');
        $this->assertSame('off', Registration::mode());
        $this->expectException(HttpException::class);
        UserService::register(['name' => 'X', 'email' => 'x@x.test', 'phone' => '+91 90000 33333', 'password' => 'a-long-unique-passphrase-9', 'password_confirmation' => 'a-long-unique-passphrase-9'], '1.1.1.1');
    }

    public function testRegistrationCanBeSwitchedOff(): void
    {
        Config::set('auth.registration_mode', 'off');
        $this->expectException(HttpException::class);
        UserService::register(['name' => 'X', 'email' => 'x@x.test', 'phone' => '+91 90000 33333', 'password' => 'a-long-unique-passphrase-9', 'password_confirmation' => 'a-long-unique-passphrase-9'], '1.1.1.1');
    }

    public function testRegistrationIsOnWithTeamApprovalUntilTheOwnerChooses(): void
    {
        Config::set('auth.registration_mode', '');
        Config::set('auth.registration_enabled', false);
        $this->assertSame('approval', Registration::mode());
        $this->assertTrue(Registration::enabled());
    }

    public function testRegistrationRejectsWeakPasswordsAndRateLimitsByIp(): void
    {
        Config::set('auth.registration_enabled', true);
        try {
            UserService::register(['name' => 'X', 'email' => 'x@x.test', 'phone' => '+91 90000 33333', 'password' => 'short', 'password_confirmation' => 'short'], '9.9.9.9');
            $this->fail('weak password accepted');
        } catch (ValidationException) {
        }
        $this->assertNull((new UserRepository())->findByEmail('x@x.test'));

        $limit = Config::get('auth.limits.register_ip')[0];
        $blocked = false;
        for ($i = 0; $i <= $limit + 1; $i++) {
            try {
                UserService::register(['name' => 'N', 'email' => "n{$i}@x.test", 'phone' => '+91 90000 33333', 'password' => 'a-long-unique-passphrase-9', 'password_confirmation' => 'a-long-unique-passphrase-9'], '8.8.8.8');
            } catch (HttpException $e) {
                $blocked = $e->status === 429;
                break;
            }
        }
        $this->assertTrue($blocked, 'registration must be rate limited per IP');
        Config::set('auth.registration_enabled', false);
    }

    public function testEmailVerificationActivatesAndTokenIsSingleUse(): void
    {
        Config::set('auth.registration_enabled', true);
        UserService::register(['name' => 'Vera Verify', 'email' => 'vera@x.test', 'phone' => '+91 90000 33333', 'password' => 'a-long-unique-passphrase-9', 'password_confirmation' => 'a-long-unique-passphrase-9'], '7.7.7.7');
        preg_match('#/verify-email/([a-f0-9]{64})#', Mailer::$sent[0]['body'], $m);
        $this->assertTrue(UserService::verifyEmail($m[1], '7.7.7.7'));
        $u = (new UserRepository())->findByEmail('vera@x.test');
        $this->assertSame('active', $u['status']);
        $this->assertNotNull($u['email_verified_at']);
        $this->assertFalse(UserService::verifyEmail($m[1], '7.7.7.7'), 'replay must fail');
        $this->assertFalse(UserService::verifyEmail(str_repeat('0', 64), '7.7.7.7'));
        $this->assertFalse(UserService::verifyEmail('not-a-token', '7.7.7.7'));
        Config::set('auth.registration_enabled', false);
    }

    public function testPasswordResetFlowEndToEndAtServiceLevel(): void
    {
        $id = $this->makeUser('reset@x.test', ['client']);
        $oldVersion = (int) Database::one('SELECT session_version v FROM users WHERE id = ?', [$id])['v'];

        UserService::requestPasswordReset('RESET@x.test', '5.5.5.5');
        $this->assertCount(1, Mailer::$sent);
        preg_match('#/reset-password/([a-f0-9]{64})#', Mailer::$sent[0]['body'], $m);

        $this->assertTrue(UserService::completePasswordToken($m[1], 'brand-new-pass-phrase-77', 'brand-new-pass-phrase-77', '5.5.5.5'));
        $u = Database::one('SELECT * FROM users WHERE id = ?', [$id]);
        $this->assertTrue(password_verify('brand-new-pass-phrase-77', $u['password_hash']));
        $this->assertSame($oldVersion + 1, (int) $u['session_version'], 'reset must sign out other sessions');
        $this->assertFalse(UserService::completePasswordToken($m[1], 'another-pass-phrase-88!', 'another-pass-phrase-88!', '5.5.5.5'), 'token is single use');
        $this->assertTrue(password_verify('brand-new-pass-phrase-77', Database::one('SELECT password_hash FROM users WHERE id = ?', [$id])['password_hash']));
    }

    public function testPasswordResetDoesNotRevealAccountsAndRespectsStatusAndLimits(): void
    {
        $this->makeUser('gone@x.test', ['client'], 'deactivated');
        UserService::requestPasswordReset('nobody@x.test', '4.4.4.4');
        UserService::requestPasswordReset('gone@x.test', '4.4.4.4');
        $this->assertCount(0, Mailer::$sent, 'no email for unknown or deactivated accounts, and no exception either');

        $this->makeUser('limited@x.test', ['client']);
        for ($i = 0; $i < 6; $i++) {
            UserService::requestPasswordReset('limited@x.test', '3.3.3.' . $i);
        }
        $this->assertLessThanOrEqual((int) Config::get('auth.limits.password_reset_email')[0], count(Mailer::$sent), 'per-email limit caps emails');
    }

    public function testResetTokenRejectedWhenExpiredWeakOrAccountDisabled(): void
    {
        $id = $this->makeUser('exp@x.test', ['client']);
        UserService::requestPasswordReset('exp@x.test', '6.6.6.6');
        preg_match('#/reset-password/([a-f0-9]{64})#', Mailer::$sent[0]['body'], $m);

        try {
            UserService::completePasswordToken($m[1], 'short', 'short', '6.6.6.6');
            $this->fail('weak password accepted');
        } catch (ValidationException) {
        }
        $this->assertSame(1, (int) Database::one('SELECT COUNT(*) c FROM auth_tokens WHERE user_id = ? AND used_at IS NULL', [$id])['c'], 'a weak password must not burn the token');

        Database::run('UPDATE auth_tokens SET expires_at = UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE user_id = ?', [$id]);
        $this->assertFalse(UserService::completePasswordToken($m[1], 'brand-new-pass-phrase-77', 'brand-new-pass-phrase-77', '6.6.6.6'), 'expired');
    }

    public function testChangeOwnPasswordNeedsCurrentPasswordPolicyAndAudits(): void
    {
        $id = $this->makeUser('chg@x.test', ['client']);
        try {
            UserService::changeOwnPassword($id, 'wrong-current', 'brand-new-pass-phrase-77', 'brand-new-pass-phrase-77');
            $this->fail('wrong current password accepted');
        } catch (ValidationException) {
        }
        try {
            UserService::changeOwnPassword($id, self::PASSWORD, 'short', 'short');
            $this->fail('weak password accepted');
        } catch (ValidationException) {
        }
        UserService::changeOwnPassword($id, self::PASSWORD, 'brand-new-pass-phrase-77', 'brand-new-pass-phrase-77');
        $this->assertTrue(password_verify('brand-new-pass-phrase-77', Database::one('SELECT password_hash FROM users WHERE id = ?', [$id])['password_hash']));
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) c FROM audit_logs WHERE action = 'user.password_changed'")['c']);
        $log = (string) Database::one('SELECT GROUP_CONCAT(COALESCE(before_json,\'\'), COALESCE(after_json,\'\'), COALESCE(meta_json,\'\')) g FROM audit_logs')['g'];
        $this->assertStringNotContainsString('brand-new-pass-phrase-77', $log);
        $this->assertStringNotContainsString(self::PASSWORD, $log);
    }

    public function testNoPasswordOrTokenEverReachesTheAuditLog(): void
    {
        Config::set('auth.registration_enabled', true);
        UserService::register(['name' => 'Leak Test', 'email' => 'leak@x.test', 'phone' => '+91 90000 33333', 'password' => 'a-long-unique-passphrase-9', 'password_confirmation' => 'a-long-unique-passphrase-9'], '2.2.2.2');
        UserService::requestPasswordReset('leak@x.test', '2.2.2.2');
        $all = '';
        foreach (Database::all('SELECT before_json, after_json, meta_json FROM audit_logs') as $r) {
            $all .= implode(' ', array_map('strval', $r));
        }
        $this->assertStringNotContainsString('a-long-unique-passphrase-9', $all);
        $this->assertDoesNotMatchRegularExpression('/[a-f0-9]{64}/', $all, 'no raw tokens in audit data');
        $this->assertStringNotContainsString('$2y$', $all, 'no password hashes in audit data');
        Config::set('auth.registration_enabled', false);
    }
}
