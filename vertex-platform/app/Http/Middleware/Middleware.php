<?php

declare(strict_types=1);

namespace Vertex\Http\Middleware;

use Vertex\Core\Request;
use Vertex\Core\Response;

interface Middleware
{
    /** @param callable(Request):Response $next */
    public function handle(Request $request, callable $next): Response;
}
