<?php

declare(strict_types=1);

namespace Vertex\Tests\Integration;

use Vertex\Core\Database;
use Vertex\Repositories\UserRepository;

/** Registration, email verification, password reset and admin invitations, end to end (email read from the dev mail log). */
final class AccountFlowsHttpTest extends HttpTestCase
{
    private const NEWPW = 'a-very-long-new-passphrase-42';

    private function roles(string $email): array
    {
        $u = (new UserRepository())->findByEmail($email);

        return $u ? array_column((new UserRepository())->rolesOf((int) $u['id']), 'slug') : [];
    }

    private function register(\Vertex\Tests\Support\HttpClient $c, array $over = []): array
    {
        return $c->post('/register', $over + [
            'name' => 'Rita Registrant', 'email' => 'rita@x.test', 'phone' => '+91 90000 55555',
            'password' => self::NEWPW, 'password_confirmation' => self::NEWPW,
        ]);
    }

    public function testTheOwnerCanSwitchRegistrationToTeamApprovalAndApproveANewClient(): void
    {
        $this->makeUser('owner@x.test', ['super_admin']);
        $owner = $this->loggedIn('owner@x.test');
        $page = $owner->get('/admin/settings')['body'];
        $this->assertStringContainsString('name="auth__registration_mode"', $page, 'the option is on the Feature switches screen');
        $owner->post('/admin/settings/features', ['features__cms' => '1', 'features__public_forms' => '1', 'features__ai' => '1', 'auth__registration_mode' => 'approval', 'confirm_password' => self::PASSWORD], '/admin/settings');

        $c = $this->client();
        $this->assertStringContainsString('New here? Sign up', $c->get('/login')['body']);
        $this->assertStringContainsString('approves each new account', $c->get('/register')['body']);
        $r = $this->register($c, ['email' => 'approval@x.test', 'name' => 'Aisha Approval']);
        $this->assertSame('/login', $r['location']);
        $this->assertStringContainsString('waiting for our team to approve', $c->get('/login')['body']);
        $row = Database::one("SELECT * FROM users WHERE email = 'approval@x.test'");
        $this->assertSame('pending', $row['status']);

        $c->login('approval@x.test', self::NEWPW);
        $this->assertStringContainsString('has to approve it first', $c->get('/login')['body']);

        $show = $owner->get('/admin/users/' . $row['public_id']);
        $this->assertStringContainsString('Approve this registration', $show['body']);
        $this->assertStringContainsString('Aisha Approval', $owner->get('/admin/users')['body']);
        $res = $owner->post('/admin/users/' . $row['public_id'] . '/approve', [], '/admin/users/' . $row['public_id']);
        $this->assertSame(303, $res['status']);
        $this->assertSame('active', Database::one("SELECT status FROM users WHERE email = 'approval@x.test'")['status']);
        $this->assertSame('/dashboard', $this->client()->login('approval@x.test', self::NEWPW)['location'], 'the client can sign in once approved');
        $cl = $this->client();
        $cl->login('approval@x.test', self::NEWPW);
        $this->assertSame(403, $cl->post('/admin/users/' . $row['public_id'] . '/approve', [], '/account')['status'], 'a client cannot approve accounts');

        // switching it Off removes the link and the page
        $owner->post('/admin/settings/features', ['features__cms' => '1', 'features__public_forms' => '1', 'features__ai' => '1', 'auth__registration_mode' => 'off', 'confirm_password' => self::PASSWORD], '/admin/settings');
        $this->assertStringNotContainsString('New here? Sign up', $this->client()->get('/login')['body']);
        $this->assertStringNotContainsString('href="/register"', $this->client()->get('/')['body'], 'with sign-up off the tab disappears too');
        $this->assertSame(404, $this->client()->get('/register')['status']);
    }

    public function testTheSignUpTabIsOnTheWebsiteAndTheLoginPageAndThePhoneNumberIsCompulsory(): void
    {
        $c = $this->client();
        $home = $c->get('/')['body'];
        $this->assertStringContainsString('href="/register">Sign up</a>', $home, 'a visible Sign up tab beside Login');
        $this->assertStringContainsString('href="/login">Login</a>', $home);
        $this->assertStringContainsString('New here? Sign up', $c->get('/login')['body']);
        $form = $c->get('/register')['body'];
        $this->assertMatchesRegularExpression('/<input id="phone"[^>]*\brequired\b/', $form);
        $this->assertStringContainsString('Phone number', $form);
        $r = $this->register($c, ['phone' => '', 'email' => 'nophone@x.test']);
        $this->assertStringContainsString('A phone number is required', $c->get('/register')['body'] . $r['body']);
        $this->assertSame([], $this->roles('nophone@x.test'), 'no account without a phone number');
        $this->assertSame(404, $c->get('/auth/google')['status'], 'Google sign-in is off until it is configured');
        $this->assertStringNotContainsString('Continue with Google', $c->get('/login')['body']);
    }

