<?php

declare(strict_types=1);

namespace Vertex\Http\Controllers\Admin;

use Vertex\Core\HttpException;
use Vertex\Core\Request;
use Vertex\Core\Response;
use Vertex\Core\Session;
use Vertex\Http\Controllers\Controller;
use Vertex\Services\Gate;
use Vertex\Services\RoleService;

final class RoleController extends Controller
{
    /** @param array<string,string> $params */
    public function index(Request $request, array $params = []): Response
    {
        return $this->page('admin/roles/index', ['title' => 'Roles & permissions', 'roles' => RoleService::all(), 'actorLevel' => Gate::level($this->actorId()), 'notice' => Session::pullFlash('notice')]);
    }

    /** @param array<string,string> $params */
    public function show(Request $request, array $params = []): Response
    {
        $role = RoleService::find($params['role'] ?? '') ?? throw new HttpException(404);
        $actorId = $this->actorId();

        return $this->page('admin/roles/show', [
            'title' => $role['name'],
            'role' => $role,
            'granted' => array_flip(RoleService::grantedKeys((int) $role['id'])),
            'catalogue' => RoleService::catalogue(),
            'canEdit' => RoleService::canEdit($actorId, $role),
            'canSensitive' => Gate::can('roles.manage_sensitive', $actorId),
            'actorPermissions' => Gate::permissionsFor($actorId),
            'notice' => Session::pullFlash('notice'),
        ]);
    }

    /** @param array<string,string> $params */
    public function update(Request $request, array $params = []): Response
    {
        $slug = (string) ($params['role'] ?? '');
        $to = '/admin/roles/' . $slug;

        return $this->guarded(function () use ($request, $slug, $to): Response {
            $this->requireStepUp($request);
            $submitted = $request->body['permissions'] ?? [];
            $submitted = is_array($submitted) ? array_values(array_filter($submitted, 'is_string')) : [];
            $desired = RoleService::preserveUneditable($this->actorId(), $slug, $submitted);
            RoleService::setPermissions($this->actorId(), $slug, $desired);

            return $this->ok($to, 'Permissions saved.');
        }, $to);
    }

    /** @param array<string,string> $params */
    public function create(Request $request, array $params = []): Response
    {
        $actor = $this->actorId();
        $r = $this->page('admin/roles/new', [
            'title' => 'New role',
            'maxLevel' => RoleService::maxCustomLevel($actor),
            'copyable' => array_filter(RoleService::all(), static fn (array $x): bool => (int) $x['level'] < Gate::level($actor)),
        ]);
        Session::forget('_old');

        return $r;
    }

    /** @param array<string,string> $params */
    public function store(Request $request, array $params = []): Response
    {
        $old = ['name' => $request->string('name'), 'description' => $request->string('description'), 'level' => $request->string('level'), 'copy_from' => $request->string('copy_from')];

        return $this->guarded(function () use ($request): Response {
            $this->requireStepUp($request);
            $slug = RoleService::create($this->actorId(), $request->string('name'), $request->string('description'), (int) $request->string('level'), $request->string('copy_from') ?: null, []);

            return $this->ok('/admin/roles/' . $slug, 'Role created. Choose its permissions below.');
        }, '/admin/roles/new', $old);
    }

    /** @param array<string,string> $params */
    public function destroy(Request $request, array $params = []): Response
    {
        $slug = (string) ($params['role'] ?? '');

        return $this->guarded(function () use ($request, $slug): Response {
            $this->requireStepUp($request);
            RoleService::delete($this->actorId(), $slug);

            return $this->ok('/admin/roles', 'Role deleted.');
        }, '/admin/roles/' . $slug);
    }
}
