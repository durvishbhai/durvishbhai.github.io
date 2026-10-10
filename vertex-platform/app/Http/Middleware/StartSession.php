<?php

declare(strict_types=1);

namespace Vertex\Http\Middleware;

use Vertex\Core\Request;
use Vertex\Core\Response;
use Vertex\Core\Session;

final class StartSession implements Middleware
{
    public function handle(Request $request, callable $next): Response
    {
        Session::start($request);

        return $next($request);
    }
}
