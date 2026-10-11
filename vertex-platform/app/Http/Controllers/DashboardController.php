<?php

declare(strict_types=1);

namespace Vertex\Http\Controllers;

use Vertex\Core\Request;
use Vertex\Core\Response;
use Vertex\Services\Auth;
use Vertex\Services\Gate;

final class DashboardController extends Controller
{
    /** @param array<string,string> $params */
    public function index(Request $request, array $params = []): Response
    {
        $user = Auth::user();
        $id = (int) $user['id'];
        $perms = array_keys(Gate::permissionsFor($id));
        sort($perms);

        return $this->page('pages/dashboard', [
            'title' => 'Dashboard',
            'user' => $user,
            'roles' => Gate::roleSlugs($id),
            'permissions' => $perms,
        ]);
    }
}
