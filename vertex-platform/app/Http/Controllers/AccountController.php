<?php

declare(strict_types=1);

namespace Vertex\Http\Controllers;

use Vertex\Core\Request;
use Vertex\Core\Response;
use Vertex\Core\Session;
use Vertex\Services\Auth;
use Vertex\Services\Gate;
use Vertex\Services\UserService;

/** Self-service. Deliberately cannot touch roles, status, email or permissions. */
final class AccountController extends Controller
{
    /** @param array<string,string> $params */
    public function show(Request $request, array $params = []): Response
    {
        $user = Auth::user();
        $r = $this->page('pages/account', [
            'title' => 'My account',
            'user' => $user,
            'roles' => Gate::roleSlugs((int) $user['id']),
            'timezones' => \DateTimeZone::listIdentifiers(),
            'notice' => Session::pullFlash('notice'),
        ]);
        Session::forget('_old');

        return $r;
    }

    /** @param array<string,string> $params */
    public function update(Request $request, array $params = []): Response
    {
        return $this->guarded(function () use ($request): Response {
            // Explicit allow-list; crafted fields like role/roles/status/email are never read.
            UserService::updateOwnProfile($this->actorId(), [
                'name' => $request->body['name'] ?? '',
                'phone' => $request->body['phone'] ?? '',
                'timezone' => $request->body['timezone'] ?? '',
            ]);

            return $this->ok('/account', 'Profile updated.');
        }, '/account', ['name' => $request->string('name'), 'phone' => $request->string('phone'), 'timezone' => $request->string('timezone')]);
    }

    /** @param array<string,string> $params */
    public function password(Request $request, array $params = []): Response
    {
        return $this->guarded(function () use ($request): Response {
            UserService::changeOwnPassword(
                $this->actorId(),
                (string) ($request->body['current_password'] ?? ''),
                (string) ($request->body['password'] ?? ''),
                (string) ($request->body['password_confirmation'] ?? ''),
            );
            Auth::refresh();

            return $this->ok('/account', 'Password changed. Your other sessions were signed out.');
        }, '/account');
    }
}
