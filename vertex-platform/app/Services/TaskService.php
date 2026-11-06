<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Config;
use Vertex\Core\Database;
use Vertex\Core\HttpException;
use Vertex\Core\ValidationException;

/**
 * Task lifecycle. Every mutation: permission + staff access to the project (TaskPolicy), validation, ONE transaction
 * containing the change, the project timeline entry and the audit entry. Status moves follow TaskWorkflow only.
 *
 * Task priority is deliberately independent of PROJECT priority: changing a task's priority never touches pricing.
 */
final class TaskService
{
    /**
     * @param array<string,mixed> $project ProjectPolicy row
     * @param array<string,mixed> $in title, description, milestone, assignee, start_date, due_date, priority, estimate_minutes, review_required, status
     * @return array{id:int,public_id:string}
     */
    public static function create(int $actorId, array $project, array $in): array
    {
        ProjectPolicy::authorizeStaff($actorId, $project, 'tasks.create');
        self::assertProjectOpen($project);
        $d = self::validate($in, $project);
        $assigneeId = self::resolveAssignee($actorId, $project, $in['assignee'] ?? '', null);
        $status = FieldRules::choice($in['status'] ?? '', ['backlog' => 'Backlog', 'to_do' => 'To Do'], 'status', false, 'backlog');

        return Database::transaction(function () use ($actorId, $project, $d, $assigneeId, $status): array {
            $pub = uuid_hex();
            Database::run(
                'INSERT INTO tasks (public_id, project_id, milestone_id, title, description, assignee_id, created_by, start_date, due_date, status, priority,
                                    estimate_minutes, review_required, review_status, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'not_required\', UTC_TIMESTAMP(), UTC_TIMESTAMP())',
                [$pub, $project['id'], $d['milestone_id'], $d['title'], $d['description'], $assigneeId, $actorId, $d['start_date'], $d['due_date'], $status,
                    $d['priority'], $d['estimate_minutes'], $d['review_required']],
            );
            $id = Database::insertId();
            ProjectActivity::log((int) $project['id'], $actorId, 'task.created', 'Task created: ' . $d['title'], 'internal', 'task', $id);
            if ($assigneeId !== null) {
                ProjectActivity::log((int) $project['id'], $actorId, 'task.assigned', 'Task "' . $d['title'] . '" assigned to ' . self::userName($assigneeId), 'internal', 'task', $id);
            }
            Audit::record('task.created', 'task', $id, [], ['priority' => $d['priority'], 'assignee_id' => $assigneeId], ['project_id' => (int) $project['id']], $actorId);

            return ['id' => $id, 'public_id' => $pub];
        });
    }

    /**
     * Edits the descriptive fields (title, description, dates, priority, estimate, review flag, milestone). Assignment is a separate
     * permission and action (assign()). @param array<string,mixed> $task @param array<string,mixed> $in
     */
    public static function update(int $actorId, array $task, array $in): void
    {
        TaskPolicy::authorize($actorId, $task, 'tasks.edit');
        $project = TaskPolicy::project($task);
        self::assertTaskOpen($task, $project);
        $d = self::validate($in, $project);
        $map = ['title' => 'title', 'description' => 'description', 'start_date' => 'start_date', 'due_date' => 'due_date', 'priority' => 'priority',
            'estimate_minutes' => 'estimate_minutes', 'review_required' => 'review_required', 'milestone_id' => 'milestone_id'];
        $changed = [];
        foreach ($map as $k => $col) {
            if ((string) ($d[$k] ?? '') !== (string) ($task[$col] ?? '')) {
                $changed[] = $k;
            }
        }
        if ($changed === []) {
            return;
        }
        Database::transaction(function () use ($actorId, $task, $project, $d, $changed): void {
            $cur = Database::one('SELECT status FROM tasks WHERE id = ? FOR UPDATE', [$task['id']]);
            if ($cur === null || $cur['status'] !== $task['status']) {
                throw new ValidationException(['The task changed while you were editing. Reload and try again.']);
            }
            Database::run(
                'UPDATE tasks SET title = ?, description = ?, start_date = ?, due_date = ?, priority = ?, estimate_minutes = ?, review_required = ?, milestone_id = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?',
                [$d['title'], $d['description'], $d['start_date'], $d['due_date'], $d['priority'], $d['estimate_minutes'], $d['review_required'], $d['milestone_id'], $task['id']],
            );
            if (in_array('due_date', $changed, true)) {
                ProjectActivity::log((int) $project['id'], $actorId, 'task.deadline_changed', 'Task "' . $d['title'] . '" due date: ' . ($task['due_date'] ?? 'not set') . ' → ' . ($d['due_date'] ?? 'not set'), 'internal', 'task', (int) $task['id']);
            }
            if (array_diff($changed, ['due_date']) !== []) {
                ProjectActivity::log((int) $project['id'], $actorId, 'task.updated', 'Task updated: ' . $d['title'], 'internal', 'task', (int) $task['id']);
            }
            Audit::record('task.updated', 'task', (int) $task['id'], [], [], ['changed_fields' => $changed], $actorId);
        });
    }

