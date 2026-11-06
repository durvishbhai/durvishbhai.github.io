<?php

declare(strict_types=1);

namespace Vertex\Tests\Integration;

use Vertex\Core\Database;
use Vertex\Services\Totp;

/** Optional TOTP two-factor login through real HTTP: setup, sign-in, replay protection, recovery codes, lockout, admin reset, required roles. */
final class TwoFactorHttpTest extends HttpTestCase
{
    protected static function extraEnv(): array
    {
        return ['APP_KEY' => 'base64:' . base64_encode(str_repeat('k', 32))];
    }

    /** @return array{0:\Vertex\Tests\Support\HttpClient,1:string,2:list<string>} signed-in client, secret, recovery codes */
    private function enrolled(string $email, array $roles = ['client']): array
    {
        $this->makeUser($email, $roles);
        $c = $this->loggedIn($email);
        $c->post('/account/two-factor/start', [], '/account');
        $page = $c->get('/account/two-factor')['body'];
        $this->assertMatchesRegularExpression('/class="key">([A-Z2-7 ]+)</', $page);
        preg_match('/class="key">([A-Z2-7 ]+)</', $page, $m);
        $secret = str_replace(' ', '', $m[1]);
        $r = $c->post('/account/two-factor/enable', ['password' => self::PASSWORD, 'code' => Totp::code($secret, Totp::step())], '/account');
        $this->assertSame(303, $r['status']);
        $done = $c->get('/account/two-factor')['body'];
        preg_match_all('#<li><code>([a-z0-9]{10})</code></li>#', $done, $codes);
        $this->assertCount(10, $codes[1], 'ten recovery codes are shown once');
        $this->assertStringNotContainsString($codes[1][0], $c->get('/account/two-factor')['body'], 'and never again');

        return [$c, $secret, $codes[1]];
    }

    public function testSetupNeedsPasswordAndAValidCodeAndStoresTheSecretSealed(): void
    {
        $this->makeUser('tf1@x.test', ['client']);
        $c = $this->loggedIn('tf1@x.test');
        $c->post('/account/two-factor/start', [], '/account');
        $r = $c->post('/account/two-factor/enable', ['password' => self::PASSWORD, 'code' => '000000'], '/account');
        $this->assertSame(303, $r['status']);
        $this->assertNull(Database::one('SELECT totp_enabled_at FROM users WHERE email = ?', ['tf1@x.test'])['totp_enabled_at'], 'a wrong code does not turn it on');
        [$c2, $secret] = $this->enrolled('tf2@x.test');
        $row = Database::one('SELECT totp_secret_enc, totp_enabled_at FROM users WHERE email = ?', ['tf2@x.test']);
        $this->assertNotNull($row['totp_enabled_at']);
        $this->assertStringNotContainsString($secret, (string) $row['totp_secret_enc'], 'the secret is sealed, not stored in clear');
        $this->assertSame(10, (int) Database::one('SELECT COUNT(*) c FROM user_recovery_codes')['c']);
        $this->assertSame(0, (int) Database::one("SELECT COUNT(*) c FROM audit_logs WHERE after_json LIKE ?", ['%' . $secret . '%'])['c'], 'the secret never reaches the audit log');
    }

    public function testPasswordAloneDoesNotSignInOnceTwoFactorIsOn(): void
    {
        [, $secret] = $this->enrolled('tf3@x.test');
        $c = $this->client();
        $r = $c->login('tf3@x.test', self::PASSWORD);
        $this->assertSame('/login/two-factor', $r['location']);
        $this->assertSame('/login', $c->get('/dashboard')['location'], 'still signed out after the password step');
        $c->formPage = '/login';
        $bad = $c->post('/login/two-factor', ['code' => '123456']);
        $this->assertSame('/login/two-factor', $bad['location']);
        $this->assertSame('/login', $c->get('/dashboard')['location']);
        $ok = $c->post('/login/two-factor', ['code' => Totp::code($secret, Totp::step() + 1)]); // the enrolment step is already used
        $this->assertSame('/dashboard', $ok['location']);
        $this->assertSame(200, $c->get('/dashboard')['status']);
    }

