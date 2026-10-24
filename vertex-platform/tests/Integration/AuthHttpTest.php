<?php

declare(strict_types=1);

namespace Vertex\Tests\Integration;

use Vertex\Core\Database;

/** Authentication, sessions, cookies and CSRF, exercised through real HTTP requests. */
final class AuthHttpTest extends HttpTestCase
{
    public function testSecurityHeadersAndNoVersionLeak(): void
    {
        $c = $this->client();
        $r = $c->request('GET', '/login');
        $this->assertSame(200, $r['status']);
        $raw = $this->headersOf('/login');
        $this->assertStringContainsString("script-src 'self'", $raw);
        $this->assertStringNotContainsString("'unsafe-inline'", $raw);
        $this->assertStringContainsString('X-Frame-Options: DENY', $raw);
        $this->assertStringContainsString('X-Content-Type-Options: nosniff', $raw);
        $this->assertStringContainsString('Cache-Control: no-store', $raw);
        $this->assertStringNotContainsStringIgnoringCase('X-Powered-By', $raw);
        $this->assertDoesNotMatchRegularExpression('/<script(?![^>]*\bsrc=)/i', $r['body'], 'no inline scripts');
        $this->assertDoesNotMatchRegularExpression('/<style|style="/i', $r['body'], 'no inline styles');
    }

    public function testPublicPagesDoNotCreateSessionsForAnonymousVisitorsButFormsDo(): void
    {
        $c = $this->client();
        $c->get('/');
        $this->assertSame([], $c->setCookies, 'home page must not set a session cookie');
        $c->get('/health');
        $this->assertSame([], $c->setCookies);
        $c->get('/login');
        $this->assertCount(1, $c->setCookies, 'login form needs a session for its CSRF token');
    }

    public function testSessionCookieFlags(): void
    {
        $c = $this->client();
        $c->get('/login');
        $cookie = $c->setCookies[0];
        $this->assertStringContainsString('HttpOnly', $cookie);
        $this->assertStringContainsString('SameSite=Lax', $cookie);
        $this->assertStringContainsString('path=/', $cookie);
        $this->assertStringNotContainsString('expires', strtolower($cookie), 'session cookie, not persistent');
    }

    public function testPostWithoutOrWithWrongCsrfTokenIsRejectedBeforeAnythingHappens(): void
    {
        $this->makeUser('csrf@x.test', ['client']);
        $c = $this->client();
        $c->get('/login');
        $this->assertSame(419, $c->postNoToken('/login', ['email' => 'csrf@x.test', 'password' => self::PASSWORD])['status']);
        $this->assertSame(419, $c->postNoToken('/login', ['email' => 'csrf@x.test', 'password' => self::PASSWORD, '_token' => str_repeat('a', 64)])['status']);
        $this->assertSame(419, $c->postNoToken('/login', ['email' => 'csrf@x.test', 'password' => self::PASSWORD, '_token' => ['x']])['status']);
        $this->assertSame(0, (int) Database::one("SELECT COUNT(*) c FROM audit_logs WHERE action LIKE 'auth.login.%'")['c'], 'a CSRF-rejected request must not even reach authentication');
        // A token from ANOTHER session is also invalid.
        $other = $this->client();
        $foreign = $other->token('/login');
        $this->assertSame(419, $c->postNoToken('/login', ['email' => 'csrf@x.test', 'password' => self::PASSWORD, '_token' => $foreign])['status']);
    }

    public function testSuccessfulLoginRegeneratesSessionIdAndRotatesCsrfToken(): void
    {
        $this->makeUser('fix@x.test', ['client']);
        $c = $this->client();
        $tokenBefore = $c->token('/login');
        $sidBefore = $c->cookie('vertex_sid');
        $this->assertNotNull($sidBefore);

        $r = $c->request('POST', '/login', ['email' => 'fix@x.test', 'password' => self::PASSWORD, '_token' => $tokenBefore]);
        $this->assertSame(303, $r['status']);
        $this->assertSame('/dashboard', $r['location']);
        $this->assertNotSame($sidBefore, $c->cookie('vertex_sid'), 'session id must change on login (fixation defence)');
        $this->assertNotSame($tokenBefore, $c->token('/account'), 'CSRF token rotates on login');
        $this->assertStringContainsString('Welcome, User fix@x.test', $c->get('/dashboard')['body']);
    }

    public function testAttackerChosenSessionIdIsNotAdopted(): void
    {
        $c = $this->client();
        $evil = str_repeat('a1', 13); // 26 chars, never issued by the server
        $c->request('GET', '/login', [], ["Cookie: vertex_sid={$evil}"]);
        $issued = null;
        foreach ($c->setCookies as $h) {
            if (preg_match('/vertex_sid=([^;]+)/', $h, $m)) {
                $issued = $m[1];
            }
        }
        $this->assertNotNull($issued, 'server must issue its own id');
        $this->assertNotSame($evil, $issued, 'strict mode: unknown ids are never adopted');
    }

