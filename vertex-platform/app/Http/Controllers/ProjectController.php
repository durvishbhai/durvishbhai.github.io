<?php

declare(strict_types=1);

namespace Vertex\Http\Controllers;

use Vertex\Core\Config;
use Vertex\Core\Database;
use Vertex\Core\HttpException;
use Vertex\Core\Request;
use Vertex\Core\Response;
use Vertex\Core\Session;
use Vertex\Core\ValidationException;
use Vertex\Repositories\UserRepository;
use Vertex\Services\ClientPolicy;
use Vertex\Services\FieldRules;
use Vertex\Services\Gate;
use Vertex\Services\MilestoneService;
use Vertex\Services\ProjectActivity;
use Vertex\Services\ProjectAssignmentService;
use Vertex\Services\ProjectPolicy;
use Vertex\Services\ProjectService;

/**
 * Projects. List/detail/timeline are visible to anyone with access to the project (ProjectPolicy: all / assigned / own).
 * Mutations additionally need the specific permission AND staff-level access (the services enforce both; route
 * middleware is only the first gate). `project` middleware resolves {project} and returns 404 for anything not visible.
 */
final class ProjectController extends Controller
{
    private const PER_PAGE = 20;

    private const EDIT_FIELDS = ['title', 'service_category', 'description', 'scope', 'start_date', 'target_date'];

    /** @param array<string,string> $params */
    public function index(Request $request, array $params = []): Response
    {
        $uid = $this->actorId();
        $f = [
            'q' => mb_substr($request->string('q'), 0, 100),
            'status' => $this->in($request->string('status'), array_keys((array) Config::get('workflow.project_statuses'))),
            'priority' => $this->in($request->string('priority'), array_keys((array) Config::get('workflow.priorities'))),
            'service' => $this->in($request->string('service'), array_keys((array) Config::get('workflow.services'))),
            'archived' => Gate::can('projects.view_all', $uid) && $request->string('archived') === '1',
        ];
        $page = $request->page();
        $res = ProjectPolicy::visibleTo($uid, $f, $page, self::PER_PAGE);
        $qs = $f;
        $qs['archived'] = $f['archived'] ? '1' : '';

        return $this->page('pages/projects', ['title' => 'Projects', 'projects' => $res['rows'], 'total' => $res['total'], 'page' => $page, 'perPage' => self::PER_PAGE, 'filters' => $f, 'qs' => $qs]);
    }

    /** @param array<string,string> $params */
    public function create(Request $request, array $params = []): Response
    {
        $r = $this->page('pages/project-create', [
            'title' => 'New project',
            'clients' => Database::all("SELECT public_id, contact_name, company_name FROM clients WHERE status = 'active' AND archived_at IS NULL ORDER BY contact_name LIMIT 500"),
        ]);
        Session::forget('_old');

        return $r;
    }

    /** @param array<string,string> $params */
    public function store(Request $request, array $params = []): Response
    {
        $in = $this->fields($request) + ['priority' => $request->string('priority'), 'budget' => $request->string('budget')];

        return $this->guarded(function () use ($request, $in): Response {
            $client = ClientPolicy::find($request->string('client')) ?? throw new ValidationException(['Choose a client.']);
            $p = ProjectService::create($this->actorId(), (int) $client['id'], $in);

            return $this->ok('/projects/' . $p['public_id'], "Draft project {$p['code']} created.");
        }, '/projects/create', $in + ['client' => $request->string('client')]);
    }

    /** @param array<string,string> $params */
    public function show(Request $request, array $params = []): Response
    {
        $uid = $this->actorId();
        $project = $request->attributes['project'];
        $level = (string) ProjectPolicy::level($uid, $project);
        $staff = in_array($level, ['all', 'assigned'], true);
        $canAssign = $level === 'all' && Gate::can('projects.assign', $uid);
        $members = ProjectAssignmentService::members((int) $project['id']);
        $financeVisible = $staff && (Gate::can('projects.edit', $uid) || Gate::can('finance.view', $uid) || Gate::can('pricing.view', $uid));

        $r = $this->page('pages/project', [
            'title' => $project['title'],
            'project' => $project, 'level' => $level, 'staff' => $staff,
            'members' => $members,
            'canAssign' => $canAssign,
            'candidates' => $canAssign ? ProjectAssignmentService::candidates((int) $project['id']) : [],
            'transitions' => ProjectService::allowedTransitions($uid, $project),
            'progress' => ProjectService::progress((int) $project['id']),
            'notes' => ProjectService::notes($uid, $project),
            'milestones' => array_slice(MilestoneService::listFor($uid, $project), 0, 6),
            'canEdit' => $staff && Gate::can('projects.edit', $uid) && $project['archived_at'] === null && !in_array($project['status'], ['completed', 'cancelled'], true),
            'canArchive' => $level === 'all' && Gate::can('projects.archive', $uid),
            'financeVisible' => $financeVisible,
            'canBudget' => Gate::can('projects.record_approval', $uid),
            'openTasks' => (int) Database::one("SELECT COUNT(*) AS c FROM tasks WHERE project_id = ? AND archived_at IS NULL AND status NOT IN ('completed','cancelled')", [$project['id']])['c'],
        ]);
        Session::forget('_old');

        return $r;
    }

