<?php

declare(strict_types=1);

namespace Vertex\Http\Controllers;

use Vertex\Core\HttpException;
use Vertex\Core\Request;
use Vertex\Core\Response;
use Vertex\Core\SecurityHeadersPolicy;
use Vertex\Core\View;

/** The "You are offline" page and the small service worker that shows it when a device has no connection. Nothing about a person is ever cached. */
final class OfflineController extends Controller
{
    /** @param array<string,string> $params */
    public function show(Request $request, array $params = []): Response
    {
        return Response::html(View::render('pages/offline', ['title' => 'You are offline', 'noindex' => true], 'layouts/bare'), 200, ['Cache-Control' => 'no-cache', 'X-Robots-Tag' => 'noindex'] + SecurityHeadersPolicy::headers());
    }

    /** @param array<string,string> $params */
    public function worker(Request $request, array $params = []): Response
    {
        $file = (defined('VERTEX_PUBLIC') ? VERTEX_PUBLIC : VERTEX_ROOT . '/public') . '/assets/js/sw.js';
        if (!is_file($file)) {
            throw new HttpException(404);
        }

        // Served from the site root so it may control every page; the browser re-checks it at least daily.
        return new Response(200, (string) file_get_contents($file), ['Content-Type' => 'application/javascript; charset=UTF-8', 'Service-Worker-Allowed' => '/', 'Cache-Control' => 'no-cache'] + SecurityHeadersPolicy::headers());
    }
}
