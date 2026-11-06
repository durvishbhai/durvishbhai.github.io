<?php

declare(strict_types=1);

namespace Vertex\Http\Controllers;

use Vertex\Core\Request;
use Vertex\Core\Response;
use Vertex\Services\TeamQuery;

/** Who is assigned to what (administrators). Read-only; assignment changes happen on the project page. */
final class TeamController extends Controller
{
    /** @param array<string,string> $params */
    public function index(Request $request, array $params = []): Response
    {
        $res = TeamQuery::overview($this->actorId());

        return $this->page('pages/team', ['title' => 'Team & assignments', 'people' => $res['people'], 'unstaffed' => $res['unstaffed']]);
    }
}
