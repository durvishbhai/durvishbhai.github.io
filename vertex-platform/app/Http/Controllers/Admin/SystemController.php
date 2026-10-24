<?php

declare(strict_types=1);

namespace Vertex\Http\Controllers\Admin;

use Vertex\Core\Request;
use Vertex\Core\Response;
use Vertex\Http\Controllers\Controller;
use Vertex\Services\SystemStatus;

/** Read-only system health for administrators (`settings.view`). */
final class SystemController extends Controller
{
    /** @param array<string,string> $params */
    public function index(Request $request, array $params = []): Response
    {
        return $this->page('admin/system', ['title' => 'System status', 'rows' => SystemStatus::report()]);
    }
}
