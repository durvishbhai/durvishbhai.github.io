<?php

declare(strict_types=1);

namespace Vertex\Http\Middleware;

use Vertex\Core\Request;
use Vertex\Core\Response;
use Vertex\Core\Session;
use Vertex\Services\Auth;

final class RequireAuth implements Middleware
{
    public function __construct(?string $arg = null)
    {
    }

    public function handle(Request $request, callable $next): Response
    {
        if (Auth::user() === null) {
            if ($request->method === 'GET') {
                Session::put('intended', $request->path);
            }

            return Response::redirect('/login');
        }

        return $next($request);
    }
}
