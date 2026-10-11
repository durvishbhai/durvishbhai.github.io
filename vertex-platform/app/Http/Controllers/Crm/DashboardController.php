<?php

declare(strict_types=1);

namespace Vertex\Http\Controllers\Crm;

use Vertex\Core\Request;
use Vertex\Core\Response;
use Vertex\Http\Controllers\Controller;
use Vertex\Services\EnquiryQuery;

final class DashboardController extends Controller
{
    /** @param array<string,string> $params */
    public function index(Request $request, array $params = []): Response
    {
        return $this->page('crm/dashboard', ['title' => 'CRM', 'crm' => EnquiryQuery::dashboard($this->actorId())]);
    }
}
