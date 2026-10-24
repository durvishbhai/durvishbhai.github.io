<?php

declare(strict_types=1);

namespace Vertex\Tests\Integration;

use Vertex\Http\Kernel;
use Vertex\Services\Gate;

/**
 * Whole-application sweep driven by the REAL route table (so a new route cannot be forgotten):
 *  - every route that is not on the explicit public allow-list requires a signed-in user;
 *  - every state-changing route verifies the CSRF token (no route opts out);
 *  - for every role, a route whose `can:` gate the user does not satisfy answers 403 (never 200), for GET and for POST with a valid token;
 *  - no request, with junk identifiers or empty forms, ever produces a 5xx.
 */
final class RouteSweepTest extends HttpTestCase
{
    /** Routes that are intentionally reachable without signing in. Anything else without `auth` or `can:` fails the test. */
    private const PUBLIC = [
        '/', '/health', '/login', '/login/two-factor', '/register', '/auth/google', '/auth/google/callback', '/auth/google/complete', '/verify-email', '/verify-email/{token}', '/forgot-password', '/reset-password/{token}', '/invite/{token}',
        '/verify', '/verify/{code}', '/pay/{order}', '/pay/{order}/status', '/pay/{order}/checkout', '/pay/{order}/verify', '/request-quote', '/contact', '/book-appointment', '/enquiry/received', '/partners/apply', '/partners/apply/received', '/f/{slug}', '/f/{slug}/thanks',
        '/sitemap.xml', '/robots.txt', '/webhooks/razorpay', '/{slug}', '/{section}/{slug}',
        '/about', '/services', '/services/{slug}', '/our-team', '/our-clients', '/our-partners', '/portfolio', '/portfolio/{slug}', '/careers', '/careers/received', '/careers/{slug}', '/careers/apply', '/hiring', '/hiring/received', '/hiring/apply', '/faq', '/sitemap', '/project-requirements',
        '/privacy-policy', '/terms-and-conditions', '/refund-and-cancellation-policy', '/payment-policy', '/cookie-policy',
    ];

    /** Machine-to-machine callbacks that cannot carry a CSRF token. Each one authenticates the caller by a secret signature instead (see Phase12HttpTest). */
    private const SIGNED_WEBHOOKS = ['/webhooks/razorpay'];

    /** @var list<array<string,mixed>> */
    private array $routes = [];
    /** @var array<string,int> */
    private array $users = [];

    protected function setUp(): void
    {
        parent::setUp();
        \Vertex\Services\PermissionSync::run(true);
        $this->users = [
            'owner' => $this->makeUser('owner@x.test', ['super_admin']),
            'admin' => $this->makeUser('admin@x.test', ['administrator']),
            'manager' => $this->makeUser('mgr@x.test', ['manager']),
            'finance' => $this->makeUser('fin@x.test', ['finance_staff']),
            'support' => $this->makeUser('sup@x.test', ['support_staff']),
            'dev' => $this->makeUser('dev@x.test', ['website_developer']),
            'freelancer' => $this->makeUser('free@x.test', ['freelancer']),
            'partner' => $this->makeUser('ref@x.test', ['referral_partner']),
            'client' => $this->makeUser('client@x.test', ['client']),
        ];
        $ref = new \ReflectionProperty(\Vertex\Core\Router::class, 'routes');
        $this->routes = $ref->getValue(Kernel::router());
    }

    private function concrete(string $pattern): string
    {
        return (string) preg_replace_callback('/\{(\w+)\}/', static fn (array $m): string => $m[1] === 'token' ? str_repeat('0', 64) : str_repeat('0', 32), $pattern);
    }

    /** @return list<string> permissions of the first `can:` gate, or [] */
    private function gate(array $route): array
    {
        foreach ($route['middleware'] as $m) {
            if (str_starts_with($m, 'can:')) {
                return array_map('trim', explode(',', substr($m, 4)));
            }
        }

        return [];
    }

    private function isProtected(array $route): bool
    {
        return in_array('auth', $route['middleware'], true) || $this->gate($route) !== [];
    }

    public function testTheRouteTableIsNotEmptyAndEveryUnprotectedRouteIsOnTheAllowList(): void
    {
        $this->assertGreaterThan(150, count($this->routes));
        $unprotected = [];
        foreach ($this->routes as $r) {
            if (!$this->isProtected($r)) {
                $unprotected[$r['pattern']] = true;
            }
        }
        foreach (array_keys($unprotected) as $p) {
            $this->assertContains($p, self::PUBLIC, "{$p} is reachable without signing in but is not on the public allow-list");
        }
    }

    public function testNoRouteOptsOutOfCsrfProtection(): void
    {
        foreach ($this->routes as $r) {
            if ($r['method'] !== 'GET' && !in_array($r['pattern'], self::SIGNED_WEBHOOKS, true)) {
                $this->assertTrue($r['csrf'], "{$r['method']} {$r['pattern']} has CSRF disabled");
            }
        }
    }