    /** Assigns (or, with null/'' unassigns) a task. Needs `tasks.assign`; the assignee must be an active member of the project. @param array<string,mixed> $task */
    public static function assign(int $actorId, array $task, ?string $assigneePublicId): void
    {
        TaskPolicy::authorize($actorId, $task, 'tasks.assign');
        $project = TaskPolicy::project($task);
        self::assertTaskOpen($task, $project);
        $new = self::resolveAssignee($actorId, $project, (string) $assigneePublicId, (int) $task['id']);
        $old = $task['assignee_id'] === null ? null : (int) $task['assignee_id'];
        if ($new === $old) {
            return;
        }
        Database::transaction(function () use ($actorId, $task, $project, $new, $old): void {
            Database::run('UPDATE tasks SET assignee_id = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?', [$new, $task['id']]);
            $summary = $new === null ? 'Task "' . $task['title'] . '" unassigned' : 'Task "' . $task['title'] . '" ' . ($old === null ? 'assigned to ' : 'reassigned to ') . self::userName($new);
            ProjectActivity::log((int) $project['id'], $actorId, $new === null ? 'task.unassigned' : 'task.assigned', $summary, 'internal', 'task', (int) $task['id']);
            Audit::record($new === null ? 'task.unassigned' : 'task.assigned', 'task', (int) $task['id'], ['assignee_id' => $old], ['assignee_id' => $new], ['project_id' => (int) $project['id']], $actorId);
        });
    }

    /**
     * Moves a task along the explicit machine. Race-safe: the row is locked and the status you saw must still be current.
     *
     * @param array<string,mixed> $task as the user saw it (status is compared under lock)
     */
    public static function transition(int $actorId, array $task, string $to, string $reason = ''): void
    {
        $project = TaskPolicy::project($task);
        if (!TaskPolicy::canView($actorId, $task)) {
            throw new HttpException(404);
        }
        $from = (string) $task['status'];
        $letters = Config::get("workflow.task_transitions.{$from}.{$to}");
        if ($letters === null) {
            throw new ValidationException(["A {$from} task cannot move to {$to}."]);
        }
        self::assertTaskOpen($task, $project);
        if (!array_key_exists($to, TaskWorkflow::allowed($actorId, $task))) {
            \Vertex\Core\Logger::security('Task transition denied', ['user' => $actorId, 'task' => (int) $task['id'], 'from' => $from, 'to' => $to]);
            throw new HttpException(403);
        }
        $reason = (string) FieldRules::text($reason, 'Reason', 3, 1000, false);
        if (in_array($to, TaskWorkflow::NEEDS_REASON, true) && $reason === '') {
            throw new ValidationException(['Say why: a reason is required to move a task to ' . Config::get("workflow.task_statuses.{$to}") . '.']);
        }
        if (($msg = TaskWorkflow::guard($actorId, $task, $to)) !== null) {
            throw new ValidationException([$msg]);
        }

        Database::transaction(function () use ($actorId, $task, $project, $from, $to, $reason): void {
            $locked = Database::one('SELECT status FROM tasks WHERE id = ? FOR UPDATE', [$task['id']]);
            if ($locked === null || $locked['status'] !== $from) {
                throw new ValidationException(['The task was changed by someone else. Reload and try again.']);
            }
            // Re-check dependencies under the lock so two concurrent moves cannot both start a blocked task.
            if (($msg = TaskWorkflow::guard($actorId, $task, $to)) !== null) {
                throw new ValidationException([$msg]);
            }
            $done = $to === 'completed';
            Database::run(
                'UPDATE tasks SET status = ?, review_status = ?, completed_at = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?',
                [$to, TaskWorkflow::reviewStatusAfter($task, $from, $to), $done ? gmdate('Y-m-d H:i:s') : null, $task['id']],
            );
            if ($reason !== '') {
                Database::run('INSERT INTO task_comments (task_id, author_id, body, created_at) VALUES (?, ?, ?, UTC_TIMESTAMP())', [$task['id'], $actorId, mb_substr(ucfirst(str_replace('_', ' ', $to)) . ': ' . $reason, 0, 5000)]);
            }
            $labels = (array) Config::get('workflow.task_statuses');
            ProjectActivity::log((int) $project['id'], $actorId, 'task.status_changed', 'Task "' . $task['title'] . '": ' . $labels[$from] . ' → ' . $labels[$to], 'internal', 'task', (int) $task['id'], ['from' => $from, 'to' => $to]);
            Audit::record('task.status_changed', 'task', (int) $task['id'], ['status' => $from], ['status' => $to], ['project_id' => (int) $project['id']], $actorId);
        });
    }

