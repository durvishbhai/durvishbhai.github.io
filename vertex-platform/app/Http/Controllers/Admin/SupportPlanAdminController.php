<?php

declare(strict_types=1);

namespace Vertex\Http\Controllers\Admin;

use Vertex\Core\Request;
use Vertex\Core\Response;
use Vertex\Http\Controllers\Controller;
use Vertex\Services\SupportPlans;

/** The team's view of bug support: which finished projects are still in free support, which have a subscription, which pay per bug, which still have to choose. */
final class SupportPlanAdminController extends Controller
{
    /** @param array<string,string> $params */
    public function index(Request $request, array $params = []): Response
    {
        $filter = $request->string('show');
        $filter = in_array($filter, ['free', 'subscription', 'per_bug', 'choice', 'none'], true) ? $filter : 'all';

        return $this->page('support-plans/index', ['title' => 'Bug-support plans', 'rows' => SupportPlans::report($filter), 'filter' => $filter]);
    }
}
