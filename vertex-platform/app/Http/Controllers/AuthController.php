<?php

declare(strict_types=1);

namespace Vertex\Http\Controllers;

use Vertex\Core\Request;
use Vertex\Core\Response;
use Vertex\Core\Session;
use Vertex\Core\Validator;
use Vertex\Core\View;
use Vertex\Services\Auth;

final class AuthController
{
    /** @param array<string,string> $params */
    public function showLogin(Request $request, array $params = []): Response
    {
        $html = View::render('pages/login', [
            'title' => 'Sign in',
            'errors' => Session::pullFlash('errors', []),
        ]);
        Session::forget('_old');

        return Response::html($html);
    }

    /** @param array<string,string> $params */
    public function login(Request $request, array $params = []): Response
    {
        $v = Validator::make($request->body, ['email' => 'required|email', 'password' => 'required|max:200']);
        if ($v->fails()) {
            return $this->back($request->string('email'), ['Enter your email address and password.']);
        }

        $result = Auth::attempt((string) $v->validated()['email'], (string) $request->body['password'], $request);

        return match ($result) {
            Auth::OK => Response::redirect($this->intended()),
            Auth::LOCKED => $this->back($request->string('email'), ['Too many failed attempts. Please wait a few minutes and try again.']),
            // Same message for wrong password, unknown email and suspended account: no account enumeration.
            default => $this->back($request->string('email'), ['Those credentials did not work.']),
        };
    }

    /** @param array<string,string> $params */
    public function logout(Request $request, array $params = []): Response
    {
        Auth::logout();

        return Response::redirect('/login');
    }

    /** @param list<string> $errors */
    private function back(string $email, array $errors): Response
    {
        Session::flash('errors', $errors);
        Session::put('_old', ['email' => $email]);

        return Response::redirect('/login');
    }

    private function intended(): string
    {
        $to = Session::pull('intended', '/dashboard');

        // Only same-site absolute paths; blocks open redirects such as //evil.com or https://evil.com.
        return is_string($to) && preg_match('#^/(?!/)[A-Za-z0-9/_\-.]*$#', $to) ? $to : '/dashboard';
    }
}
