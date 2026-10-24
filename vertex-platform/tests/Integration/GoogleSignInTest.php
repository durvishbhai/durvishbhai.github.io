<?php

declare(strict_types=1);

namespace Vertex\Tests\Integration;

use Vertex\Core\Config;
use Vertex\Core\Database;
use Vertex\Core\HttpException;
use Vertex\Core\Request;
use Vertex\Core\ValidationException;
use Vertex\Repositories\UserRepository;
use Vertex\Services\Auth;
use Vertex\Services\GoogleAuth;
use Vertex\Services\Mailer;
use Vertex\Services\PermissionSync;
use Vertex\Services\UserService;

/** "Continue with Google": the server-side OpenID Connect flow, the account rules and the compulsory phone number. Google is faked; nothing leaves the machine. */
final class GoogleSignInTest extends DatabaseTestCase
{
    private const CLIENT_ID = 'test-client-123.apps.googleusercontent.com';

    protected function setUp(): void
    {
        parent::setUp();
        PermissionSync::run(true);
        Config::set('app.url', 'https://portal.example.test');
        Config::set('google.enabled', true);
        Config::set('google.client_id', self::CLIENT_ID);
        Config::set('google.client_secret', 'GOCSPX-test-secret');
        Config::set('auth.registration_mode', 'approval');
        Config::set('mail.driver', 'array');
        Mailer::$sent = [];
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        \Vertex\Services\Http::fake(null);
        Config::set('google.enabled', false);
        Config::set('auth.registration_mode', '');
        Config::set('mail.driver', 'none');
        Config::set('app.url', '');
        $_SESSION = [];
    }

    /** Starts the flow and returns [state, nonce] as Google would receive them. @return array{0:string,1:string} */
    private function start(): array
    {
        $url = GoogleAuth::authorizeUrl();
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

        return [(string) $q['state'], (string) $q['nonce']];
    }

