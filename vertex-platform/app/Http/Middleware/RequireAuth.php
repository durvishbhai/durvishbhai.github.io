<?php

declare(strict_types=1);

namespace Vertex\Http\Middleware;

use Vertex\Core\Request;
use Vertex\Core\Response;
use Vertex\Core\Session;
use Vertex\Services\Auth;
use Vertex\Services\TwoFactorService;

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

        // Roles for which two-factor login is compulsory (TWO_FACTOR_REQUIRED_LEVEL) can only reach the setup page until it is on.
        $id = (int) Auth::id();
        if (!str_starts_with($request->path, '/account/two-factor') && $request->path !== '/logout' && TwoFactorService::requiredFor($id) && !TwoFactorService::isEnabled((array) Auth::user())) {
            Session::flash('notice', 'Your role requires two-factor login. Set it up to continue.');

            return Response::redirect('/account/two-factor');
        }

        return $next($request);
    }
}
