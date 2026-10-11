<?php

declare(strict_types=1);

namespace Vertex\Http\Controllers;

use Vertex\Core\Config;
use Vertex\Core\HttpException;
use Vertex\Core\Request;
use Vertex\Core\Response;
use Vertex\Core\Session;
use Vertex\Core\ValidationException;
use Vertex\Services\Gate;
use Vertex\Services\MilestoneService;
use Vertex\Services\ProjectAssignmentService;
use Vertex\Services\ProjectPolicy;
use Vertex\Services\TaskPolicy;
use Vertex\Services\TaskQuery;
use Vertex\Services\TaskService;
use Vertex\Services\TaskWorkflow;

/**
 * Tasks, Kanban board and deadlines. Route middleware (`can:tasks.view`) is only the first gate: TaskPolicy decides per
 * task (404 when not visible), and TaskService re-checks the specific permission on every mutation.
 */
final class TaskController extends Controller
{
    private const PER_PAGE = 25;

    /** @param array<string,string> $params */
    public function index(Request $request, array $params = []): Response
    {
        $uid = $this->actorId();
        $f = $this->filters($request);
        $page = $request->page();
        $res = TaskQuery::paginate($uid, $f, $page, self::PER_PAGE);

        return $this->page('pages/tasks', [
            'title' => 'Tasks', 'tasks' => $res['rows'], 'total' => $res['total'], 'page' => $page, 'perPage' => self::PER_PAGE,
            'filters' => $f, 'qs' => $f, 'projects' => TaskQuery::projectsFor($uid), 'people' => TaskQuery::assigneesFor($uid), 'view' => 'list',
        ]);
    }

    /** @param array<string,string> $params */
    public function board(Request $request, array $params = []): Response
    {
        $uid = $this->actorId();
        $f = $this->filters($request);
        $f['status'] = ''; // the board always shows every column; status is a column, not a filter
        $res = TaskQuery::board($uid, $f);
        $allowed = [];
        foreach ($res['columns'] as $cards) {
            foreach ($cards as $c) {
                $allowed[$c['public_id']] = array_keys(TaskWorkflow::allowedGiven($uid, $c, true));
            }
        }

        return $this->page('pages/board', [
            'title' => 'Task board', 'columns' => $res['columns'], 'truncated' => $res['truncated'], 'allowed' => $allowed,
            'filters' => $f, 'projects' => TaskQuery::projectsFor($uid), 'people' => TaskQuery::assigneesFor($uid), 'view' => 'board',
        ]);
    }

    /** @param array<string,string> $params */
    public function deadlines(Request $request, array $params = []): Response
    {
        return $this->page('pages/deadlines', ['title' => 'Deadlines', 'dl' => TaskQuery::deadlines($this->actorId())]);
    }

    /** Project "Tasks" tab: the project's tasks and the create form. @param array<string,string> $params */
    public function projectTasks(Request $request, array $params = []): Response
    {
        $uid = $this->actorId();
        $project = $request->attributes['project'];
        if (!in_array(ProjectPolicy::level($uid, $project), ['all', 'assigned'], true) || !Gate::can('tasks.view', $uid)) {
            throw new HttpException(404);
        }
        $f = $this->filters($request);
        $f['project'] = $project['public_id'];
        $page = $request->page();
        $res = TaskQuery::paginate($uid, $f, $page, self::PER_PAGE);
        $open = $project['archived_at'] === null && !in_array($project['status'], ['cancelled', 'completed'], true);
        $canAssign = Gate::can('tasks.assign', $uid);
        $r = $this->page('pages/project-tasks', [
            'title' => 'Tasks · ' . $project['title'], 'project' => $project, 'tab' => 'tasks', 'tasks' => $res['rows'], 'total' => $res['total'],
            'page' => $page, 'perPage' => self::PER_PAGE, 'filters' => $f, 'qs' => $f,
            'canCreate' => $open && Gate::can('tasks.create', $uid),
            'members' => $canAssign ? ProjectAssignmentService::members((int) $project['id']) : [],
            'milestones' => MilestoneService::listFor($uid, $project),
        ]);
        Session::forget('_old');

        return $r;
    }

