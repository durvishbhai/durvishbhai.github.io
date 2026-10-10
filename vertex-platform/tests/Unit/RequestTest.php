<?php

declare(strict_types=1);

namespace Vertex\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vertex\Core\Config;
use Vertex\Core\Request;

final class RequestTest extends TestCase
{
    protected function tearDown(): void
    {
        Config::set('app.trusted_proxies', []);
    }

    public function testForwardedForIsIgnoredFromUntrustedClients(): void
    {
        Config::set('app.trusted_proxies', []);
        $r = new Request('GET', '/', [], [], ['REMOTE_ADDR' => '198.51.100.7', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4']);
        $this->assertSame('198.51.100.7', $r->ip());
    }

    public function testForwardedForIsHonouredOnlyFromTrustedProxy(): void
    {
        Config::set('app.trusted_proxies', ['10.0.0.1']);
        $r = new Request('GET', '/', [], [], ['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => '203.0.113.9, 10.0.0.1']);
        $this->assertSame('203.0.113.9', $r->ip());

        $bad = new Request('GET', '/', [], [], ['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => 'not-an-ip']);
        $this->assertSame('10.0.0.1', $bad->ip());
    }

    public function testSecureDetection(): void
    {
        $this->assertTrue((new Request('GET', '/', [], [], ['HTTPS' => 'on']))->isSecure());
        $this->assertFalse((new Request('GET', '/', [], [], ['HTTPS' => 'off']))->isSecure());
        $this->assertFalse((new Request('GET', '/', [], [], ['HTTP_X_FORWARDED_PROTO' => 'https']))->isSecure());
    }
}
