<?php

declare(strict_types=1);

namespace Vertex\Http\Controllers\Admin;

use Vertex\Core\Config;
use Vertex\Core\HttpException;
use Vertex\Core\Request;
use Vertex\Core\Response;
use Vertex\Core\Session;
use Vertex\Http\Controllers\Controller;
use Vertex\Repositories\UserRepository;
use Vertex\Services\Gate;
use Vertex\Services\Mailer;
use Vertex\Services\UserService;

/**
 * Admin user management. Routes carry `can:` middleware for the coarse permission; UserService re-checks the
 * permission AND the hierarchy rules (target level < actor level, no self-modification, assignable roles only).
 */
final class UserController extends Controller
{
    private const PER_PAGE = 20;

    /** @param array<string,string> $params */
    public function index(Request $request, array $params = []): Response
    {
        $filters = [
            'q' => mb_substr($request->string('q'), 0, 100),
            'role' => preg_match('/^[a-z_]{2,40}$/', $request->string('role')) ? $request->string('role') : '',
            'status' => in_array($request->string('status'), ['pending', 'active', 'deactivated', 'archived'], true) ? $request->string('status') : '',
        ];
        $page = $request->page();
        $result = (new UserRepository())->paginate($filters, $page, self::PER_PAGE);
        $actorLevel = Gate::level($this->actorId());

        return $this->page('admin/users/index', [
            'title' => 'Users',
            'rows' => $result['rows'],
            'total' => $result['total'],
            'page' => $page,
            'perPage' => self::PER_PAGE,
            'filters' => $filters,
            'roles' => \Vertex\Services\RoleService::definitions(),
            'actorLevel' => $actorLevel,
            'notice' => Session::pullFlash('notice'),
        ]);
    }

    /** @param array<string,string> $params */
    public function create(Request $request, array $params = []): Response
    {
        $r = $this->page('admin/users/create', [
            'title' => 'Create user',
            'assignable' => $this->assignableRoles(),
            'timezones' => \DateTimeZone::listIdentifiers(),
            'mailEnabled' => Mailer::enabled(),
        ]);
        Session::forget('_old');

        return $r;
    }

    /** @param array<string,string> $params */
    public function store(Request $request, array $params = []): Response
    {
        return $this->guarded(function () use ($request): Response {
            $res = UserService::adminCreate($this->actorId(), [
                'name' => $request->body['name'] ?? '',
                'email' => $request->body['email'] ?? '',
                'phone' => $request->body['phone'] ?? '',
                'roles' => $request->body['roles'] ?? [],
            ]);
            $user = (new UserRepository())->findById($res['user_id']);
            $msg = $res['invite_sent']
                ? 'User created. An invitation email was sent so they can set their own password.'
                : 'User created, but no invitation email could be sent (email is not configured). Use the console command user:set-password to let them in.';

            return $this->ok('/admin/users/' . $user['public_id'], $msg);
        }, '/admin/users/create', ['name' => $request->string('name'), 'email' => $request->string('email'), 'phone' => $request->string('phone')]);
    }

    /** @param array<string,string> $params */
    public function show(Request $request, array $params = []): Response
    {
        $repo = new UserRepository();
        $target = $repo->findByPublicId($params['user'] ?? '') ?? throw new HttpException(404);
        $actorId = $this->actorId();
        $tid = (int) $target['id'];
        $self = $tid === $actorId;
        $manageable = !$self && Gate::level($tid) < Gate::level($actorId);

        $r = $this->page('admin/users/show', [
            'title' => $target['name'],
            'target' => $target,
            'targetRoles' => $repo->rolesOf($tid),
            'assignable' => $this->assignableRoles(),
            'timezones' => \DateTimeZone::listIdentifiers(),
            'self' => $self,
            'manageable' => $manageable,
            'mailEnabled' => Mailer::enabled(),
            'notice' => Session::pullFlash('notice'),
        ]);
        Session::forget('_old');

        return $r;
    }

