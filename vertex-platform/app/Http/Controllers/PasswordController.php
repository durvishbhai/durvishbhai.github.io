<?php

declare(strict_types=1);

namespace Vertex\Http\Controllers;

use Vertex\Core\HttpException;
use Vertex\Core\Request;
use Vertex\Core\Response;
use Vertex\Core\Session;
use Vertex\Services\Mailer;
use Vertex\Services\TokenService;
use Vertex\Services\UserService;

/** Forgot-password, reset-by-link and invitation acceptance (set first password). */
final class PasswordController extends Controller
{
    /** @param array<string,string> $params */
    public function showForgot(Request $request, array $params = []): Response
    {
        return $this->page('pages/forgot-password', [
            'title' => 'Reset password',
            'mailEnabled' => Mailer::enabled(),
            'notice' => Session::pullFlash('notice'),
        ], 'layouts/public');
    }

    /** @param array<string,string> $params */
    public function forgot(Request $request, array $params = []): Response
    {
        if (!Mailer::enabled()) {
            throw new HttpException(404);
        }
        UserService::requestPasswordReset($request->string('email'), $request->ip());

        // One neutral message regardless of whether the account exists.
        return $this->ok('/forgot-password', 'If that email belongs to an active account, we have sent a reset link. It expires in 1 hour.');
    }

    /** @param array<string,string> $params */
    public function showReset(Request $request, array $params = []): Response
    {
        return $this->form($params['token'] ?? '', false);
    }

    /** @param array<string,string> $params */
    public function showInvite(Request $request, array $params = []): Response
    {
        return $this->form($params['token'] ?? '', true);
    }

    /** @param array<string,string> $params */
    public function submitReset(Request $request, array $params = []): Response
    {
        return $this->submit($request, $params['token'] ?? '', '/reset-password/');
    }

    /** @param array<string,string> $params */
    public function submitInvite(Request $request, array $params = []): Response
    {
        return $this->submit($request, $params['token'] ?? '', '/invite/');
    }

    private function form(string $token, bool $invite): Response
    {
        $valid = TokenService::peek($token, [$invite ? TokenService::INVITE : TokenService::RESET]) !== null;

        return $this->page('pages/set-password', ['title' => $invite ? 'Set your password' : 'Choose a new password', 'invite' => $invite, 'valid' => $valid, 'token' => $token], 'layouts/public');
    }

    private function submit(Request $request, string $token, string $base): Response
    {
        return $this->guarded(function () use ($request, $token): Response {
            $ok = UserService::completePasswordToken($token, (string) ($request->body['password'] ?? ''), (string) ($request->body['password_confirmation'] ?? ''), $request->ip());
            if (!$ok) {
                return $this->back('/forgot-password', ['That link is invalid or has expired. Request a new one.']);
            }

            return $this->ok('/login', 'Password saved. You can now sign in.');
        }, $base . $token);
    }
}
