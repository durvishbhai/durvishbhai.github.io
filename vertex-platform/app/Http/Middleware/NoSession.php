<?php

declare(strict_types=1);

namespace Vertex\Http\Middleware;

use Vertex\Core\Request;
use Vertex\Core\Response;

/** Marker alias: StartSession skips creating a session for anonymous visitors on routes that carry it. */
final class NoSession implements Middleware
{
    public function __construct(?string $arg = null)
    {
    }

    public function handle(Request $request, callable $next): Response
    {
        return $next($request);
    }
}
