<?php

declare(strict_types=1);

namespace Vertex\Http\Middleware;

use Vertex\Core\Config;
use Vertex\Core\Request;
use Vertex\Core\Response;
use Vertex\Core\View;
use Vertex\Services\Auth;
use Vertex\Services\Gate;

/** Shows the "back soon" page (HTTP 503) to everyone except signed-in team members while maintenance mode is on. */
final class Maintenance implements Middleware
{
    public function handle(Request $request, callable $next): Response
    {
        if (!(bool) Config::get('maintenance.enabled', false) || self::open($request->path) || self::team()) {
            return $next($request);
        }
        $retry = (int) Config::get('maintenance.retry_after_seconds', 3600);
        if ($request->expectsJson()) {
            return new Response(503, (string) json_encode(['error' => 'The site is under maintenance. Please try again soon.']), ['Content-Type' => 'application/json; charset=UTF-8', 'Retry-After' => (string) $retry, 'Cache-Control' => 'no-store']);
        }
        $message = trim((string) Config::get('maintenance.message', ''));

        return new Response(503, View::render('pages/maintenance', ['title' => 'Back soon', 'message' => $message], 'layouts/bare'), [
            'Content-Type' => 'text/html; charset=UTF-8', 'Retry-After' => (string) $retry, 'Cache-Control' => 'no-store', 'X-Robots-Tag' => 'noindex',
        ]);
    }

    private static function open(string $path): bool
    {
        foreach ((array) Config::get('maintenance.open_paths', []) as $p) {
            if ($path === $p || (str_ends_with((string) $p, '/') && str_starts_with($path, (string) $p))) {
                return true;
            }
        }

        return false;
    }

    /** A signed-in person who is not only a client or referral partner. */
    private static function team(): bool
    {
        try {
            $id = Auth::id();
            if ($id === null) {
                return false;
            }

            return array_diff(Gate::roleSlugs($id), ['client', 'referral_partner']) !== [];
        } catch (\Throwable) {
            return false;
        }
    }
}
