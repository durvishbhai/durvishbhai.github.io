<?php

declare(strict_types=1);

namespace Vertex\Tests\Integration;

/** Maintenance mode: visitors and clients see the "back soon" page; the team, sign-in, health check and payment webhooks still work. */
final class MaintenanceHttpTest extends HttpTestCase
{
    protected static function extraEnv(): array
    {
        return ['MAINTENANCE_MODE' => 'true', 'MAINTENANCE_MESSAGE' => 'We are upgrading our servers until 6 PM.'];
    }

    public function testVisitorsGetTheBackSoonPageWithA503AndNothingIsIndexed(): void
    {
        $c = $this->client();
        $r = $c->get('/');
        $this->assertSame(503, $r['status']);
        $this->assertStringContainsString("We'll be back soon", $r['body']);
        $this->assertStringContainsString('We are upgrading our servers until 6 PM.', $r['body']);
        $this->assertStringContainsString('noindex', $r['body']);
        $this->assertSame('3600', $c->lastHeaders['retry-after']);
        $this->assertSame('no-store', $c->lastHeaders['cache-control']);
        $this->assertSame(503, $c->get('/services')['status']);
        $this->assertSame(503, $c->get('/request-quote')['status']);
        $this->assertSame(503, $c->get('/register')['status']);
        $this->assertSame(503, $c->get('/verify')['status']);
    }

    public function testSignInTheHealthCheckAndPaymentWebhooksStayOpen(): void
    {
        $c = $this->client();
        $this->assertSame(200, $c->get('/health')['status']);
        $this->assertSame(200, $c->get('/login')['status']);
        $hook = $c->post('/webhooks/razorpay', [], '/login');
        $this->assertNotSame(503, $hook['status'], 'a payment webhook must still be processed (it is refused for its own reasons, not for maintenance)');
    }

    public function testTheTeamKeepsWorkingButAClientSeesTheMaintenancePage(): void
    {
        $this->makeUser('owner@x.test', ['super_admin']);
        $this->makeUser('client@x.test', ['client']);
        $team = $this->loggedIn('owner@x.test');
        $this->assertSame(200, $team->get('/dashboard')['status']);
        $this->assertSame(200, $team->get('/')['status'], 'the team can see the public site to check it');
        $client = $this->client();
        $this->assertSame(303, $client->login('client@x.test', self::PASSWORD)['status']);
        $this->assertSame(503, $client->get('/dashboard')['status']);
    }
}