    /** @param array<string,mixed> $task */
    public static function addComment(int $actorId, array $task, string $body): void
    {
        TaskPolicy::authorize($actorId, $task, 'tasks.comment');
        if ($task['project_archived_at'] !== null) {
            throw new ValidationException(['This project is archived.']);
        }
        $text = (string) FieldRules::text($body, 'Comment', 1, 3000);
        Database::transaction(function () use ($actorId, $task, $text): void {
            Database::run('INSERT INTO task_comments (task_id, author_id, body, created_at) VALUES (?, ?, ?, UTC_TIMESTAMP())', [$task['id'], $actorId, $text]);
            Audit::record('task.commented', 'task', (int) $task['id'], [], [], ['project_id' => (int) $task['project_id']], $actorId);
        });
    }

    /** Time can be logged only by the assignee, on an open task. @param array<string,mixed> $task */
    public static function logTime(int $actorId, array $task, mixed $minutes, mixed $workDate, mixed $note = ''): void
    {
        TaskPolicy::authorize($actorId, $task, 'tasks.log_time');
        if ($task['assignee_id'] === null || (int) $task['assignee_id'] !== $actorId) {
            throw new HttpException(403);
        }
        $project = TaskPolicy::project($task);
        self::assertTaskOpen($task, $project);
        $mins = FieldRules::minutes($minutes, 'Time', true, 1440);
        if ($mins === null || $mins < 1) {
            throw new ValidationException(['Time must be at least 1 minute.']);
        }
        $date = FieldRules::date($workDate === '' || $workDate === null ? AppTime::today() : $workDate, 'Work date', true);
        if ($date > AppTime::today()) {
            throw new ValidationException(['Work date cannot be in the future.']);
        }
        $n = FieldRules::line($note, 'Note', 1, 255, false);
        Database::transaction(function () use ($actorId, $task, $project, $mins, $date, $n): void {
            Database::run('INSERT INTO task_time_entries (task_id, user_id, minutes, work_date, note, created_at) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())', [$task['id'], $actorId, $mins, $date, $n]);
            ProjectActivity::log((int) $project['id'], $actorId, 'task.time_logged', "Time logged on \"{$task['title']}\": {$mins} min", 'internal', 'task', (int) $task['id']);
            Audit::record('task.time_logged', 'task', (int) $task['id'], [], [], ['minutes' => $mins], $actorId);
        });
    }

