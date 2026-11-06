<?php

declare(strict_types=1);

namespace Vertex\Http\Middleware;

use Vertex\Core\HttpException;
use Vertex\Core\Request;
use Vertex\Core\Response;
use Vertex\Services\Auth;
use Vertex\Services\ProjectPolicy;

/**
 * Route alias `project`: for any route with a {project} parameter (public id). Resolves the project, runs the
 * ProjectPolicy for the signed-in user and exposes it as $request->attributes['project']. Unknown or inaccessible
 * projects both return 404. Every present and future project-scoped route (files, tasks, chat ...) must carry it.
 */
final class RequireProjectAccess implements Middleware
{
    public function __construct(?string $arg = null)
    {
    }

    public function handle(Request $request, callable $next): Response
    {
        $userId = Auth::id() ?? throw new HttpException(404);
        $publicId = (string) ($request->attributes['params']['project'] ?? '');
        $request->attributes['project'] = ProjectPolicy::requireView($userId, $publicId);

        return $next($request);
    }
}
