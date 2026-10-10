<?php

declare(strict_types=1);

namespace Vertex\Http\Controllers;

use Vertex\Core\Request;
use Vertex\Core\Response;
use Vertex\Core\View;

final class HomeController
{
    /** @param array<string,string> $params */
    public function index(Request $request, array $params = []): Response
    {
        return Response::html(View::render('pages/home', ['title' => 'Vertex Integrated Solutions']));
    }
}
