<?php

declare(strict_types=1);

namespace Vertex\Http\Controllers;

use Vertex\Core\Config;
use Vertex\Core\HttpException;
use Vertex\Core\Request;
use Vertex\Core\Response;
use Vertex\Core\Session;
use Vertex\Services\Registration;
use Vertex\Services\TokenService;
use Vertex\Services\UserService;

/** Public client registration and email verification. Disabled unless REGISTRATION_ENABLED=true AND email works. */
final class RegistrationController extends Controller
{
    private function enabled(): bool
    {
        return Registration::enabled();
    }

    /** @param array<string,string> $params */
    public function showRegister(Request $request, array $params = []): Response
    {
        if (!$this->enabled()) {
            throw new HttpException(404);
        }
        $r = $this->page('pages/register', ['title' => 'Create account', 'approval' => Registration::mode() === 'approval', 'mode' => Registration::mode()], 'layouts/public');
        Session::forget('_old');

        return $r;
    }

    /** @param array<string,string> $params */
    public function register(Request $request, array $params = []): Response
    {
        if (!$this->enabled()) {
            throw new HttpException(404);
        }

        // Only these fields are read: a submitted "role"/"status" has no effect.
        return $this->guarded(function () use ($request): Response {
            UserService::register([
                'name' => $request->body['name'] ?? '',
                'email' => $request->body['email'] ?? '',
                'phone' => $request->body['phone'] ?? '',
                'password' => $request->body['password'] ?? '',
                'password_confirmation' => $request->body['password_confirmation'] ?? '',
            ], $request->ip());

            // Identical response whether or not the email already had an account.
            return $this->ok('/login', match (Registration::mode()) {
                'approval' => 'Thank you. Your account was created and is waiting for our team to approve it. You will be able to sign in as soon as it is approved.',
                'instant' => 'Your account is ready. Sign in with your email and password. (If you already had an account with this email, use Forgot password.)',
                default => 'Check your email: we sent a link to confirm your address. You can sign in once it is confirmed.',
            });
        }, '/register', ['name' => $request->string('name'), 'email' => $request->string('email'), 'phone' => $request->string('phone')]);
    }

    /** @param array<string,string> $params */
    public function showVerify(Request $request, array $params = []): Response
    {
        $token = $params['token'] ?? '';
        // GET never consumes the token (mail scanners prefetch links); a button POSTs it.
        $valid = TokenService::peek($token, [TokenService::VERIFY]) !== null;

        return $this->page('pages/verify-email', ['title' => 'Confirm email', 'token' => $token, 'valid' => $valid], 'layouts/public');
    }

    /** @param array<string,string> $params */
    public function verify(Request $request, array $params = []): Response
    {
        if (UserService::verifyEmail($params['token'] ?? '', $request->ip())) {
            return $this->ok('/login', 'Email confirmed. You can now sign in.');
        }

        return $this->back('/login', ['That confirmation link is invalid or has expired. Request a new one below.']);
    }

    /** @param array<string,string> $params */
    public function showResend(Request $request, array $params = []): Response
    {
        if (!\Vertex\Services\Mailer::enabled()) {
            throw new HttpException(404);
        }

        return $this->page('pages/verify-resend', ['title' => 'Resend confirmation', 'notice' => Session::pullFlash('notice')], 'layouts/public');
    }

    /** @param array<string,string> $params */
    public function resend(Request $request, array $params = []): Response
    {
        UserService::resendVerification($request->string('email'), $request->ip());

        return $this->ok('/verify-email', 'If that address has an unconfirmed account, a new link is on its way.');
    }
}
