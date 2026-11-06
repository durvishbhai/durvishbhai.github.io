<?php

declare(strict_types=1);

namespace Vertex\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vertex\Core\HttpException;
use Vertex\Core\Request;
use Vertex\Core\Response;
use Vertex\Core\Router;
use Vertex\Http\Middleware\Middleware;

final class TraceMiddleware implements Middleware
{
    public static array $log = [];

    public function __construct(private ?string $arg = null)
    {
    }

    public function handle(Request $request, callable $next): Response
    {
        self::$log[] = 'trace:' . ($this->arg ?? '');

        return $next($request);
    }
}

final class BlockMiddleware implements Middleware
{
    public function __construct(?string $arg = null)
    {
    }

    public function handle(Request $request, callable $next): Response
    {
        return new Response(403, 'blocked');
    }
}

final class RouterTest extends TestCase
{
    private function router(): Router
    {
        TraceMiddleware::$log = [];

        return new Router(['trace' => TraceMiddleware::class, 'block' => BlockMiddleware::class], []);
    }

    public function testMatchesStaticAndParameterisedRoutes(): void
    {
        $r = $this->router();
        $r->get('/projects/{id}/files/{file}', fn (Request $q, array $p) => new Response(200, json_encode($p)));
        $res = $r->dispatch(new Request('GET', '/projects/42/files/a.pdf'));
        $this->assertSame('{"id":"42","file":"a.pdf"}', $res->body);
    }

    public function testUnknownPathIs404(): void
    {
        $r = $this->router();
        $r->get('/a', fn () => new Response());
        $this->expectException(HttpException::class);
        $this->expectExceptionCode(404);
        $r->dispatch(new Request('GET', '/b'));
    }

    public function testWrongMethodIs405WithAllowHeader(): void
    {
        $r = $this->router();
        $r->get('/a', fn () => new Response());
        $r->post('/a', fn () => new Response());
        try {
            $r->dispatch(new Request('DELETE', '/a'));
            $this->fail('expected 405');
        } catch (HttpException $e) {
            $this->assertSame(405, $e->status);
            $this->assertSame('GET, POST', $e->headers['Allow']);
        }
    }

    public function testGroupPrefixAndMiddlewareOrderWithArguments(): void
    {
        $r = $this->router();
        $r->group('/admin', ['trace:outer'], function (Router $r): void {
            $r->get('/x', fn () => new Response(200, 'ok'), ['trace:inner']);
        });
        $res = $r->dispatch(new Request('GET', '/admin/x'));
        $this->assertSame('ok', $res->body);
        $this->assertSame(['trace:outer', 'trace:inner'], TraceMiddleware::$log);
    }

    public function testMiddlewareCanShortCircuit(): void
    {
        $r = $this->router();
        $r->get('/secret', fn () => new Response(200, 'secret'), ['block']);
        $res = $r->dispatch(new Request('GET', '/secret'));
        $this->assertSame(403, $res->status);
        $this->assertSame('blocked', $res->body);
    }

    public function testUnknownMiddlewareAliasFailsLoudly(): void
    {
        $r = $this->router();
        $r->get('/a', fn () => new Response(), ['nonexistent']);
        $this->expectException(\LogicException::class);
        $r->dispatch(new Request('GET', '/a'));
    }

    public function testRouteExposesCsrfFlagToMiddleware(): void
    {
        $r = $this->router();
        $r->post('/webhook', fn () => new Response(), [], false);
        $req = new Request('POST', '/webhook');
        $r->dispatch($req);
        $this->assertFalse($req->attributes['route']['csrf']);
    }
}
