<?php

declare(strict_types=1);

namespace Vertex\Http\Middleware;

use Vertex\Core\Csrf;
use Vertex\Core\HttpException;
use Vertex\Core\Request;
use Vertex\Core\Response;

/**
 * Global: every POST/PUT/PATCH/DELETE must carry a valid token. A route opts out ONLY by being registered with
 * csrf=false (reserved for signature-verified webhooks, e.g. the payment gateway in a later phase).
 */
final class VerifyCsrfToken implements Middleware
{
    public function handle(Request $request, callable $next): Response
    {
        $unsafe = !in_array($request->method, ['GET', 'HEAD', 'OPTIONS'], true);
        $required = ($request->attributes['route']['csrf'] ?? true) === true;

        if ($unsafe && $required && !Csrf::verify($request)) {
            throw new HttpException(419);
        }

        return $next($request);
    }
}
