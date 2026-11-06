<?php

declare(strict_types=1);

namespace Vertex\Http\Controllers;

use Vertex\Core\HttpException;
use Vertex\Core\Request;
use Vertex\Core\Response;
use Vertex\Core\Session;
use Vertex\Services\Gate;
use Vertex\Services\ScopeRequestService;

/** Client scope-change requests: staff queue and the client's own request screens. */
final class ScopeRequestController extends Controller
{
    /** @param array<string,string> $params */
    public function index(Request $request, array $params = []): Response
    {
        $f = ['status' => $request->string('status')];
        $res = ScopeRequestService::paginate($this->actorId(), $f, $request->page(), 20);

        return $this->page('scope/index', ['title' => 'Scope requests', 'rows' => $res['rows'], 'total' => $res['total'], 'page' => $request->page(), 'perPage' => 20, 'filters' => $f, 'qs' => array_filter($f)]);
    }

    /** @param array<string,string> $params */
    public function show(Request $request, array $params = []): Response
    {
        $s = ScopeRequestService::requireStaff($this->actorId(), $params['request'] ?? '');
        $r = $this->page('scope/show', ['title' => $s['reference'], 's' => $s, 'canConvert' => Gate::can('revisions.classify', $this->actorId())]);
        Session::forget('_old');

        return $r;
    }

    /** @param array<string,string> $params */
    public function action(Request $request, array $params = []): Response
    {
        $uid = $this->actorId();
        $s = ScopeRequestService::requireStaff($uid, $params['request'] ?? '');
        $to = '/scope-requests/' . $s['public_id'];
        $action = $request->string('action');

        return $this->guarded(function () use ($request, $uid, $s, $to, $action): Response {
            switch ($action) {
                case 'assess':
                    ScopeRequestService::assess($uid, $s, $request->string('amount'), $request->string('days'), $request->string('note'));

                    return $this->ok($to, 'Assessment saved. Nothing has been sent to the client yet.');
                case 'convert':
                    $cr = ScopeRequestService::convert($uid, $s, $request->string('scope'), $request->string('reason'));

                    return $this->ok('/changes/' . $cr, 'A change proposal was created as a draft. Review it, then send it to the client.');
                case 'decline':
                    $this->requireStepUp($request);
                    ScopeRequestService::decline($uid, $s, $request->string('reason'));

                    return $this->ok($to, 'The request was declined and the client was told why.');
            }
            throw new HttpException(404);
        }, $to);
    }

    // ---------------------------------------------------------------------------------------------- client

    /** @param array<string,string> $params */
    public function myIndex(Request $request, array $params = []): Response
    {
        return $this->page('scope/my-index', ['title' => 'My scope requests', 'rows' => ScopeRequestService::forClient($this->actorId())]);
    }

    /** @param array<string,string> $params */
    public function myShow(Request $request, array $params = []): Response
    {
        $s = ScopeRequestService::requireOwn($this->actorId(), $params['request'] ?? '');

        return $this->page('scope/my-show', ['title' => $s['reference'], 's' => $s]);
    }

    /** @param array<string,string> $params */
    public function create(Request $request, array $params = []): Response
    {
        $project = $request->attributes['project'];
        Gate::authorize('scope.request', $this->actorId());
        $r = $this->page('scope/new', ['title' => 'Request a change', 'project' => $project, 'token' => bin2hex(random_bytes(16))]);
        Session::forget('_old');

        return $r;
    }

    /** @param array<string,string> $params */
    public function store(Request $request, array $params = []): Response
    {
        $project = $request->attributes['project'];
        $in = ['description' => $request->string('description'), 'wanted_by' => $request->string('wanted_by')];

        return $this->guarded(function () use ($request, $project, $in): Response {
            $r = ScopeRequestService::submit($this->actorId(), $project, $in['description'], $in['wanted_by'], $request->string('token'));

            return $this->ok('/my/scope-requests/' . $r['public_id'], 'Thank you. We will look at the cost and the effect on your delivery date and come back to you.');
        }, '/projects/' . $project['public_id'] . '/scope-request', $in);
    }

    /** @param array<string,string> $params */
    public function withdraw(Request $request, array $params = []): Response
    {
        $s = ScopeRequestService::requireOwn($this->actorId(), $params['request'] ?? '');

        return $this->guarded(function () use ($s): Response {
            ScopeRequestService::withdraw($this->actorId(), $s);

            return $this->ok('/my/scope-requests', 'Withdrawn.');
        }, '/my/scope-requests/' . $s['public_id']);
    }
}