    /** @param array<string,mixed> $task @param array<string,mixed> $file one entry of $_FILES */
    public static function attach(int $actorId, array $task, array $file, bool $requireHttpUpload = true): void
    {
        TaskPolicy::authorize($actorId, $task, 'files.upload');
        self::assertTaskOpen($task, TaskPolicy::project($task));
        if (!RateLimiter::hit('attachment_user', (string) $actorId)) {
            throw new HttpException(429);
        }
        $att = AttachmentService::store('task', (int) $task['id'], $actorId, $file, $requireHttpUpload);
        Database::transaction(static function () use ($actorId, $task, $att): void {
            ProjectActivity::log((int) $task['project_id'], $actorId, 'task.attachment_added', 'File attached to task "' . $task['title'] . '": ' . $att['original_name'], 'internal', 'task', (int) $task['id']);
        });
    }

    /** @param array<string,mixed> $task */
    public static function setArchived(int $actorId, array $task, bool $archive): void
    {
        TaskPolicy::authorize($actorId, $task, 'tasks.archive');
        $project = TaskPolicy::project($task);
        if ($project['archived_at'] !== null) {
            throw new ValidationException(['This project is archived.']);
        }
        if (($task['archived_at'] !== null) === $archive) {
            return;
        }
        if ($archive && !in_array($task['status'], ['completed', 'cancelled', 'backlog'], true)) {
            throw new ValidationException(['Only completed, cancelled or backlog tasks can be archived. Cancel or finish it first.']);
        }
        Database::transaction(function () use ($actorId, $task, $project, $archive): void {
            Database::run('UPDATE tasks SET archived_at = ' . ($archive ? 'UTC_TIMESTAMP()' : 'NULL') . ', updated_at = UTC_TIMESTAMP() WHERE id = ?', [$task['id']]);
            ProjectActivity::log((int) $project['id'], $actorId, $archive ? 'task.archived' : 'task.restored', ($archive ? 'Task archived: ' : 'Task restored: ') . $task['title'], 'internal', 'task', (int) $task['id']);
            Audit::record($archive ? 'task.archived' : 'task.restored', 'task', (int) $task['id'], [], [], ['project_id' => (int) $project['id']], $actorId);
        });
    }

    /**
     * Makes $task wait on $dependsOn (same project). Cycles are rejected: the project row is locked so concurrent edits of
     * the dependency graph are serialised and the check cannot be raced.
     *
     * @param array<string,mixed> $task
     */
    public static function addDependency(int $actorId, array $task, string $dependsOnPublicId): void
    {
        TaskPolicy::authorize($actorId, $task, 'tasks.edit');
        $project = TaskPolicy::project($task);
        self::assertTaskOpen($task, $project);
        if (in_array($task['status'], ['completed', 'cancelled'], true)) {
            throw new ValidationException(['A finished task cannot gain new prerequisites.']);
        }
        $other = TaskPolicy::find($dependsOnPublicId);
        if ($other === null || (int) $other['project_id'] !== (int) $task['project_id'] || $other['archived_at'] !== null) {
            throw new ValidationException(['Choose a task from this project.']); // same message for "other project" and "missing": no probing
        }
        if ((int) $other['id'] === (int) $task['id']) {
            throw new ValidationException(['A task cannot depend on itself.']);
        }
        Database::transaction(function () use ($actorId, $task, $other, $project): void {
            Database::one('SELECT id FROM projects WHERE id = ? FOR UPDATE', [$task['project_id']]);
            if (Database::one('SELECT 1 AS x FROM task_dependencies WHERE task_id = ? AND depends_on_task_id = ?', [$task['id'], $other['id']]) !== null) {
                throw new ValidationException(['That dependency already exists.']);
            }
            if (self::reaches((int) $other['id'], (int) $task['id'])) {
                throw new ValidationException(['That would create a circular dependency: "' . $other['title'] . '" already depends (directly or indirectly) on this task.']);
            }
            Database::run('INSERT INTO task_dependencies (task_id, depends_on_task_id, created_by, created_at) VALUES (?, ?, ?, UTC_TIMESTAMP())', [$task['id'], $other['id'], $actorId]);
            ProjectActivity::log((int) $project['id'], $actorId, 'task.dependency_added', 'Task "' . $task['title'] . '" now depends on "' . $other['title'] . '"', 'internal', 'task', (int) $task['id']);
            Audit::record('task.dependency_added', 'task', (int) $task['id'], [], ['depends_on_task_id' => (int) $other['id']], ['project_id' => (int) $project['id']], $actorId);
        });
    }

