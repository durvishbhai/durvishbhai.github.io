<?php

declare(strict_types=1);

namespace Vertex\Http\Controllers;

use Vertex\Core\Request;
use Vertex\Core\Response;
use Vertex\Core\Session;
use Vertex\Services\Auth;
use Vertex\Services\Gate;
use Vertex\Services\TwoFactorService;
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

    /** The signed-in person's own sign-ins and the actions on their account, newest first. @param array<string,string> $params */
    public function activity(Request $request, array $params = []): Response
    {
        $page = $request->page();
        $feed = \Vertex\Services\ActivityService::feed(['user' => $this->actorId(), 'direction' => 'both'], $page, 25);

        return $this->page('pages/my-activity', ['title' => 'My account activity', 'page' => $page, 'perPage' => 25] + $feed);
    }

    /** @param array<string,string> $params */
    public function twoFactor(Request $request, array $params = []): Response
    {
        $id = $this->actorId();
        $user = Auth::user();
        $codes = Session::pullFlash('recovery_codes');

        return $this->page('pages/two-factor', [
            'title' => 'Two-factor login',
            'enabled' => TwoFactorService::isEnabled($user),
            'required' => TwoFactorService::requiredFor($id),
            'pending' => TwoFactorService::pendingSetup($id),
            'remaining' => TwoFactorService::remainingRecoveryCodes($id),
            'codes' => is_array($codes) ? $codes : null,
            'notice' => Session::pullFlash('notice'),
        ]);
    }

    /** @param array<string,string> $params */
    public function twoFactorStart(Request $request, array $params = []): Response
    {
        return $this->guarded(function (): Response {
            TwoFactorService::begin($this->actorId());

            return Response::redirect('/account/two-factor');
        }, '/account/two-factor');
    }

    /** @param array<string,string> $params */
    public function twoFactorEnable(Request $request, array $params = []): Response
    {
        return $this->guarded(function () use ($request): Response {
            $codes = TwoFactorService::enable($this->actorId(), (string) ($request->body['password'] ?? ''), (string) ($request->body['code'] ?? ''));
            Session::flash('recovery_codes', $codes);

            return $this->ok('/account/two-factor', 'Two-factor login is on. Save your recovery codes now: they are shown only once.');
        }, '/account/two-factor');
    }

    /** @param array<string,string> $params */
    public function twoFactorDisable(Request $request, array $params = []): Response
    {
        return $this->guarded(function () use ($request): Response {
            TwoFactorService::disable($this->actorId(), (string) ($request->body['password'] ?? ''), (string) ($request->body['code'] ?? ''));

            return $this->ok('/account/two-factor', 'Two-factor login is off.');
        }, '/account/two-factor');
    }

    /** @param array<string,string> $params */
    public function twoFactorRecovery(Request $request, array $params = []): Response
    {
        return $this->guarded(function () use ($request): Response {
            $codes = TwoFactorService::regenerateRecoveryCodes($this->actorId(), (string) ($request->body['password'] ?? ''), (string) ($request->body['code'] ?? ''));
            Session::flash('recovery_codes', $codes);

            return $this->ok('/account/two-factor', 'New recovery codes created. The old ones no longer work.');
        }, '/account/two-factor');
    }
}
