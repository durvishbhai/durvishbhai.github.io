<?php

declare(strict_types=1);

namespace Vertex\Http\Controllers;

use Vertex\Core\Request;
use Vertex\Core\Response;
use Vertex\Core\Session;
use Vertex\Core\ValidationException;
use Vertex\Core\View;
use Vertex\Services\Auth;

abstract class Controller
{
    /** @param array<string,mixed> $data */
    protected function page(string $view, array $data = [], string $layout = 'layouts/app'): Response
    {
        return Response::html(View::render($view, $data + ['errors' => Session::pullFlash('errors', []), 'notice' => Session::pullFlash('notice')], $layout));
    }

    /** @param list<string> $errors @param array<string,string> $old */
    protected function back(string $to, array $errors = [], array $old = []): Response
    {
        if ($errors !== []) {
            Session::flash('errors', $errors);
        }
        if ($old !== []) {
            Session::put('_old', $old);
        }

        return Response::redirect($to);
    }

    protected function ok(string $to, string $message): Response
    {
        Session::flash('notice', $message);

        return Response::redirect($to);
    }

    /**
     * Runs a mutation; a ValidationException becomes flashed form errors and a redirect (inputs kept, never passwords).
     *
     * @param callable():Response $action
     * @param array<string,string> $old
     */
    protected function guarded(callable $action, string $errorRedirect, array $old = []): Response
    {
        try {
            return $action();
        } catch (ValidationException $e) {
            return $this->back($errorRedirect, $e->errors, $old);
        }
    }

    /**
     * Sensitive actions require the actor to re-type their own password (step-up). Enforced on the server;
     * the browser confirm() dialog is only a convenience.
     */
    protected function requireStepUp(Request $request): void
    {
        $pw = $request->body['confirm_password'] ?? '';
        if (!is_string($pw) || $pw === '' || !Auth::verifyPassword((int) Auth::id(), $pw)) {
            throw new ValidationException(['Your password was missing or incorrect. The action was not performed.']);
        }
    }

    /** @return int the signed-in user's id (routes using this are behind the `auth` middleware) */
    protected function actorId(): int
    {
        return (int) Auth::id();
    }
}
