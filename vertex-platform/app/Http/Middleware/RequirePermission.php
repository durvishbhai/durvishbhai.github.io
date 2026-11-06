<?php

declare(strict_types=1);

namespace Vertex\Http\Middleware;

use Vertex\Core\HttpException;
use Vertex\Core\Request;
use Vertex\Core\Response;
use Vertex\Services\Auth;
use Vertex\Services\Gate;

/**
 * Route alias `can:perm.key` (or `can:a,b` = any of). Unauthenticated users are redirected to login, authenticated
 * users lacking the permission get 403. This checks the role permission only; row-level scope is a policy concern.
 */
final class RequirePermission implements Middleware
{
    /** @var list<string> */
    private array $permissions;

    public function __construct(?string $arg = null)
    {
        $this->permissions = array_values(array_filter(array_map('trim', explode(',', (string) $arg))));
        if ($this->permissions === []) {
            throw new \LogicException('can: middleware requires a permission key.');
        }
    }

    public function handle(Request $request, callable $next): Response
    {
        if (Auth::user() === null) {
            return Response::redirect('/login');
        }
        if (!Gate::canAny($this->permissions)) {
            throw new HttpException(403);
        }

        return $next($request);
    }
}
