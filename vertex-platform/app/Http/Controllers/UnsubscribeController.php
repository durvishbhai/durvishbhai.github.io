<?php

declare(strict_types=1);

namespace Vertex\Http\Controllers;

use Vertex\Core\HttpException;
use Vertex\Core\Request;
use Vertex\Core\Response;
use Vertex\Core\Session;
use Vertex\Core\View;
use Vertex\Services\Marketing;

/** Unsubscribe from MARKETING email. The link carries a signed token for one address; security and transactional emails are not affected and have no such link. */
final class UnsubscribeController extends Controller
{
    private function render(string $view, string $title, array $data): Response
    {
        $html = View::render($view, ['title' => $title, 'meta' => [], 'noindex' => true, 'errors' => [], 'notice' => null] + $data);

        return Response::html($html, 200, ['Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer']);
    }

    /** @param array<string,string> $params */
    public function show(Request $request, array $params = []): Response
    {
        $email = Marketing::emailFromToken((string) ($params['token'] ?? ''));

        return $this->render('site/unsubscribe', 'Unsubscribe', ['ok' => $email !== null, 'email' => $email, 'token' => (string) ($params['token'] ?? ''), 'done' => false]);
    }

    /** @param array<string,string> $params */
    public function confirm(Request $request, array $params = []): Response
    {
        $email = Marketing::emailFromToken((string) ($params['token'] ?? '')) ?? throw new HttpException(404);
        Marketing::unsubscribe($email);

        return $this->render('site/unsubscribe', 'Unsubscribed', ['ok' => true, 'email' => $email, 'token' => $params['token'], 'done' => true]);
    }

    /** The one-click form of the same thing (mail programs post here). The signed token is the proof, so no session or CSRF token is involved. @param array<string,string> $params */
    public function oneClick(Request $request, array $params = []): Response
    {
        $email = Marketing::emailFromToken((string) ($params['token'] ?? '')) ?? throw new HttpException(404);
        Marketing::unsubscribe($email);

        return Response::json(['ok' => true]);
    }
}