    public function testACodeCannotBeUsedTwiceAndRecoveryCodesWorkOnce(): void
    {
        [, $secret, $codes] = $this->enrolled('tf4@x.test');
        // enrolment consumed the current time step; the same code must now be refused (replay protection)
        $c = $this->client();
        $c->login('tf4@x.test', self::PASSWORD);
        $r = $c->post('/login/two-factor', ['code' => Totp::code($secret, Totp::step())], '/login');
        $this->assertSame('/login/two-factor', $r['location'], 'replay of the setup code is refused');
        $r = $c->post('/login/two-factor', ['code' => Totp::code($secret, Totp::step() + 1)], '/login');
        $this->assertSame('/dashboard', $r['location'], 'the next time step is accepted');

        $c2 = $this->client();
        $c2->login('tf4@x.test', self::PASSWORD);
        $this->assertSame('/dashboard', $c2->post('/login/two-factor', ['code' => $codes[0]], '/login')['location'], 'a recovery code signs in');
        $c3 = $this->client();
        $c3->login('tf4@x.test', self::PASSWORD);
        $this->assertSame('/login/two-factor', $c3->post('/login/two-factor', ['code' => $codes[0]], '/login')['location'], 'but only once');
        $this->assertSame(1, (int) Database::one('SELECT COUNT(*) c FROM user_recovery_codes WHERE used_at IS NOT NULL')['c']);
    }

    public function testWrongCodesAreRateLimited(): void
    {
        [, $secret] = $this->enrolled('tf5@x.test');
        $c = $this->client();
        $c->login('tf5@x.test', self::PASSWORD);
        for ($i = 0; $i < 5; $i++) {
            $c->post('/login/two-factor', ['code' => '11111' . $i], '/login');
        }
        $r = $c->post('/login/two-factor', ['code' => Totp::code($secret, Totp::step() + 1)], '/login');
        $this->assertNotSame('/dashboard', $r['location'], 'after five wrong codes even the right one is refused for a while');
        $this->assertGreaterThan(0, (int) Database::one("SELECT COUNT(*) c FROM audit_logs WHERE action = 'auth.2fa.locked'")['c']);
    }

    public function testDisablingNeedsPasswordAndCode(): void
    {
        [$c, $secret] = $this->enrolled('tf6@x.test');
        $c->post('/account/two-factor/disable', ['password' => 'wrong-password-123', 'code' => Totp::code($secret, Totp::step() + 1)], '/account');
        $this->assertNotNull(Database::one('SELECT totp_enabled_at FROM users WHERE email = ?', ['tf6@x.test'])['totp_enabled_at']);
        $c->post('/account/two-factor/disable', ['password' => self::PASSWORD, 'code' => Totp::code($secret, Totp::step() + 1)], '/account');
        $this->assertNull(Database::one('SELECT totp_enabled_at FROM users WHERE email = ?', ['tf6@x.test'])['totp_enabled_at']);
        $this->assertSame(0, (int) Database::one('SELECT COUNT(*) c FROM user_recovery_codes')['c']);
    }

    public function testAdministratorCanResetALowerRankedUserButNotThemselvesOrPeers(): void
    {
        $this->enrolled('tf7@x.test');
        $this->makeUser('adm7@x.test', ['administrator']);
        $this->makeUser('adm7b@x.test', ['administrator']);
        $victim = Database::one('SELECT public_id FROM users WHERE email = ?', ['tf7@x.test'])['public_id'];
        $a = $this->loggedIn('adm7@x.test');
        $a->post('/admin/users/' . $victim . '/reset-2fa', ['confirm_password' => 'wrong'], '/account');
        $this->assertNotNull(Database::one('SELECT totp_enabled_at FROM users WHERE email = ?', ['tf7@x.test'])['totp_enabled_at'], 'step-up password required');
        $a->post('/admin/users/' . $victim . '/reset-2fa', ['confirm_password' => self::PASSWORD], '/account');
        $this->assertNull(Database::one('SELECT totp_enabled_at FROM users WHERE email = ?', ['tf7@x.test'])['totp_enabled_at']);
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) c FROM audit_logs WHERE action = 'auth.2fa.reset_by_admin'")['c']);
        $peer = Database::one('SELECT public_id FROM users WHERE email = ?', ['adm7b@x.test'])['public_id'];
        $this->assertSame(403, $a->post('/admin/users/' . $peer . '/reset-2fa', ['confirm_password' => self::PASSWORD], '/account')['status']);
        $c = $this->loggedIn('tf7@x.test');
        $this->assertSame(403, $c->post('/admin/users/' . $victim . '/reset-2fa', ['confirm_password' => self::PASSWORD], '/account')['status'], 'clients cannot reset anything');
    }

    public function testTotpMatchesTheRfc6238Vector(): void
    {
        $secret = Totp::base32Encode('12345678901234567890');
        $this->assertSame('287082', Totp::code($secret, intdiv(59, 30)));
        $this->assertSame('081804', Totp::code($secret, intdiv(1111111109, 30)));
        $this->assertSame('12345678901234567890', Totp::base32Decode($secret));
    }
}