    /** @param array<string,string> $params */
    public function store(Request $request, array $params = []): Response
    {
        $project = $request->attributes['project'];
        $to = '/projects/' . $project['public_id'] . '/tasks';
        $in = $this->taskInput($request) + ['assignee' => $request->string('assignee'), 'status' => $request->string('status')];

        return $this->guarded(function () use ($project, $in, $to): Response {
            $t = TaskService::create($this->actorId(), $project, $in);

            return $this->ok('/tasks/' . $t['public_id'], 'Task created.');
        }, $to, $in);
    }

    /** @param array<string,string> $params */
    public function show(Request $request, array $params = []): Response
    {
        $uid = $this->actorId();
        $task = TaskPolicy::requireView($uid, $params['task'] ?? '');
        $project = TaskPolicy::project($task);
        $detail = TaskService::detail($task);
        $open = TaskWorkflow::projectOpen($task) && $task['archived_at'] === null;
        $allowed = TaskWorkflow::allowed($uid, $task);
        $r = $this->page('pages/task', [
            'title' => $task['title'], 'task' => $task, 'project' => $project, 'detail' => $detail, 'allowed' => $allowed,
            'unresolved' => TaskWorkflow::unresolvedDependencies((int) $task['id']),
            'canEdit' => $open && TaskPolicy::can($uid, $task, 'tasks.edit'),
            'canAssign' => $open && TaskPolicy::can($uid, $task, 'tasks.assign'),
            'canComment' => $task['project_archived_at'] === null && TaskPolicy::can($uid, $task, 'tasks.comment'),
            'canLog' => $open && TaskPolicy::can($uid, $task, 'tasks.log_time') && $task['assignee_id'] !== null && (int) $task['assignee_id'] === $uid,
            'canArchive' => TaskPolicy::can($uid, $task, 'tasks.archive') && $project['archived_at'] === null,
            'canAttach' => $open && TaskPolicy::can($uid, $task, 'files.upload'),
            'canDownload' => Gate::can('files.download', $uid),
            'members' => $open && TaskPolicy::can($uid, $task, 'tasks.assign') ? ProjectAssignmentService::members((int) $task['project_id']) : [],
            'candidates' => $open && TaskPolicy::can($uid, $task, 'tasks.edit') ? TaskService::dependencyCandidates($task) : [],
            'milestones' => MilestoneService::listFor($uid, $project),
            'tab' => 'tasks',
        ]);
        Session::forget('_old');

        return $r;
    }

    /** @param array<string,string> $params */
    public function update(Request $request, array $params = []): Response
    {
        [$task, $to] = $this->load($params);
        $in = $this->taskInput($request);

        return $this->guarded(function () use ($task, $in, $to): Response {
            TaskService::update($this->actorId(), $task, $in);

            return $this->ok($to, 'Task updated.');
        }, $to, $in);
    }

    /**
     * Status change. Plain form post redirects; fetch() (Accept: application/json, used by the Kanban board) gets JSON.
     * Both paths run the exact same TaskService::transition(), so drag-and-drop has no extra privileges.
     *
     * @param array<string,string> $params
     */
    public function status(Request $request, array $params = []): Response
    {
        $json = $request->expectsJson();
        [$task, $to] = $this->load($params);
        $target = $request->string('status');
        $back = $request->string('return') === 'board' ? '/board' : $to;
        try {
            TaskService::transition($this->actorId(), $task, $target, $request->string('reason'));
        } catch (ValidationException $e) {
            if ($json) {
                return Response::json(['ok' => false, 'error' => $e->errors[0] ?? 'Not allowed.', 'errors' => $e->errors], 422);
            }

            return $this->back($back, $e->errors);
        }
        if ($json) {
            $fresh = TaskPolicy::find((string) $task['public_id']) ?? throw new HttpException(404);

            return Response::json([
                'ok' => true, 'status' => $fresh['status'], 'label' => (string) Config::get("workflow.task_statuses.{$fresh['status']}", $fresh['status']),
                'allowed' => array_keys(TaskWorkflow::allowed($this->actorId(), $fresh)),
            ]);
        }

        return $this->ok($back, 'Task moved to ' . Config::get("workflow.task_statuses.{$target}", $target) . '.');
    }

    /** @param array<string,string> $params */
    public function assign(Request $request, array $params = []): Response
    {
        [$task, $to] = $this->load($params);

        return $this->guarded(function () use ($request, $task, $to): Response {
            TaskService::assign($this->actorId(), $task, $request->string('assignee'));

            return $this->ok($to, 'Assignment saved.');
        }, $to);
    }

