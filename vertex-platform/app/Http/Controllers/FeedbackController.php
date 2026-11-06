<?php

declare(strict_types=1);

namespace Vertex\Http\Controllers;

use Vertex\Core\Request;
use Vertex\Core\Response;
use Vertex\Services\FeedbackPointService;
use Vertex\Services\RevisionAccess;

final class FeedbackController extends Controller
{
    /** @param array<string,string> $params */
    public function add(Request $request, array $params = []): Response
    {
        $r = RevisionAccess::requireView($this->actorId(), (string) ($params['revision'] ?? ''));
        $to = '/revisions/' . $r['public_id'];

        return $this->guarded(function () use ($request, $r, $to): Response {
            FeedbackPointService::add($this->actorId(), $r, $request->string('at'), $request->string('note'));

            return $this->ok($to, 'Feedback point added.');
        }, $to);
    }

    /** @param array<string,string> $params */
    public function resolve(Request $request, array $params = []): Response
    {
        $r = RevisionAccess::requireView($this->actorId(), (string) ($params['revision'] ?? ''));
        $to = '/revisions/' . $r['public_id'];

        return $this->guarded(function () use ($request, $r, $params, $to): Response {
            FeedbackPointService::resolve($this->actorId(), $r, (int) ($params['point'] ?? 0), $request->string('done') === '1');

            return \Vertex\Core\Response::redirect($to);
        }, $to);
    }
}
