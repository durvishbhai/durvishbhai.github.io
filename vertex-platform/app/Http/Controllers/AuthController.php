<?php

declare(strict_types=1);

namespace Vertex\Http\Controllers;

use Vertex\Core\Request;
use Vertex\Core\Response;
use Vertex\Core\Session;
use Vertex\Core\Validator;
use Vertex\Core\View;
use Vertex\Services\Auth;

final class AuthController extends Controller
{
    /** @param array<string,string> $params */
    public function showLogin(Request $request, array $params = []): Response
    {
        $html = View::render('pages/login', [
            'title' => 'Sign in',
            'errors' => Session::pullFlash('errors', []),
            'notice' => Session::pullFlash('notice'),
        ]);
        Session::forget('_old');

        return Response::html($html);
    }

    /** @param array<string,string> $params */
    public function login(Request $request, array $params = []): Response
    {
        $v = Validator::make($request->body, ['email' => 'required|email', 'password' => 'required|max:200']);
        if ($v->fails()) {
            return $this->back('/login', ['Enter your email address and password.'], ['email' => $request->string('email')]);
        }

        $result = Auth::attempt((string) $v->validated()['email'], (string) $request->body['password'], $request);
        $old = ['email' => $request->string('email')];

        return match ($result) {
            Auth::OK => Response::redirect($this->intended()),
            Auth::TWO_FACTOR => Response::redirect('/login/two-factor'),
            Auth::LOCKED => $this->back('/login', ['Too many failed attempts. Please wait a few minutes and try again.'], $old),
            // Only reached after the correct password was supplied, so this does not reveal accounts to guessers.
            Auth::PENDING => $this->back('/login', ['Your account is not active yet. Confirm your email address using the link we sent you, or ask an administrator.'], $old),
            // Same message for wrong password and unknown email: no account enumeration.
            default => $this->back('/login', ['Those credentials did not work.'], $old),
        };
    }

    /** @param array<string,string> $params */
    public function showTwoFactor(Request $request, array $params = []): Response
    {
        if (Auth::pendingTwoFactorUser() === null) {
            return Response::redirect('/login');
        }
        $html = View::render('pages/login-two-factor', ['title' => 'Verification code', 'errors' => Session::pullFlash('errors', []), 'notice' => null]);

        return Response::html($html);
    }

    /** @param array<string,string> $params */
    public function twoFactor(Request $request, array $params = []): Response
    {
        $result = Auth::completeTwoFactor(substr((string) ($request->body['code'] ?? ''), 0, 40), $request);

        return match ($result) {
            Auth::OK => Response::redirect($this->intended()),
            Auth::EXPIRED => $this->back('/login', ['That sign-in took too long. Enter your password again.']),
            Auth::LOCKED => $this->back('/login/two-factor', ['Too many wrong codes. Wait ten minutes and try again.']),
            default => $this->back('/login/two-factor', ['That code is not correct.']),
        };
    }

    /** @param array<string,string> $params */
    public function logout(Request $request, array $params = []): Response
    {
        Auth::logout();

        return Response::redirect('/login');
    }

    private function intended(): string
    {
        $to = Session::pull('intended', '/dashboard');

        // Only same-site absolute paths; blocks open redirects such as //evil.com or https://evil.com.
        return is_string($to) && preg_match('#^/(?!/)[A-Za-z0-9/_\-.]*$#', $to) ? $to : '/dashboard';
    }
}
