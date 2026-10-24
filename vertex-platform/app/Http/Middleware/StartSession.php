<?php

declare(strict_types=1);

namespace Vertex\Http\Middleware;

use Vertex\Core\Config;
use Vertex\Core\Request;
use Vertex\Core\Response;
use Vertex\Core\Session;

final class StartSession implements Middleware
{
    public function handle(Request $request, callable $next): Response
    {
        // Public pages without forms ('nosession') don't create a session for anonymous visitors (no session-file growth),
        // but a visitor who already has a session cookie still gets it loaded so the header shows their state.
        $skip = in_array('nosession', $request->attributes['route']['middleware'] ?? [], true)
            && $request->cookie((string) Config::get('session.name', 'vertex_sid')) === null;
        if (!$skip) {
            Session::start($request);
        }

        return $next($request);
    }
}
