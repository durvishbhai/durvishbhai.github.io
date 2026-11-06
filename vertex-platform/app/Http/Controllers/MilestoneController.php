<?php

declare(strict_types=1);

namespace Vertex\Http\Controllers;

use Vertex\Core\HttpException;
use Vertex\Core\Request;
use Vertex\Core\Response;
use Vertex\Core\Session;
use Vertex\Services\Gate;
use Vertex\Services\MilestoneService;
use Vertex\Services\ProjectPolicy;

/** Project milestones. The `project` middleware has already proven access to the project; the milestone must belong to it. */
final class MilestoneController extends Controller
{
    /** @param array<string,string> $params */
    public function index(Request $request, array $params = []): Response
    {
        $uid = $this->actorId();
        $project = $request->attributes['project'];
        $level = (string) ProjectPolicy::level($uid, $project);
        $list = MilestoneService::listFor($uid, $project);
        $allowed = [];
        foreach ($list as $m) {
            $allowed[$m['public_id']] = MilestoneService::allowedTransitions($uid, $project, $m);
        }
        $r = $this->page('pages/milestones', [
            'title' => 'Milestones · ' . $project['title'], 'project' => $project, 'milestones' => $list, 'allowed' => $allowed, 'tab' => 'milestones',
            'staff' => in_array($level, ['all', 'assigned'], true),
            'canCreate' => in_array($level, ['all', 'assigned'], true) && Gate::canAny(['milestones.create', 'milestones.propose'], $uid) && $project['archived_at'] === null && $project['status'] !== 'cancelled',
            'direct' => Gate::can('milestones.create', $uid),
            'canEdit' => in_array($level, ['all', 'assigned'], true) && Gate::can('milestones.edit', $uid),
        ]);
        Session::forget('_old');

        return $r;
    }

    /** @param array<string,string> $params */
    public function store(Request $request, array $params = []): Response
    {
        $project = $request->attributes['project'];
        $to = '/projects/' . $project['public_id'] . '/milestones';
        $in = $this->input($request);

        return $this->guarded(function () use ($project, $in, $to): Response {
            $m = MilestoneService::create($this->actorId(), $project, $in);

            return $this->ok($to, $m['status'] === 'proposed' ? 'Milestone proposed. It stays internal until it is approved.' : 'Milestone created.');
        }, $to, $in);
    }

    /** @param array<string,string> $params */
    public function update(Request $request, array $params = []): Response
    {
        [$project, $m, $to] = $this->load($request);
        $in = $this->input($request);

        return $this->guarded(function () use ($project, $m, $in, $to): Response {
            MilestoneService::update($this->actorId(), $project, $m, $in);

            return $this->ok($to, 'Milestone updated.');
        }, $to, $in);
    }

    /** @param array<string,string> $params */
    public function status(Request $request, array $params = []): Response
    {
        [$project, $m, $to] = $this->load($request);

        return $this->guarded(function () use ($request, $project, $m, $to): Response {
            MilestoneService::transition($this->actorId(), $project, $m, $request->string('status'), $request->string('visibility'));

            return $this->ok($to, 'Milestone updated.');
        }, $to);
    }

    /** @param array<string,string> $params */
    public function archive(Request $request, array $params = []): Response
    {
        [$project, $m, $to] = $this->load($request);

        return $this->guarded(function () use ($project, $m, $to): Response {
            MilestoneService::setArchived($this->actorId(), $project, $m);

            return $this->ok($to, 'Milestone archived.');
        }, $to);
    }

    /** @return array{0:array<string,mixed>,1:array<string,mixed>,2:string} */
    private function load(Request $request): array
    {
        $project = $request->attributes['project'];
        $m = MilestoneService::findInProject((string) ($request->attributes['params']['milestone'] ?? ''), (int) $project['id']) ?? throw new HttpException(404);

        return [$project, $m, '/projects/' . $project['public_id'] . '/milestones'];
    }

    /** @return array<string,string> */
    private function input(Request $request): array
    {
        return ['title' => $request->string('title'), 'description' => $request->string('description'), 'target_date' => $request->string('target_date'), 'visibility' => $request->string('visibility', 'internal')];
    }
}