    /** @param array<string,mixed> $task */
    public static function removeDependency(int $actorId, array $task, string $dependsOnPublicId): void
    {
        TaskPolicy::authorize($actorId, $task, 'tasks.edit');
        $project = TaskPolicy::project($task);
        self::assertTaskOpen($task, $project);
        $other = TaskPolicy::find($dependsOnPublicId);
        if ($other === null || (int) $other['project_id'] !== (int) $task['project_id']) {
            throw new HttpException(404);
        }
        Database::transaction(function () use ($actorId, $task, $other, $project): void {
            $n = Database::run('DELETE FROM task_dependencies WHERE task_id = ? AND depends_on_task_id = ?', [$task['id'], $other['id']])->rowCount();
            if ($n === 0) {
                return;
            }
            ProjectActivity::log((int) $project['id'], $actorId, 'task.dependency_removed', 'Task "' . $task['title'] . '" no longer depends on "' . $other['title'] . '"', 'internal', 'task', (int) $task['id']);
            Audit::record('task.dependency_removed', 'task', (int) $task['id'], ['depends_on_task_id' => (int) $other['id']], [], ['project_id' => (int) $project['id']], $actorId);
        });
    }

    /** True when $from depends (directly or through others) on $target. Iterative, so deep chains cannot overflow the stack. */
    public static function reaches(int $from, int $target): bool
    {
        $seen = [$from => true];
        $queue = [$from];
        while ($queue !== []) {
            $id = array_shift($queue);
            foreach (Database::all('SELECT depends_on_task_id AS d FROM task_dependencies WHERE task_id = ?', [$id]) as $row) {
                $d = (int) $row['d'];
                if ($d === $target) {
                    return true;
                }
                if (!isset($seen[$d])) {
                    $seen[$d] = true;
                    $queue[] = $d;
                }
            }
        }

        return false;
    }

    /** @param array<string,mixed> $task @return array{comments:list<array<string,mixed>>,time:list<array<string,mixed>>,logged_minutes:int,depends_on:list<array<string,mixed>>,blocking:list<array<string,mixed>>,attachments:list<array<string,mixed>>} */
    public static function detail(array $task): array
    {
        $id = (int) $task['id'];
        $time = Database::all('SELECT te.minutes, te.work_date, te.note, te.created_at, u.name FROM task_time_entries te LEFT JOIN users u ON u.id = te.user_id WHERE te.task_id = ? ORDER BY te.id DESC', [$id]);

        return [
            'comments' => Database::all('SELECT c.body, c.created_at, u.name FROM task_comments c LEFT JOIN users u ON u.id = c.author_id WHERE c.task_id = ? ORDER BY c.id', [$id]),
            'time' => $time,
            'logged_minutes' => array_sum(array_map(static fn (array $r): int => (int) $r['minutes'], $time)),
            'depends_on' => Database::all('SELECT d.public_id, d.title, d.status, d.archived_at FROM task_dependencies td JOIN tasks d ON d.id = td.depends_on_task_id WHERE td.task_id = ? ORDER BY d.id', [$id]),
            'blocking' => Database::all('SELECT d.public_id, d.title, d.status FROM task_dependencies td JOIN tasks d ON d.id = td.task_id WHERE td.depends_on_task_id = ? AND d.archived_at IS NULL ORDER BY d.id', [$id]),
            'attachments' => AttachmentService::forParent('task', $id),
        ];
    }

    /**
     * Same-project candidates for a new prerequisite: not itself, not archived, not already a prerequisite, and not something
     * that already (transitively) depends on this task. @param array<string,mixed> $task @return list<array<string,mixed>>
     */
    public static function dependencyCandidates(array $task): array
    {
        $rows = Database::all(
            'SELECT t.id, t.public_id, t.title, t.status FROM tasks t
              WHERE t.project_id = ? AND t.id <> ? AND t.archived_at IS NULL
                AND t.id NOT IN (SELECT depends_on_task_id FROM task_dependencies WHERE task_id = ?) ORDER BY t.title LIMIT 300',
            [$task['project_id'], $task['id'], $task['id']],
        );

        return array_values(array_filter($rows, static fn (array $r): bool => !self::reaches((int) $r['id'], (int) $task['id'])));
    }