    public function testWrongPasswordAndUnknownEmailLookIdenticalAndAreAudited(): void
    {
        $this->makeUser('real@x.test', ['client']);
        $bodies = [];
        foreach ([['real@x.test', 'wrong-password'], ['ghost@x.test', 'wrong-password']] as [$email, $pw]) {
            $c = $this->client();
            $r = $c->login($email, $pw);
            $this->assertSame(303, $r['status']);
            $this->assertSame('/login', $r['location']);
            $bodies[] = $c->get('/login')['body'];
        }
        foreach ($bodies as $b) {
            $this->assertStringContainsString('Those credentials did not work.', $b);
        }
        $this->assertSame(2, (int) Database::one("SELECT COUNT(*) c FROM audit_logs WHERE action = 'auth.login.failed'")['c']);
    }

    public function testDeactivatedAndPendingAccountsCannotLogInAndGetNoHint(): void
    {
        $this->makeUser('off@x.test', ['client'], 'deactivated');
        $this->makeUser('arch@x.test', ['client'], 'archived');
        $this->makeUser('wait@x.test', ['client'], 'pending');
        foreach (['off@x.test', 'arch@x.test'] as $email) {
            $c = $this->client();
            $c->login($email, self::PASSWORD);
            $this->assertStringContainsString('Those credentials did not work.', $c->get('/login')['body']);
            $this->assertSame(303, $c->get('/dashboard')['status']);
        }
        $c = $this->client();
        $c->login('wait@x.test', self::PASSWORD);
        $this->assertStringContainsString('not active yet', $c->get('/login')['body']);
        $dash = $c->get('/dashboard');
        $this->assertSame(303, $dash['status']);
        $this->assertSame('/login', $dash['location'], 'a pending account has no session');
    }

    public function testBruteForceLockoutBlocksEvenTheCorrectPassword(): void
    {
        $this->makeUser('brute@x.test', ['client']);
        $c = $this->client();
        for ($i = 0; $i < 5; $i++) {
            $c->login('brute@x.test', 'guess' . $i);
        }
        $c->login('brute@x.test', self::PASSWORD);
        $this->assertStringContainsString('Too many failed attempts', $c->get('/login')['body']);
        $this->assertSame(303, $c->get('/dashboard')['status'], 'still not signed in');
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) c FROM audit_logs WHERE action = 'auth.login.locked'")['c']);
    }

    public function testLogoutNeedsPostAndTokenAndDestroysTheSessionServerSide(): void
    {
        $this->makeUser('out@x.test', ['client']);
        $c = $this->loggedIn('out@x.test');
        $stolen = $c->cookie('vertex_sid');

        $this->assertContains($c->request('GET', '/logout')['status'], [404, 405], 'logout is not a GET link');
        $this->assertSame(419, $c->postNoToken('/logout')['status']);
        $this->assertSame(200, $c->get('/dashboard')['status'], 'failed logout attempts keep the session');

        $r = $c->post('/logout', [], '/account');
        $this->assertSame(303, $r['status']);
        $this->assertSame(303, $c->get('/dashboard')['status']);

        // Replaying the old cookie must not resurrect the session.
        $replay = $this->client();
        $res = $replay->request('GET', '/dashboard', [], ["Cookie: vertex_sid={$stolen}"]);
        $this->assertSame(303, $res['status'], 'destroyed session id is dead');
        $this->assertSame(1, (int) Database::one("SELECT COUNT(*) c FROM audit_logs WHERE action = 'auth.logout'")['c']);
    }

    public function testChangingPasswordSignsOutOtherSessionsButNotTheCurrentOne(): void
    {
        $this->makeUser('multi@x.test', ['client']);
        $laptop = $this->loggedIn('multi@x.test');
        $phone = $this->loggedIn('multi@x.test');

        $r = $laptop->post('/account/password', ['current_password' => self::PASSWORD, 'password' => 'a-brand-new-passphrase-55', 'password_confirmation' => 'a-brand-new-passphrase-55'], '/account');
        $this->assertSame(303, $r['status']);
        $this->assertSame(200, $laptop->get('/dashboard')['status'], 'current session survives');
        $this->assertSame(303, $phone->get('/dashboard')['status'], 'other session is signed out');
        $this->assertSame(303, $this->client()->login('multi@x.test', self::PASSWORD)['status']);
        $c = $this->client();
        $c->login('multi@x.test', 'a-brand-new-passphrase-55');
        $this->assertSame(200, $c->get('/dashboard')['status']);
    }

    public function testUnauthenticatedUsersAreRedirectedFromEveryProtectedPage(): void
    {
        $c = $this->client();
        foreach (['/dashboard', '/account', '/projects', '/admin/users', '/admin/users/create', '/admin/roles', '/admin/roles/client', '/admin/audit', '/projects/' . str_repeat('a', 32)] as $path) {
            $r = $c->get($path);
            $this->assertSame(303, $r['status'], $path);
            $this->assertSame('/login', $r['location'], $path);
        }
    }

    public function testSignedInUsersAreRedirectedAwayFromLogin(): void
    {
        $this->makeUser('already@x.test', ['client']);
        $c = $this->loggedIn('already@x.test');
        $this->assertSame('/dashboard', $c->get('/login')['location']);
    }

    private function headersOf(string $path): string
    {
        $ch = curl_init(self::$base . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_NOBODY => false]);
        $out = (string) curl_exec($ch);
        curl_close($ch);

        return substr($out, 0, (int) strpos($out, "\r\n\r\n"));
    }
}
