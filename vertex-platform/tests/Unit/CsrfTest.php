<?php

declare(strict_types=1);

namespace Vertex\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vertex\Core\Csrf;
use Vertex\Core\HttpException;
use Vertex\Core\Request;
use Vertex\Core\Response;
use Vertex\Http\Middleware\VerifyCsrfToken;

final class CsrfTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    public function testTokenIsStableWithinSessionAndLongEnough(): void
    {
        $t = Csrf::token();
        $this->assertSame(64, strlen($t));
        $this->assertSame($t, Csrf::token());
    }

    public function testVerifyAcceptsBodyTokenAndHeader(): void
    {
        $t = Csrf::token();
        $this->assertTrue(Csrf::verify(new Request('POST', '/', [], ['_token' => $t])));
        $this->assertTrue(Csrf::verify(new Request('POST', '/', [], [], ['HTTP_X_CSRF_TOKEN' => $t])));
    }

    public function testVerifyRejectsMissingWrongAndArrayTokens(): void
    {
        Csrf::token();
        $this->assertFalse(Csrf::verify(new Request('POST', '/')));
        $this->assertFalse(Csrf::verify(new Request('POST', '/', [], ['_token' => 'wrong'])));
        $this->assertFalse(Csrf::verify(new Request('POST', '/', [], ['_token' => ['x']])));
    }

    public function testNoSessionTokenMeansNothingVerifies(): void
    {
        $this->assertFalse(Csrf::verify(new Request('POST', '/', [], ['_token' => ''])));
    }

    public function testMiddlewareBlocksUnsafeMethodsWithoutToken(): void
    {
        $next = fn () => new Response(200, 'ok');
        $mw = new VerifyCsrfToken();
        foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $m) {
            try {
                $mw->handle(new Request($m, '/'), $next);
                $this->fail("{$m} should be rejected");
            } catch (HttpException $e) {
                $this->assertSame(419, $e->status);
            }
        }
        $this->assertSame('ok', $mw->handle(new Request('GET', '/'), $next)->body);
    }

    public function testMiddlewareAllowsValidTokenAndExplicitExemption(): void
    {
        $next = fn () => new Response(200, 'ok');
        $mw = new VerifyCsrfToken();
        $this->assertSame('ok', $mw->handle(new Request('POST', '/', [], ['_token' => Csrf::token()]), $next)->body);

        $exempt = new Request('POST', '/webhook');
        $exempt->attributes['route'] = ['csrf' => false];
        $this->assertSame('ok', $mw->handle($exempt, $next)->body);
    }
}