    public function testRegistrationVerificationThenLogin(): void
    {
        $c = $this->client();
        $this->assertSame(200, $c->get('/register')['status']);
        $r = $this->register($c);
        $this->assertSame(303, $r['status']);
        $this->assertSame('/login', $r['location']);
        $this->assertStringContainsString('Check your email', $c->get('/login')['body']);

        // cannot sign in before verifying
        $c->login('rita@x.test', self::NEWPW);
        $this->assertStringContainsString('not active yet', $c->get('/login')['body']);

        $link = $this->mailLink('/verify-email/', 'rita@x.test');
        $this->assertNotNull($link, 'verification email must contain a link');

        // GET shows a confirm button and does NOT consume the token (mail scanners prefetch links)
        $page = $c->get($link);
        $this->assertSame(200, $page['status']);
        $this->assertStringContainsString('Confirm email', $page['body']);
        $this->assertSame('pending', Database::one("SELECT status FROM users WHERE email = 'rita@x.test'")['status']);

        $r = $c->post($link, [], $link);
        $this->assertSame(303, $r['status']);
        $this->assertSame('active', Database::one("SELECT status FROM users WHERE email = 'rita@x.test'")['status']);
        $this->assertSame(['client'], $this->roles('rita@x.test'));

        $login = $this->client()->login('rita@x.test', self::NEWPW);
        $this->assertSame('/dashboard', $login['location']);

        // replay of the same link fails
        $again = $this->client();
        $again->post($link, [], '/login'); // the used link no longer renders a form, so take a token from /login
        $this->assertStringContainsString('invalid or has expired', $again->get('/login')['body']);
    }

    public function testCraftedRegistrationFieldsCannotCreatePrivilegedAccounts(): void
    {
        $c = $this->client();
        $r = $this->register($c, ['role' => 'super_admin', 'roles' => ['super_admin'], 'roles[]' => 'administrator', 'status' => 'active', 'level' => '100', 'email_verified_at' => '2020-01-01']);
        $this->assertSame(303, $r['status']);
        $u = Database::one("SELECT * FROM users WHERE email = 'rita@x.test'");
        $this->assertSame('pending', $u['status']);
        $this->assertNull($u['email_verified_at']);
        $this->assertSame(['client'], $this->roles('rita@x.test'));
    }

