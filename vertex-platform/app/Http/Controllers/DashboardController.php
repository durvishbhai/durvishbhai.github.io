<?php

declare(strict_types=1);

namespace Vertex\Http\Controllers;

use Vertex\Core\Request;
use Vertex\Core\Response;
use Vertex\Core\View;
use Vertex\Services\Auth;
use Vertex\Services\Gate;

/**
 * Phase 0 dashboard: shows the signed-in identity, roles and effective permissions so the RBAC foundation can be
 * verified. Role-specific dashboards (projects, billing, ...) arrive with their modules.
 */
final class DashboardController
{
    /** @param array<string,string> $params */
    public function index(Request $request, array $params = []): Response
    {
        $user = Auth::user();
        $id = (int) $user['id'];
        $perms = array_keys(Gate::permissionsFor($id));
        sort($perms);

        return Response::html(View::render('pages/dashboard', [
            'title' => 'Dashboard',
            'user' => $user,
            'roles' => Gate::roleSlugs($id),
            'permissions' => $perms,
        ], 'layouts/app'));
    }
}