    /**
     * All field problems are reported together (not one at a time).
     *
     * @param array<string,mixed> $in @param array<string,mixed> $project @return array<string,mixed>
     */
    private static function validate(array $in, array $project): array
    {
        $errors = [];
        $try = static function (callable $fn) use (&$errors): mixed {
            try {
                return $fn();
            } catch (ValidationException $e) {
                array_push($errors, ...$e->errors);

                return null;
            }
        };
        $title = $try(static fn () => (string) FieldRules::line($in['title'] ?? '', 'Task title', 3, 200));
        $description = $try(static fn () => FieldRules::text($in['description'] ?? '', 'Description', 3, 5000, false));
        $due = $try(static fn () => FieldRules::date($in['due_date'] ?? '', 'Due date'));
        $start = $try(static fn () => FieldRules::date($in['start_date'] ?? '', 'Start date'));
        if ($start !== null && $due !== null && $due < $start) {
            $errors[] = 'The due date cannot be before the start date.';
        }
        $priority = $try(static fn () => (string) FieldRules::choice($in['priority'] ?? '', (array) Config::get('workflow.priorities'), 'priority', false, 'normal'));
        $estimate = $try(static fn () => FieldRules::minutes($in['estimate_minutes'] ?? '', 'Estimate', false, 100000));
        $milestoneId = $try(static function () use ($in, $project): ?int {
            $mp = FieldRules::clean($in['milestone'] ?? '');
            if ($mp === '') {
                return null;
            }
            $m = MilestoneService::findInProject($mp, (int) $project['id']);
            if ($m === null || $m['archived_at'] !== null || in_array($m['status'], ['rejected', 'cancelled'], true)) {
                throw new ValidationException(['Choose a milestone from this project.']);
            }

            return (int) $m['id'];
        });
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return [
            'title' => $title,
            'description' => $description,
            'start_date' => $start,
            'due_date' => $due,
            'priority' => $priority,
            'estimate_minutes' => $estimate,
            'review_required' => (string) ($in['review_required'] ?? '1') === '0' || ($in['review_required'] ?? '1') === '' ? 0 : 1,
            'milestone_id' => $milestoneId,
        ];
    }

    /**
     * Resolves an assignee public id to a user id. Empty = unassigned. Setting any assignee needs `tasks.assign`, and the
     * assignee must be an ACTIVE, non-revoked member of this project with staff access.
     *
     * @param array<string,mixed> $project
     */
    private static function resolveAssignee(int $actorId, array $project, mixed $publicId, ?int $taskId): ?int
    {
        $pid = FieldRules::clean($publicId);
        if ($pid === '') {
            return null;
        }
        if (!Gate::can('tasks.assign', $actorId)) {
            throw new HttpException(403); // workers cannot hand out work
        }
        $u = preg_match('/^[a-f0-9]{32}$/', $pid) ? Database::one(
            "SELECT u.id FROM users u JOIN project_assignments pa ON pa.user_id = u.id AND pa.project_id = ? AND pa.revoked_at IS NULL
              WHERE u.public_id = ? AND u.status = 'active'",
            [$project['id'], $pid],
        ) : null;
        if ($u === null) {
            throw new ValidationException(['The assignee must be an active member of this project. Ask an administrator to add them first.']);
        }

        return (int) $u['id'];
    }

    /** @param array<string,mixed> $project */
    private static function assertProjectOpen(array $project): void
    {
        if ($project['archived_at'] !== null || in_array($project['status'], ['cancelled', 'completed'], true)) {
            throw new ValidationException(['This project is closed; tasks cannot be changed. Reopen it first.']);
        }
    }

    /** @param array<string,mixed> $task @param array<string,mixed> $project */
    private static function assertTaskOpen(array $task, array $project): void
    {
        self::assertProjectOpen($project);
        if ($task['archived_at'] !== null) {
            throw new ValidationException(['This task is archived.']);
        }
    }

    private static function userName(int $id): string
    {
        return (string) (Database::one('SELECT name FROM users WHERE id = ?', [$id])['name'] ?? 'a team member');
    }
}