    /** @param array<string,string> $params */
    public function update(Request $request, array $params = []): Response
    {
        $target = $this->target($params);

        return $this->guarded(function () use ($request, $target): Response {
            UserService::adminUpdate($this->actorId(), (int) $target['id'], [
                'name' => $request->body['name'] ?? '',
                'email' => $request->body['email'] ?? '',
                'phone' => $request->body['phone'] ?? '',
                'timezone' => $request->body['timezone'] ?? '',
            ]);

            return $this->ok($this->url($target), 'User updated.');
        }, $this->url($target));
    }

    /** @param array<string,string> $params */
    public function roles(Request $request, array $params = []): Response
    {
        $target = $this->target($params);

        return $this->guarded(function () use ($request, $target): Response {
            $this->requireStepUp($request);
            $roles = $request->body['roles'] ?? [];
            UserService::setRoles($this->actorId(), (int) $target['id'], is_array($roles) ? array_values($roles) : []);

            return $this->ok($this->url($target), 'Roles updated.');
        }, $this->url($target));
    }

    /** @param array<string,string> $params */
    public function deactivate(Request $request, array $params = []): Response
    {
        $target = $this->target($params);

        return $this->guarded(function () use ($request, $target): Response {
            $this->requireStepUp($request);
            UserService::deactivate($this->actorId(), (int) $target['id'], $request->string('reason'));

            return $this->ok($this->url($target), 'Account deactivated. Access was revoked immediately.');
        }, $this->url($target));
    }

    /** @param array<string,string> $params */
    public function reactivate(Request $request, array $params = []): Response
    {
        $target = $this->target($params);

        return $this->guarded(function () use ($request, $target): Response {
            $this->requireStepUp($request);
            UserService::reactivate($this->actorId(), (int) $target['id']);

            return $this->ok($this->url($target), 'Account reactivated.');
        }, $this->url($target));
    }

    /** @param array<string,string> $params */
    public function archive(Request $request, array $params = []): Response
    {
        $target = $this->target($params);

        return $this->guarded(function () use ($request, $target): Response {
            $this->requireStepUp($request);
            UserService::archive($this->actorId(), (int) $target['id']);

            return $this->ok($this->url($target), 'Account archived.');
        }, $this->url($target));
    }

    /** @param array<string,string> $params */
    public function reset2fa(Request $request, array $params = []): Response
    {
        $target = $this->target($params);

        return $this->guarded(function () use ($request, $target): Response {
            $this->requireStepUp($request);
            \Vertex\Services\TwoFactorService::adminReset($this->actorId(), (int) $target['id']);

            return $this->ok($this->url($target), 'Two-factor login was reset. The user can sign in with the password and set it up again.');
        }, $this->url($target));
    }

    /** @param array<string,string> $params */
    public function sendReset(Request $request, array $params = []): Response
    {
        $target = $this->target($params);

        return $this->guarded(function () use ($target): Response {
            $sent = UserService::adminSendReset($this->actorId(), (int) $target['id']);

            return $this->ok($this->url($target), $sent ? 'Password reset email sent.' : 'No email was sent (the account must be active and email must be configured).');
        }, $this->url($target));
    }

    /** @param array<string,string> $params */
    public function resendInvite(Request $request, array $params = []): Response
    {
        $target = $this->target($params);

        return $this->guarded(function () use ($target): Response {
            $sent = UserService::adminResendInvite($this->actorId(), (int) $target['id']);

            return $this->ok($this->url($target), $sent ? 'Invitation re-sent.' : 'No email was sent (email is not configured).');
        }, $this->url($target));
    }

    /** @param array<string,string> $params @return array<string,mixed> */
    private function target(array $params): array
    {
        return (new UserRepository())->findByPublicId($params['user'] ?? '') ?? throw new HttpException(404);
    }

    /** @param array<string,mixed> $target */
    private function url(array $target): string
    {
        return '/admin/users/' . $target['public_id'];
    }

    /** @return array<string,string> slug => name of roles the signed-in admin may assign */
    private function assignableRoles(): array
    {
        $out = [];
        foreach (UserService::assignableRoleSlugs($this->actorId()) as $slug) {
            $out[$slug] = \Vertex\Services\RoleService::definitions()[$slug]['name'];
        }

        return $out;
    }
}