    /** @param array<string,mixed> $claims */
    private function fakeGoogle(array $claims, int $status = 200): void
    {
        $b64 = static fn (string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $jwt = $b64('{"alg":"RS256"}') . '.' . $b64((string) json_encode($claims)) . '.sig';
        \Vertex\Services\Http::fake(static fn (string $m, string $url, array $h, ?string $body): array => ['status' => $status, 'body' => (string) json_encode(['id_token' => $jwt, 'access_token' => 'ya29.x'])]);
    }

    /** @return array<string,mixed> */
    private function claims(string $nonce, array $over = []): array
    {
        return $over + ['iss' => 'https://accounts.google.com', 'aud' => self::CLIENT_ID, 'exp' => time() + 3000, 'nonce' => $nonce, 'sub' => '1098765432100', 'email' => 'Gina.Test@gmail.test', 'email_verified' => true, 'name' => 'Gina Test'];
    }

    /** @return array{sub:string,email:string,name:string} */
    private function identity(array $over = []): array
    {
        [$state, $nonce] = $this->start();
        $this->fakeGoogle($this->claims($nonce, $over));

        return GoogleAuth::identify('auth-code-1', $state);
    }

    private function request(): Request
    {
        return new Request('GET', '/auth/google/callback', [], [], ['REMOTE_ADDR' => '203.0.113.5', 'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/120.0']);
    }

    public function testTheAuthorizeAddressCarriesTheClientIdTheRedirectAddressAndAOneTimeState(): void
    {
        $url = GoogleAuth::authorizeUrl();
        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        $this->assertSame(self::CLIENT_ID, $q['client_id']);
        $this->assertSame('https://portal.example.test/auth/google/callback', $q['redirect_uri']);
        $this->assertSame('code', $q['response_type']);
        $this->assertSame('openid email profile', $q['scope']);
        $this->assertGreaterThanOrEqual(32, strlen((string) $q['state']));
        $this->assertStringNotContainsString('GOCSPX', $url, 'the secret never goes through the browser');
        $this->assertStringNotContainsString($q['state'], json_encode($_SESSION), 'only a hash of the state is kept');
    }

    public function testAVerifiedIdentityIsReadFromGoogleAndTheStateWorksOnce(): void
    {
        [$state, $nonce] = $this->start();
        $this->fakeGoogle($this->claims($nonce));
        $g = GoogleAuth::identify('code-1', $state);
        $this->assertSame(['sub' => '1098765432100', 'email' => 'gina.test@gmail.test', 'name' => 'Gina Test'], $g);
        $this->expectException(\RuntimeException::class);
        GoogleAuth::identify('code-1', $state); // replay
    }

    /** @return array<string,array{0:array<string,mixed>}> */
    public static function badClaims(): array
    {
        return [
            'wrong audience' => [['aud' => 'someone-else.apps.googleusercontent.com']],
            'wrong issuer' => [['iss' => 'https://evil.example']],
            'expired' => [['exp' => time() - 3600]],
            'email not verified' => [['email_verified' => false]],
            'wrong nonce' => [['nonce' => 'not-ours']],
            'no subject' => [['sub' => '']],
            'bad email' => [['email' => 'not-an-email']],
        ];
    }

    /** @dataProvider badClaims
     * @param array<string,mixed> $over */
    #[\PHPUnit\Framework\Attributes\DataProvider('badClaims')]
    public function testAnIdentityThatGoogleDidNotProperlyConfirmIsRefused(array $over): void
    {
        [$state, $nonce] = $this->start();
        $this->fakeGoogle($this->claims($nonce, $over));
        $this->expectException(\RuntimeException::class);
        GoogleAuth::identify('code-1', $state);
    }

    public function testAWrongOrMissingStateAndAGoogleErrorAreRefused(): void
    {
        [$state, $nonce] = $this->start();
        $this->fakeGoogle($this->claims($nonce));
        try {
            GoogleAuth::identify('code-1', 'forged-state');
            $this->fail('forged state accepted');
        } catch (\RuntimeException) {
        }
        [, $nonce] = $this->start();
        $this->fakeGoogle($this->claims($nonce), 400);
        $state = (string) (function () { $url = GoogleAuth::authorizeUrl(); parse_str((string) parse_url($url, PHP_URL_QUERY), $q); return $q['state']; })();
        $this->expectException(\RuntimeException::class);
        GoogleAuth::identify('code-1', $state);
    }

    public function testItIsOnlyAvailableOverHttpsWhenConfiguredAndSwitchedOn(): void
    {
        $this->assertTrue(GoogleAuth::enabled());
        Config::set('google.client_secret', '');
        $this->assertFalse(GoogleAuth::enabled());
        Config::set('google.client_secret', 'x');
        Config::set('app.url', 'http://portal.example.test');
        $this->assertFalse(GoogleAuth::enabled());
        Config::set('app.url', 'https://portal.example.test');
        Config::set('google.enabled', false);
        $this->assertFalse(GoogleAuth::enabled());
    }

    public function testAFirstTimeVisitorMustGiveAPhoneNumberAndWaitsForApproval(): void
    {
        $g = $this->identity();
        $this->assertSame('complete', UserService::googleSignIn($g, '203.0.113.5')['action']);
        foreach (['', '   ', 'abc'] as $bad) {
            try {
                UserService::completeGoogleSignUp($g, $bad, 'Gina Test', '203.0.113.5');
                $this->fail('a phone number is compulsory');
            } catch (ValidationException) {
            }
        }
        $this->assertNull((new UserRepository())->findByEmail('gina.test@gmail.test'));
        $made = UserService::completeGoogleSignUp($g, '+91 90000 11111', 'Gina Test', '203.0.113.5');
        $this->assertSame('pending', $made['status']);
        $u = Database::one('SELECT * FROM users WHERE id = ?', [$made['user_id']]);
        $this->assertSame('1098765432100', $u['google_sub']);
        $this->assertSame('+91 90000 11111', $u['phone']);
        $this->assertNotNull($u['email_verified_at'], 'Google confirmed the address');
        $this->assertSame(1, (int) $u['self_registered']);
        $this->assertSame(['client'], array_column((new UserRepository())->rolesOf($made['user_id']), 'slug'));
        $this->assertSame('pending', UserService::googleSignIn($g, '203.0.113.5')['action'], 'waits for the team');
        $this->assertFalse(password_verify('', (string) $u['password_hash']));
        $this->assertSame('client', Database::one('SELECT client_type FROM clients WHERE user_id = ?', [$made['user_id']])['client_type'] === 'individual' ? 'client' : 'none', 'a client record was created');
    }

    public function testWithTheSignUpSwitchOnEmailTheNewClientCanSignInAtOnceAndWithOffNobodyCanJoin(): void
    {
        Config::set('auth.registration_mode', 'email');
        $g = $this->identity(['sub' => '555', 'email' => 'quick@gmail.test']);
        $made = UserService::completeGoogleSignUp($g, '+91 90000 22222', '', '203.0.113.5');
        $this->assertSame('active', $made['status']);
        $user = (new UserRepository())->findById($made['user_id']);
        $this->assertSame(Auth::OK, Auth::loginVerified($user, $this->request(), 'Google'));
        $this->assertSame($made['user_id'], $_SESSION['auth_user_id']);
        $this->assertSame('Gina Test', $user['name'], 'the Google name is used when none is typed');

        Config::set('auth.registration_mode', 'off');
        try {
            UserService::googleSignIn($this->identity(['sub' => '777', 'email' => 'late@gmail.test']), '203.0.113.5');
            $this->fail('sign-up is off');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('closed', $e->getMessage());
        }
        $this->expectException(HttpException::class);
        UserService::completeGoogleSignUp($this->identity(['sub' => '778', 'email' => 'late2@gmail.test']), '+91 90000 33333', '', '203.0.113.5');
    }

    public function testAnExistingClientIsLinkedAndSignedInButTeamAccountsAreNeverOpenedThisWay(): void
    {
        $client = $this->makeUser('gina.test@gmail.test', ['client']);
        $r = UserService::googleSignIn($this->identity(), '203.0.113.5');
        $this->assertSame('login', $r['action']);
        $this->assertSame($client, (int) $r['user']['id']);
        $this->assertSame('1098765432100', Database::one('SELECT google_sub FROM users WHERE id = ?', [$client])['google_sub']);
        // the same Google account finds the same user next time, even if the email address changed
        Database::run("UPDATE users SET email = 'gina.new@x.test' WHERE id = ?", [$client]);
        $this->assertSame($client, (int) UserService::googleSignIn($this->identity(), '203.0.113.5')['user']['id']);

        $this->makeUser('staff@gmail.test', ['administrator']);
        $this->expectException(\RuntimeException::class);
        UserService::googleSignIn($this->identity(['sub' => '999', 'email' => 'staff@gmail.test']), '203.0.113.5');
    }

    public function testADifferentGoogleAccountCannotTakeOverALinkedEmailAndInactiveAccountsStayOut(): void
    {
        $client = $this->makeUser('gina.test@gmail.test', ['client']);
        Database::run("UPDATE users SET google_sub = 'original-sub' WHERE id = ?", [$client]);
        try {
            UserService::googleSignIn($this->identity(), '203.0.113.5');
            $this->fail('a different Google account must not use this email');
        } catch (\RuntimeException) {
        }
        Database::run("UPDATE users SET google_sub = NULL, status = 'deactivated' WHERE id = ?", [$client]);
        $this->expectException(\RuntimeException::class);
        UserService::googleSignIn($this->identity(), '203.0.113.5');
    }

    public function testAnAccountWithTwoFactorStillOwesItsCodeAndTheSignInAlertIsSent(): void
    {
        $id = $this->makeUser('gina.test@gmail.test', ['client']);
        Database::run("UPDATE users SET totp_enabled_at = UTC_TIMESTAMP(), totp_secret_enc = 'x' WHERE id = ?", [$id]);
        $user = (new UserRepository())->findById($id);
        $this->assertSame(Auth::TWO_FACTOR, Auth::loginVerified($user, $this->request(), 'Google'));
        $this->assertArrayNotHasKey('auth_user_id', $_SESSION);
        $this->assertSame($id, $_SESSION['twofa_pending']['uid']);

        Database::run('UPDATE users SET totp_enabled_at = NULL, totp_secret_enc = NULL WHERE id = ?', [$id]);
        Mailer::$sent = [];
        $this->assertSame(Auth::OK, Auth::loginVerified((new UserRepository())->findById($id), $this->request(), 'Google'));
        $this->assertStringContainsString('New sign-in', Mailer::$sent[0]['subject']);
        $this->assertStringContainsString('Google', Mailer::$sent[0]['body']);
    }
}
