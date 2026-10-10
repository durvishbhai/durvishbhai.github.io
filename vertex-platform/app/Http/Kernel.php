<?php

declare(strict_types=1);

namespace Vertex\Http;

use Throwable;
use Vertex\Core\ErrorHandler;
use Vertex\Core\Request;
use Vertex\Core\Response;
use Vertex\Core\Router;
use Vertex\Core\SecurityHeadersPolicy;
use Vertex\Http\Middleware\AddSecurityHeaders;
use Vertex\Http\Middleware\RedirectIfAuthenticated;
use Vertex\Http\Middleware\RequireAuth;
use Vertex\Http\Middleware\RequirePermission;
use Vertex\Http\Middleware\StartSession;
use Vertex\Http\Middleware\VerifyCsrfToken;

/**
 * Global pipeline (outermost first): security headers -> session -> CSRF -> route middleware -> controller.
 * Route middleware aliases: auth, guest, can:<permission>[,<permission>].
 */
final class Kernel
{
    public static function router(): Router
    {
        $router = new Router(
            ['auth' => RequireAuth::class, 'guest' => RedirectIfAuthenticated::class, 'can' => RequirePermission::class],
            [AddSecurityHeaders::class, StartSession::class, VerifyCsrfToken::class],
        );
        (require VERTEX_ROOT . '/app/routes.php')($router);

        return $router;
    }

    public static function handle(Request $request): Response
    {
        try {
            return self::router()->dispatch($request);
        } catch (Throwable $e) {
            $response = ErrorHandler::render($e, $request);
            foreach (SecurityHeadersPolicy::headers() as $k => $v) {
                $response->headers[$k] ??= $v;
            }
            $response->headers['Cache-Control'] ??= 'no-store';

            return $response;
        }
    }
}
