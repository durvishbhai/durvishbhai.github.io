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
}
