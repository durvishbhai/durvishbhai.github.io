<?php

declare(strict_types=1);

namespace Vertex\Http\Controllers;

use Vertex\Core\HttpException;
use Vertex\Core\Request;
use Vertex\Core\Response;
use Vertex\Core\Session;
use Vertex\Core\ValidationException;
use Vertex\Services\Auth;
use Vertex\Services\GoogleAuth;
use Vertex\Services\RateLimiter;
use Vertex\Services\UserService;

/** "Continue with Google" for clients: start, callback, and the one extra step (compulsory phone number) for a first-time visitor. */
final class GoogleAuthController extends Controller
{
    private const SIGNUP = 'google_signup';

    /** @param array<string,string> $params */
    public function start(Request $request, array $params = []): Response
    {
        if (!GoogleAuth::enabled()) {
            throw new HttpException(404);
        }
        if (!RateLimiter::hit('google_ip', $request->ip())) {
            throw new HttpException(429);
        }

        return Response::redirect(GoogleAuth::authorizeUrl(), 302);
    }

    /** @param array<string,string> $params */
    public function callback(Request $request, array $params = []): Response
    {
        if (!GoogleAuth::enabled()) {
            throw new HttpException(404);
        }
        if (!RateLimiter::hit('google_ip', $request->ip())) {
            throw new HttpException(429);
        }
        if (isset($request->query['error'])) {
            Session::forget('google_oauth');

            return $this->back('/login', ['Google sign-in was cancelled. You can try again or sign in with your password.']);
        }
        try {
            $g = GoogleAuth::identify((string) ($request->query['code'] ?? ''), (string) ($request->query['state'] ?? ''));
            $r = UserService::googleSignIn($g, $request->ip());
        } catch (\RuntimeException $e) {
            return $this->back('/login', [$e->getMessage()]);
        }
        if ($r['action'] === 'complete') {
            Session::put(self::SIGNUP, $g + ['exp' => time() + 900]);

            return Response::redirect('/auth/google/complete');
        }
        if ($r['action'] === 'pending') {
            return $this->ok('/login', 'Your account is waiting for our team to approve it. You will be able to sign in as soon as it is approved.');
        }

        return $this->finish($r['user'], $request);
    }

    /** @param array<string,string> $params */
    public function showComplete(Request $request, array $params = []): Response
    {
        $g = $this->pending();
        if ($g === null) {
            return Response::redirect('/login');
        }
        $r = $this->page('pages/google-complete', ['title' => 'Finish signing up', 'g' => $g, 'approval' => \Vertex\Services\Registration::configured() === 'approval'], 'layouts/public');
        Session::forget('_old');

        return $r;
    }

    /** @param array<string,string> $params */
    public function complete(Request $request, array $params = []): Response
    {
        $g = $this->pending();
        if ($g === null) {
            return $this->back('/login', ['That sign-up took too long. Please start again.']);
        }

        return $this->guarded(function () use ($request, $g): Response {
            try {
                $made = UserService::completeGoogleSignUp($g, $request->body['phone'] ?? '', $request->body['name'] ?? '', $request->ip());
            } catch (\RuntimeException $e) {
                Session::forget(self::SIGNUP);
                throw new ValidationException([$e->getMessage()]);
            }
            Session::forget(self::SIGNUP);
            if ($made['status'] === 'pending') {
                return $this->ok('/login', 'Thank you. Your account was created and is waiting for our team to approve it. You will be able to sign in as soon as it is approved.');
            }
            $user = (new \Vertex\Repositories\UserRepository())->findById($made['user_id']) ?? throw new HttpException(500);

            return $this->finish($user, $request);
        }, '/auth/google/complete', ['name' => $request->string('name'), 'phone' => $request->string('phone')]);
    }

    /** @param array<string,mixed> $user */
    private function finish(array $user, Request $request): Response
    {
        return match (Auth::loginVerified($user, $request, 'Google')) {
            Auth::OK => Response::redirect($this->intended()),
            Auth::TWO_FACTOR => Response::redirect('/login/two-factor'),
            default => $this->back('/login', ['This account is not active. Please contact us.']),
        };
    }

    /** @return array{sub:string,email:string,name:string}|null */
    private function pending(): ?array
    {
        $g = Session::get(self::SIGNUP);
        if (!is_array($g) || (int) ($g['exp'] ?? 0) < time() || !isset($g['sub'], $g['email'], $g['name'])) {
            Session::forget(self::SIGNUP);

            return null;
        }

        return ['sub' => (string) $g['sub'], 'email' => (string) $g['email'], 'name' => (string) $g['name']];
    }

    private function intended(): string
    {
        $to = Session::pull('intended', '/dashboard');

        return is_string($to) && preg_match('#^/(?!/)[A-Za-z0-9/_\-.]*$#', $to) ? $to : '/dashboard';
    }
}
