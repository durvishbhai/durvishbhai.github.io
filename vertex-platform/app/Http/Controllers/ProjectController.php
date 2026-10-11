<?php

declare(strict_types=1);

namespace Vertex\Http\Controllers;

use Vertex\Core\HttpException;
use Vertex\Core\Request;
use Vertex\Core\Response;
use Vertex\Core\Session;
use Vertex\Repositories\UserRepository;
use Vertex\Services\Gate;
use Vertex\Services\ProjectAssignmentService;
use Vertex\Services\ProjectPolicy;

/**
 * Phase 1 scope: read-only access-controlled project list/detail plus Admin assignment. This exists to prove and
 * exercise the access model. Creating/editing projects, milestones, files etc. arrive in Phase 2 and MUST reuse
 * the `project` middleware / ProjectPolicy.
 */
final class ProjectController extends Controller
{
    /** @param array<string,string> $params */
    public function index(Request $request, array $params = []): Response
    {
        return $this->page('pages/projects', ['title' => 'Projects', 'projects' => ProjectPolicy::visibleTo($this->actorId())]);
    }

    /** @param array<string,string> $params */
    public function show(Request $request, array $params = []): Response
    {
        $project = $request->attributes['project']; // set by `project` middleware after the policy check
        $canAssign = Gate::can('projects.assign', $this->actorId());

        return $this->page('pages/project', [
            'title' => $project['title'],
            'project' => $project,
            'members' => ProjectAssignmentService::members((int) $project['id']),
            'canAssign' => $canAssign,
            'candidates' => $canAssign ? ProjectAssignmentService::candidates((int) $project['id']) : [],
            'notice' => Session::pullFlash('notice'),
        ]);
    }

    /** @param array<string,string> $params */
    public function assign(Request $request, array $params = []): Response
    {
        $project = $request->attributes['project'];
        $to = '/projects/' . $project['public_id'];

        return $this->guarded(function () use ($request, $project, $to): Response {
            $user = (new UserRepository())->findByPublicId($request->string('user')) ?? throw new HttpException(404);
            ProjectAssignmentService::assign($this->actorId(), (int) $project['id'], (int) $user['id'], $request->string('project_role', 'member'));

            return $this->ok($to, 'Team member assigned.');
        }, $to);
    }

    /** @param array<string,string> $params */
    public function revoke(Request $request, array $params = []): Response
    {
        $project = $request->attributes['project'];
        $user = (new UserRepository())->findByPublicId($request->attributes['params']['user'] ?? '') ?? throw new HttpException(404);
        ProjectAssignmentService::revoke($this->actorId(), (int) $project['id'], (int) $user['id']);

        return $this->ok('/projects/' . $project['public_id'], 'Team member unassigned.');
    }
}