    /** @param array<string,string> $params */
    public function update(Request $request, array $params = []): Response
    {
        $project = $request->attributes['project'];
        $in = $this->fields($request);
        if ($request->input('budget') !== null) {
            $in['budget'] = $request->string('budget');
        }
        $to = '/projects/' . $project['public_id'];

        return $this->guarded(function () use ($project, $in, $to): Response {
            ProjectService::update($this->actorId(), $project, $in);

            return $this->ok($to, 'Project updated.');
        }, $to . '#edit', $in);
    }

    /** @param array<string,string> $params */
    public function transition(Request $request, array $params = []): Response
    {
        $project = $request->attributes['project'];
        $to = '/projects/' . $project['public_id'];

        return $this->guarded(function () use ($request, $project, $to): Response {
            ProjectService::transition($this->actorId(), $project, $request->string('status'), $request->string('note'), $request->string('confirm') === '1');

            return $this->ok($to, 'Status updated.');
        }, $to);
    }

    /** @param array<string,string> $params */
    public function note(Request $request, array $params = []): Response
    {
        $project = $request->attributes['project'];
        $to = '/projects/' . $project['public_id'];

        return $this->guarded(function () use ($request, $project, $to): Response {
            ProjectService::addNote($this->actorId(), $project, $request->string('kind'), $request->string('body'));

            return $this->ok($to, 'Saved.');
        }, $to);
    }

    /** @param array<string,string> $params */
    public function archive(Request $request, array $params = []): Response
    {
        $project = $request->attributes['project'];
        $to = '/projects/' . $project['public_id'];

        return $this->guarded(function () use ($request, $project, $to): Response {
            $this->requireStepUp($request);
            ProjectService::setArchived($this->actorId(), $project, $request->string('restore') !== '1');

            return $this->ok($to, $request->string('restore') === '1' ? 'Project restored.' : 'Project archived.');
        }, $to);
    }

    /** @param array<string,string> $params */
    public function timeline(Request $request, array $params = []): Response
    {
        $uid = $this->actorId();
        $project = $request->attributes['project'];
        $page = $request->page();
        $res = ProjectActivity::timeline((int) $project['id'], ProjectPolicy::level($uid, $project) === 'own', $page, 30);

        return $this->page('pages/project-timeline', ['title' => 'Timeline · ' . $project['title'], 'project' => $project, 'items' => $res['rows'], 'total' => $res['total'], 'page' => $page, 'tab' => 'timeline']);
    }

    // ---- team (Admin-only: projects.assign) -----------------------------------------------------------------------------------

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
    public function reassign(Request $request, array $params = []): Response
    {
        $project = $request->attributes['project'];
        $to = '/projects/' . $project['public_id'];

        return $this->guarded(function () use ($request, $project, $to): Response {
            $repo = new UserRepository();
            $from = $repo->findByPublicId($request->string('from')) ?? throw new HttpException(404);
            $new = $repo->findByPublicId($request->string('to_user')) ?? throw new ValidationException(['Choose who to hand the work to.']);
            ProjectAssignmentService::reassign($this->actorId(), (int) $project['id'], (int) $from['id'], (int) $new['id'], $request->string('move_tasks') === '1');

            return $this->ok($to, 'Team member reassigned.');
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

    /** @return array<string,string> */
    private function fields(Request $request): array
    {
        $out = [];
        foreach (self::EDIT_FIELDS as $f) {
            $out[$f] = $request->string($f);
        }

        return $out;
    }

    /** @param list<string> $allowed */
    private function in(string $v, array $allowed): string
    {
        return in_array($v, $allowed, true) ? $v : '';
    }
}