    /** @param array<string,string> $params */
    public function comment(Request $request, array $params = []): Response
    {
        [$task, $to] = $this->load($params);

        return $this->guarded(function () use ($request, $task, $to): Response {
            TaskService::addComment($this->actorId(), $task, $request->string('body'));

            return $this->ok($to, 'Comment added.');
        }, $to, ['comment' => $request->string('body')]);
    }

    /** @param array<string,string> $params */
    public function time(Request $request, array $params = []): Response
    {
        [$task, $to] = $this->load($params);

        return $this->guarded(function () use ($request, $task, $to): Response {
            TaskService::logTime($this->actorId(), $task, $request->string('minutes'), $request->string('work_date'), $request->string('note'));

            return $this->ok($to, 'Time logged.');
        }, $to);
    }

    /** @param array<string,string> $params */
    public function archive(Request $request, array $params = []): Response
    {
        [$task, $to] = $this->load($params);
        $archive = $request->string('restore') !== '1';

        return $this->guarded(function () use ($task, $archive, $to): Response {
            TaskService::setArchived($this->actorId(), $task, $archive);

            return $this->ok($archive ? '/projects/' . $task['project_public_id'] . '/tasks' : $to, $archive ? 'Task archived.' : 'Task restored.');
        }, $to);
    }

    /** @param array<string,string> $params */
    public function addDependency(Request $request, array $params = []): Response
    {
        [$task, $to] = $this->load($params);

        return $this->guarded(function () use ($request, $task, $to): Response {
            TaskService::addDependency($this->actorId(), $task, $request->string('depends_on'));

            return $this->ok($to, 'Dependency added.');
        }, $to);
    }

    /** @param array<string,string> $params */
    public function removeDependency(Request $request, array $params = []): Response
    {
        [$task, $to] = $this->load($params);

        return $this->guarded(function () use ($task, $params, $to): Response {
            TaskService::removeDependency($this->actorId(), $task, (string) ($params['dep'] ?? ''));

            return $this->ok($to, 'Dependency removed.');
        }, $to);
    }

    /** @param array<string,string> $params */
    public function attach(Request $request, array $params = []): Response
    {
        [$task, $to] = $this->load($params);

        return $this->guarded(function () use ($request, $task, $to): Response {
            TaskService::attach($this->actorId(), $task, $request->files['file'] ?? []);

            return $this->ok($to, 'File uploaded.');
        }, $to);
    }

    /** @param array<string,string> $params @return array{0:array<string,mixed>,1:string} */
    private function load(array $params): array
    {
        $task = TaskPolicy::requireView($this->actorId(), $params['task'] ?? '');

        return [$task, '/tasks/' . $task['public_id']];
    }

    /** @return array<string,string> */
    private function taskInput(Request $request): array
    {
        return [
            'title' => $request->string('title'), 'description' => $request->string('description'), 'milestone' => $request->string('milestone'),
            'start_date' => $request->string('start_date'), 'due_date' => $request->string('due_date'), 'priority' => $request->string('priority', 'normal'),
            'estimate_minutes' => $request->string('estimate_minutes'), 'review_required' => $request->string('review_required', '0') === '1' ? '1' : '0',
        ];
    }

    /** @return array{q:string,project:string,status:string,priority:string,assignee:string,overdue:bool} */
    private function filters(Request $request): array
    {
        $in = static fn (string $v, array $allowed): string => in_array($v, $allowed, true) ? $v : '';
        $assignee = $request->string('assignee');

        return [
            'q' => mb_substr($request->string('q'), 0, 100),
            'project' => preg_match('/^[a-f0-9]{32}$/', $request->string('project')) ? $request->string('project') : '',
            'status' => $in($request->string('status'), array_keys((array) Config::get('workflow.task_statuses'))),
            'priority' => $in($request->string('priority'), array_keys((array) Config::get('workflow.priorities'))),
            'assignee' => ($assignee === 'me' || $assignee === 'none' || preg_match('/^[a-f0-9]{32}$/', $assignee)) ? $assignee : '',
            'overdue' => $request->string('overdue') === '1',
        ];
    }
}
