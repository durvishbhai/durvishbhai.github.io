<?php

declare(strict_types=1);

namespace Vertex\Http\Controllers;

use Vertex\Core\Request;
use Vertex\Core\Response;
use Vertex\Services\ApprovalCenter;

final class ApprovalController extends Controller
{
    /** @param array<string,string> $params */
    public function index(Request $request, array $params = []): Response
    {
        $id = $this->actorId();

        return $this->page('pages/approvals', ['title' => 'Approval centre', 'pending' => ApprovalCenter::pending($id), 'history' => ApprovalCenter::history($id)]);
    }
}
