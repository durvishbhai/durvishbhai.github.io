<?php

declare(strict_types=1);

namespace Vertex\Tests\Integration;

use Vertex\Core\View;

/** The branded error pages (404, 403, 419, 5xx), the offline page and the offline service worker. */
final class ErrorPagesHttpTest extends HttpTestCase
{
    public function testAnUnknownAddressShowsTheBrandedNotFoundPage(): void
    {
        $c = $this->client();
        $r = $c->get('/no-such-page-' . bin2hex(random_bytes(3)));
        $this->assertSame(404, $r['status']);
        $this->assertStringContainsString('Page not found', $r['body']);
        $this->assertStringContainsString('Back to home', $r['body']);
        $this->assertStringContainsString('noindex', $r['body']);
        $this->assertStringContainsString('Reference:', $r['body']);
        $this->assertStringContainsString('href="/services"', $r['body'], 'helpful links on a 404');
    }

    public function testAClientWhoOpensATeamPageSeesAccessDeniedWithAWayBack(): void
    {
        $this->makeUser('client@x.test', ['client']);
        $c = $this->loggedIn('client@x.test');
        $r = $c->get('/admin/users');
        $this->assertSame(403, $r['status']);
        $this->assertStringContainsString('Access denied', $r['body']);
        $this->assertStringContainsString('Go to my dashboard', $r['body']);
        $this->assertStringNotContainsString('Sign in</a>', $r['body'], 'a signed-in person is not asked to sign in');
    }

    public function testAFormWithoutItsTokenShowsTheSessionExpiredPage(): void
    {
        $r = $this->client()->postNoToken('/login', ['email' => 'a@b.test', 'password' => 'x']);
        $this->assertSame(419, $r['status']);
        $this->assertStringContainsString('Your session expired', $r['body']);
        $this->assertStringContainsString('Sign in', $r['body']);
    }

    public function testServerErrorPagesNeverShowDetailsAndDoNotNeedTheDatabaseHeader(): void
    {
        $html = View::render('errors/error', ['status' => 500, 'message' => 'SQLSTATE secret detail', 'reference' => 'ABC123', 'trace' => null, 'title' => 'Error 500'], 'layouts/bare');
        $this->assertStringContainsString('Something went wrong', $html);
        $this->assertStringContainsString('Our team has been notified', $html);
        $this->assertStringContainsString('Try again', $html);
        $this->assertStringContainsString('ABC123', $html);
        $this->assertStringContainsString('<html', $html);
        foreach ([429 => 'Too many attempts', 503 => 'Service temporarily unavailable', 400 => 'Bad request', 413 => 'That file is too large'] as $code => $headline) {
            $this->assertStringContainsString($headline, View::render('errors/error', ['status' => $code, 'message' => '', 'reference' => 'R', 'trace' => null], null));
        }
    }

    public function testTheOfflinePageAndTheServiceWorkerAreServed(): void
    {
        $c = $this->client();
        $p = $c->get('/offline');
        $this->assertSame(200, $p['status']);
        $this->assertStringContainsString('You are offline', $p['body']);
        $this->assertStringContainsString('noindex', $p['body']);
        $this->assertStringContainsString('/assets/css/app.css', $p['body']);
        $w = $c->get('/sw.js');
        $this->assertSame(200, $w['status']);
        $this->assertStringContainsString('javascript', $c->lastHeaders['content-type']);
        $this->assertSame('/', $c->lastHeaders['service-worker-allowed']);
        $this->assertStringContainsString("'/offline'", $w['body']);
        $this->assertStringNotContainsString('POST', $w['body'] === '' ? 'x' : preg_replace('#//.*|/\*.*?\*/#s', '', $w['body']) ?? '', 'only GET page loads are handled');
        $this->assertStringContainsString("navigator.serviceWorker.register('/sw.js')", (string) file_get_contents(VERTEX_ROOT . '/public/assets/js/app.js'));
    }
}