    public function testAnonymousVisitorsAreSentToSignInOnEveryProtectedRoute(): void
    {
        $anon = $this->client();
        $n = 0;
        foreach ($this->routes as $r) {
            if (!$this->isProtected($r) || $r['pattern'] === '/logout') {
                continue;
            }
            $path = $this->concrete($r['pattern']);
            if ($r['method'] === 'GET') {
                $res = $anon->get($path);
                $this->assertSame(303, $res['status'], "GET {$path}");
                $this->assertSame('/login', $res['location'], "GET {$path}");
            } else {
                $this->assertSame(419, $anon->postNoToken($path, ['x' => 'y'])['status'], "POST {$path} without a token");
                $res = $anon->post($path, ['x' => 'y'], '/login');
                $this->assertSame(303, $res['status'], "POST {$path}");
                $this->assertSame('/login', $res['location'], "POST {$path}");
            }
            $n++;
        }
        $this->assertGreaterThan(120, $n);
    }

    public function testEveryStateChangingRouteRejectsAMissingTokenEvenWhenSignedIn(): void
    {
        $c = $this->loggedIn('admin@x.test');
        $n = 0;
        foreach ($this->routes as $r) {
            if ($r['method'] === 'GET' || $r['pattern'] === '/logout' || in_array($r['pattern'], self::SIGNED_WEBHOOKS, true)) {
                continue;
            }
            $this->assertSame(419, $c->postNoToken($this->concrete($r['pattern']), ['x' => 'y'])['status'], "{$r['method']} {$r['pattern']}");
            $n++;
        }
        $this->assertGreaterThan(60, $n);
    }

    public function testEveryRoleIsRefusedWhereItsPermissionsDoNotReachAndNothingErrors(): void
    {
        $checked = 0;
        $refused = 0;
        foreach ($this->users as $label => $uid) {
            $email = \Vertex\Core\Database::one('SELECT email FROM users WHERE id = ?', [$uid])['email'];
            $c = $this->loggedIn($email);
            $token = $c->token('/account');
            \Vertex\Services\Auth::reset();
            Gate::flush();
            foreach ($this->routes as $r) {
                if (!$this->isProtected($r) || in_array($r['pattern'], ['/logout', '/account/password', '/account'], true) || $r['method'] === 'POST' && str_contains($r['pattern'], '/audit/verify')) {
                    continue;
                }
                $needs = $this->gate($r);
                $path = $this->concrete($r['pattern']);
                $holds = $needs === [] || array_reduce($needs, fn (bool $a, string $p): bool => $a || Gate::can($p, $uid), false);
                $res = $r['method'] === 'GET' ? $c->get($path) : $c->request('POST', $path, ['_token' => $token]);
                $this->assertLessThan(500, $res['status'], "{$label}: {$r['method']} {$path} produced a server error");
                if (!$holds) {
                    $this->assertSame(403, $res['status'], "{$label} lacks " . implode('|', $needs) . " but {$r['method']} {$path} answered {$res['status']}");
                    $refused++;
                }
                $checked++;
            }
        }
        $this->assertGreaterThan(1200, $checked);
        $this->assertGreaterThan(500, $refused);
    }

    public function testHostileQueryStringsNeverReachSqlOrBreakAnyListPage(): void
    {
        $names = ['q', 'search', 'status', 'stage', 'priority', 'service', 'client', 'project', 'owner', 'assignee', 'kind', 'category', 'severity', 'group', 'from', 'to', 'page', 'sort', 'order', 'type',
            'source', 'scope', 'mine', 'open', 'overdue', 'archived', 'followup', 'unread', 'role', 'action', 'actor', 'entity', 'expiring', 'escalated', 'due', 'per_page', 'limit', 'offset', 'id'];
        $payloads = ["' OR '1'='1", "'; DROP TABLE users; --", '" OR ""="', '%', '\\', '1 UNION SELECT password_hash FROM users', "\x00", str_repeat('A', 5000), '-1', '99999999999999999999', '[]', "'\"<script>alert(1)</script>"];
        $c = $this->loggedIn('owner@x.test');
        $n = 0;
        foreach ($this->routes as $r) {
            if ($r['method'] !== 'GET' || !$this->isProtected($r) || $r['pattern'] === '/logout') {
                continue;
            }
            foreach ($payloads as $i => $payload) {
                $qs = http_build_query(array_fill_keys($names, $payload));
                $res = $c->get($this->concrete($r['pattern']) . '?' . $qs);
                $this->assertLessThan(500, $res['status'], "GET {$r['pattern']} with payload #{$i} produced a server error");
                $this->assertDoesNotMatchRegularExpression('/SQLSTATE|You have an error in your SQL|PDOException|Stack trace/i', $res['body'], "GET {$r['pattern']} payload #{$i} leaked an SQL/PHP error");
                $n++;
            }
        }
        $this->assertGreaterThan(600, $n);
        $this->assertSame(9, (int) \Vertex\Core\Database::one('SELECT COUNT(*) c FROM users')['c'], 'no payload altered the users table');
    }
}
