<?php

declare(strict_types=1);

namespace Vertex\Core;

use Closure;
use Vertex\Http\Middleware\Middleware;

/**
 * Small explicit router.
 *
 *   $router->get('/projects/{id}', [ProjectController::class, 'show'], ['auth', 'can:projects.view_assigned']);
 *
 * Route options: 'middleware' (list of aliases), 'csrf' (bool, default true for unsafe methods).
 * Controllers are `fn(Request $request, array $params): Response`.
 */
final class Router
{
    /** @var list<array{method:string,pattern:string,regex:string,handler:mixed,middleware:list<string>,csrf:bool}> */
    private array $routes = [];

    private string $prefix = '';

    /** @var list<string> */
    private array $groupMiddleware = [];

    /** @var array<string,class-string<Middleware>> */
    private array $aliases = [];

    /** @var list<class-string<Middleware>|Middleware> */
    private array $global = [];

    /** @param array<string,class-string<Middleware>> $aliases @param list<class-string<Middleware>|Middleware> $global */
    public function __construct(array $aliases = [], array $global = [])
    {
        $this->aliases = $aliases;
        $this->global = $global;
    }

    /** @param list<string> $middleware */
    public function group(string $prefix, array $middleware, callable $define): void
    {
        $previous = [$this->prefix, $this->groupMiddleware];
        $this->prefix .= $prefix;
        $this->groupMiddleware = array_merge($this->groupMiddleware, $middleware);
        $define($this);
        [$this->prefix, $this->groupMiddleware] = $previous;
    }

    /** @param list<string> $middleware */
    public function get(string $path, mixed $handler, array $middleware = []): void
    {
        $this->add('GET', $path, $handler, $middleware);
    }

    /** @param list<string> $middleware */
    public function post(string $path, mixed $handler, array $middleware = [], bool $csrf = true): void
    {
        $this->add('POST', $path, $handler, $middleware, $csrf);
    }

    /** @param list<string> $middleware */
    public function add(string $method, string $path, mixed $handler, array $middleware = [], bool $csrf = true): void
    {
        $full = '/' . trim($this->prefix . '/' . trim($path, '/'), '/');
        $regex = '#^' . preg_replace('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', '(?P<$1>[^/]+)', $full) . '$#';
        $this->routes[] = [
            'method' => strtoupper($method),
            'pattern' => $full,
            'regex' => $regex,
            'handler' => $handler,
            'middleware' => array_merge($this->groupMiddleware, $middleware),
            'csrf' => $csrf,
        ];
    }

    public function dispatch(Request $request): Response
    {
        $allowed = [];
        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $request->path, $m)) {
                continue;
            }
            if ($route['method'] !== $request->method) {
                $allowed[] = $route['method'];
                continue;
            }
            $params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
            $request->attributes['route'] = ['pattern' => $route['pattern'], 'csrf' => $route['csrf']];

            $pipeline = array_merge($this->global, $this->resolve($route['middleware']));
            $core = fn (Request $r): Response => $this->invoke($route['handler'], $r, $params);

            return $this->run($pipeline, $request, $core);
        }

        if ($allowed !== []) {
            // Still run global middleware (headers, session) on the error response via the exception path.
            throw new HttpException(405, '', ['Allow' => implode(', ', array_unique($allowed))]);
        }
        throw new HttpException(404);
    }

    /** @param list<string> $aliases @return list<Middleware> */
    private function resolve(array $aliases): array
    {
        $out = [];
        foreach ($aliases as $alias) {
            [$name, $arg] = array_pad(explode(':', $alias, 2), 2, null);
            if (!isset($this->aliases[$name])) {
                throw new \LogicException("Unknown middleware alias '{$name}'.");
            }
            $class = $this->aliases[$name];
            $out[] = new $class($arg);
        }

        return $out;
    }

    /** @param list<class-string<Middleware>|Middleware> $pipeline */
    private function run(array $pipeline, Request $request, Closure $core): Response
    {
        $next = array_reduce(
            array_reverse($pipeline),
            static function (Closure $next, string|Middleware $mw): Closure {
                $instance = is_string($mw) ? new $mw() : $mw;

                return static fn (Request $r): Response => $instance->handle($r, $next);
            },
            $core,
        );

        return $next($request);
    }

    /** @param array<string,string> $params */
    private function invoke(mixed $handler, Request $request, array $params): Response
    {
        if (is_array($handler) && is_string($handler[0])) {
            $handler = [new $handler[0](), $handler[1]];
        }
        $result = $handler($request, $params);
        if (!$result instanceof Response) {
            throw new \LogicException('Route handler must return a Response.');
        }

        return $result;
    }
}
