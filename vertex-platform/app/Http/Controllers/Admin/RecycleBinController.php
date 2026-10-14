<?php

declare(strict_types=1);

namespace Vertex\Http\Controllers\Admin;

use Vertex\Core\Request;
use Vertex\Core\Response;
use Vertex\Core\Session;
use Vertex\Http\Controllers\Controller;
use Vertex\Services\RecycleBin;

final class RecycleBinController extends Controller
{
    /** @param array<string,string> $params */
    public function index(Request $request, array $params = []): Response
    {
        $type = $request->string('type');
        $type = isset(RecycleBin::types()[$type]) ? $type : '';

        return $this->page('admin/recycle-bin', ['title' => 'Recycle bin', 'items' => RecycleBin::items($this->actorId(), $type), 'types' => RecycleBin::types(), 'type' => $type, 'notice' => Session::pullFlash('notice')]);
    }

    /** @param array<string,string> $params */
    public function restore(Request $request, array $params = []): Response
    {
        return $this->guarded(function () use ($request): Response {
            $this->requireStepUp($request);
            RecycleBin::restore($this->actorId(), $request->string('type'), $request->string('id'), $request->string('reason'));

            return $this->ok('/admin/recycle-bin', 'Record restored.');
        }, '/admin/recycle-bin');
    }
}
