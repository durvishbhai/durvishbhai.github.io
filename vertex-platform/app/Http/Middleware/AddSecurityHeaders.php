<?php

declare(strict_types=1);

namespace Vertex\Http\Middleware;

use Vertex\Core\Request;
use Vertex\Core\Response;
use Vertex\Core\SecurityHeadersPolicy;

final class AddSecurityHeaders implements Middleware
{
    public function handle(Request $request, callable $next): Response
    {
        $response = $next($request);
        foreach (SecurityHeadersPolicy::headers() as $name => $value) {
            $response->headers[$name] ??= $value;
        }
        // Authenticated pages must never be cached by browsers or shared proxies.
        $response->headers['Cache-Control'] ??= 'no-store';

        return $response;
    }
}
