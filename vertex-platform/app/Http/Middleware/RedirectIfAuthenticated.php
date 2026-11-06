<?php

declare(strict_types=1);

namespace Vertex\Http\Middleware;

use Vertex\Core\Request;
use Vertex\Core\Response;
use Vertex\Services\Auth;

final class RedirectIfAuthenticated implements Middleware
{
    public function __construct(?string $arg = null)
    {
    }

    public function handle(Request $request, callable $next): Response
    {
        return Auth::user() !== null ? Response::redirect('/dashboard') : $next($request);
    }
}