    public function testRegisteringAnExistingEmailLooksIdenticalAndCreatesNothing(): void
    {
        $this->makeUser('rita@x.test', ['administrator']);
        $c = $this->client();
        $r = $this->register($c);
        $this->assertSame(303, $r['status']);
        $this->assertSame('/login', $r['location']);
        $this->assertStringContainsString('Check your email', $c->get('/login')['body']);
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) c FROM users WHERE email = 'rita@x.test'")['c']);
        $this->assertSame(['administrator'], $this->roles('rita@x.test'), 'existing account untouched');
        $this->assertNull($this->mailLink('/verify-email/'), 'no verification link is sent for an existing account');
    }

    public function testWeakOrMismatchedPasswordsAreRejectedWithoutCreatingAnAccount(): void
    {
        $c = $this->client();
        $this->register($c, ['password' => 'short', 'password_confirmation' => 'short']);
        $this->assertStringContainsString('at least 12 characters', $c->get('/register')['body']);
        $this->register($c, ['password_confirmation' => 'does-not-match-at-all-1']);
        $this->assertStringContainsString('confirmation does not match', $c->get('/register')['body']);
        $this->register($c, ['phone' => '']);
        $this->assertStringContainsString('phone number is required', $c->get('/register')['body']);
        $this->assertNull((new UserRepository())->findByEmail('rita@x.test'));
    }

    public function testPasswordResetFlow(): void
    {
        $this->makeUser('forgot@x.test', ['client']);
        $c = $this->client();
        $this->assertSame(200, $c->get('/forgot-password')['status']);

        $unknown = $c->post('/forgot-password', ['email' => 'nobody@x.test'], '/forgot-password');
        $known = $this->client()->post('/forgot-password', ['email' => 'forgot@x.test'], '/forgot-password');
        $this->assertSame($unknown['status'], $known['status'], 'identical responses');
        $this->assertSame($unknown['location'], $known['location']);
        $this->assertStringContainsString('If that email belongs to an active account', $c->get('/forgot-password')['body']);
        $this->assertSame(1, $this->mailCount(), 'only the real account got an email');

        $link = $this->mailLink('/reset-password/', 'forgot@x.test');
        $this->assertNotNull($link);
        $this->assertStringContainsString('Choose a new password', $c->get($link)['body']);

        $weak = $c->post($link, ['password' => 'short', 'password_confirmation' => 'short'], $link);
        $this->assertSame(303, $weak['status']);
        $this->assertStringContainsString('at least 12 characters', $c->get($link)['body']);

        $ok = $c->post($link, ['password' => self::NEWPW, 'password_confirmation' => self::NEWPW], $link);
        $this->assertSame('/login', $ok['location']);
        $this->assertSame('/dashboard', $this->client()->login('forgot@x.test', self::NEWPW)['location']);
        $old = $this->client();
        $old->login('forgot@x.test', self::PASSWORD);
        $this->assertSame(303, $old->get('/dashboard')['status'], 'old password no longer works');

        // single use
        $replay = $this->client();
        $replay->post($link, ['password' => 'another-long-passphrase-77', 'password_confirmation' => 'another-long-passphrase-77'], '/login');
        $this->assertSame('/dashboard', $this->client()->login('forgot@x.test', self::NEWPW)['location'], 'replay changed nothing');
    }

    public function testResetLinkDiesWhenAccountIsDeactivatedOrTokenExpired(): void
    {
        $id = $this->makeUser('exp@x.test', ['client']);
        $this->client()->post('/forgot-password', ['email' => 'exp@x.test'], '/forgot-password');
        $link = $this->mailLink('/reset-password/', 'exp@x.test');

        Database::run('UPDATE auth_tokens SET expires_at = UTC_TIMESTAMP() - INTERVAL 1 SECOND WHERE user_id = ?', [$id]);
        $c = $this->client();
        $this->assertStringContainsString('invalid, already used, or has expired', $c->get($link)['body']);
        $c->post($link, ['password' => self::NEWPW, 'password_confirmation' => self::NEWPW], '/login');
        $this->assertSame(303, $this->client()->login('exp@x.test', self::NEWPW)['status']);
        $d = $this->client();
        $d->login('exp@x.test', self::NEWPW);
        $this->assertSame(303, $d->get('/dashboard')['status'], 'expired token did not change the password');

        $this->setUp();
        $id = $this->makeUser('exp2@x.test', ['client']);
        $this->client()->post('/forgot-password', ['email' => 'exp2@x.test'], '/forgot-password');
        $link = $this->mailLink('/reset-password/', 'exp2@x.test');
        Database::run("UPDATE users SET status = 'deactivated' WHERE id = ?", [$id]);
        $c = $this->client();
        $c->post($link, ['password' => self::NEWPW, 'password_confirmation' => self::NEWPW], '/login');
        $this->assertTrue(password_verify(self::PASSWORD, Database::one('SELECT password_hash FROM users WHERE id = ?', [$id])['password_hash']), 'deactivated accounts cannot be reset into');
    }

    public function testMalformedTokensAreHarmless(): void
    {
        $c = $this->client();
        foreach (['/reset-password/abc', '/reset-password/' . str_repeat('z', 64), '/invite/' . str_repeat('0', 64), '/verify-email/%27%20OR%201=1', '/verify-email/' . str_repeat('A', 64)] as $path) {
            $r = $c->get($path);
            $this->assertSame(200, $r['status'], $path);
            $this->assertStringContainsString('invalid', strtolower($r['body']), $path);
        }
    }

    public function testAdminInvitationFlow(): void
    {
        $this->makeUser('admin@x.test', ['administrator']);
        $admin = $this->loggedIn('admin@x.test');
        $r = $admin->post('/admin/users', ['name' => 'Ivy Invited', 'email' => 'ivy@x.test', 'phone' => '+91 90000 66666', 'roles' => ['video_editor']], '/account');
        $this->assertSame(303, $r['status']);
        $this->assertStringStartsWith('/admin/users/', (string) $r['location']);
        $this->assertSame('pending', Database::one("SELECT status FROM users WHERE email = 'ivy@x.test'")['status']);
        $this->assertSame(['video_editor'], $this->roles('ivy@x.test'));

        $link = $this->mailLink('/invite/', 'ivy@x.test');
        $this->assertNotNull($link, 'invitation email sent');
        $guest = $this->client();
        $guest->login('ivy@x.test', 'anything-at-all-123');
        $this->assertSame(303, $guest->get('/dashboard')['status'], 'no usable password before accepting');

        $this->assertStringContainsString('Set your password', $guest->get($link)['body']);
        $guest->post($link, ['password' => self::NEWPW, 'password_confirmation' => self::NEWPW], $link);
        $row = Database::one("SELECT status, email_verified_at FROM users WHERE email = 'ivy@x.test'");
        $this->assertSame('active', $row['status']);
        $this->assertNotNull($row['email_verified_at']);
        $this->assertSame('/dashboard', $this->client()->login('ivy@x.test', self::NEWPW)['location']);
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) c FROM audit_logs WHERE action = 'user.invite_accepted'")['c']);
    }

    public function testAdminCreateRejectsDuplicatesMissingPhoneAndEmptyRolesWithFormErrors(): void
    {
        $this->makeUser('admin@x.test', ['administrator']);
        $this->makeUser('taken@x.test', ['client']);
        $admin = $this->loggedIn('admin@x.test');
        $admin->post('/admin/users', ['name' => 'D', 'email' => 'TAKEN@x.test', 'phone' => '+91 90000 66666', 'roles' => ['client']], '/account');
        $this->assertStringContainsString('already exists', $admin->get('/admin/users/create')['body']);
        $admin->post('/admin/users', ['name' => 'F', 'email' => 'f@x.test', 'phone' => '', 'roles' => ['freelancer']], '/account');
        $this->assertStringContainsString('phone number is required', $admin->get('/admin/users/create')['body']);
        $admin->post('/admin/users', ['name' => 'N', 'email' => 'n@x.test', 'phone' => '+91 90000 66666'], '/account');
        $this->assertStringContainsString('at least one role', $admin->get('/admin/users/create')['body']);
        $this->assertNull((new UserRepository())->findByEmail('f@x.test'));
        $this->assertNull((new UserRepository())->findByEmail('n@x.test'));
    }

    public function testRolePickerOffersOnlyRolesTheActorMayAssign(): void
    {
        $this->makeUser('admin@x.test', ['administrator']);
        $this->makeUser('owner@x.test', ['super_admin']);
        $adminForm = $this->loggedIn('admin@x.test')->get('/admin/users/create')['body'];
        $this->assertStringContainsString('value="manager"', $adminForm);
        $this->assertStringNotContainsString('value="administrator"', $adminForm);
        $this->assertStringNotContainsString('value="super_admin"', $adminForm);
        $ownerForm = $this->loggedIn('owner@x.test')->get('/admin/users/create')['body'];
        $this->assertStringContainsString('value="administrator"', $ownerForm);
        $this->assertStringNotContainsString('value="super_admin"', $ownerForm, 'owner role is console-only');
    }

    public function testProfileUpdateAndTimezoneDisplay(): void
    {
        $id = $this->makeUser('tz@x.test', ['client']);
        $c = $this->loggedIn('tz@x.test');
        $c->post('/account', ['name' => 'Tz User', 'phone' => '+91 90000 77777', 'timezone' => 'Pacific/Auckland'], '/account');
        $this->assertSame('Pacific/Auckland', Database::one('SELECT timezone FROM users WHERE id = ?', [$id])['timezone']);
        $c->post('/account', ['name' => 'Tz User', 'phone' => '+91 90000 77777', 'timezone' => 'Not/AZone'], '/account');
        $this->assertSame('Pacific/Auckland', Database::one('SELECT timezone FROM users WHERE id = ?', [$id])['timezone'], 'invalid zone rejected');
        $this->assertStringContainsString('valid time zone', $c->get('/account')['body']);
        $c->post('/account', ['name' => 'Tz User', 'phone' => '', 'timezone' => ''], '/account');
        $this->assertStringContainsString('phone number is required', $c->get('/account')['body']);
    }
}
