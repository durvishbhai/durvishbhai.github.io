<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Config;
use Vertex\Core\Database;

/**
 * The task status machine (config/workflow.php `task_transitions`) turned into per-user answers.
 * Letters: A = the assignee (tasks.update_assigned), E = editor (tasks.edit), R = reviewer (tasks.review).
 * All moves additionally need staff-level access to the project and an open project (not archived/cancelled/completed).
 */
final class TaskWorkflow
{
    /** Statuses that mean the work has started: they require every prerequisite to be completed. */
    public const NEEDS_DEPENDENCIES_DONE = ['in_progress', 'in_review', 'completed'];

    /** Statuses that require a written reason (stored as a task comment). */
    public const NEEDS_REASON = ['blocked', 'changes_requested'];

    /**
     * Statuses the user may move this task to (permission-wise; dependency guards are reported by guard()).
     *
     * @param array<string,mixed> $task from TaskPolicy::find()
     * @return array<string,string> status => label
     */
    public static function allowed(int $userId, array $task): array
    {
        return self::allowedGiven($userId, $task, TaskPolicy::canView($userId, $task));
    }

    /**
     * Same answer when the caller has already proven staff access to the task's project (e.g. rows that came out of
     * TaskQuery, which is scoped by TaskPolicy::scopeSql). Avoids per-card queries on the Kanban board.
     *
     * @param array<string,mixed> $task needs status, assignee_id, archived_at, project_status, project_archived_at
     * @return array<string,string>
     */
    public static function allowedGiven(int $userId, array $task, bool $staff): array
    {
        if (!$staff || $task['archived_at'] !== null || !self::projectOpen($task)) {
            return [];
        }
        $out = [];
        foreach ((array) Config::get("workflow.task_transitions.{$task['status']}", []) as $to => $letters) {
            if (self::may($userId, $task, (string) $letters)) {
                $out[$to] = (string) Config::get("workflow.task_statuses.{$to}", $to);
            }
        }

        return $out;
    }

    /** Why this move is refused right now even though the role permits it; null = fine. @param array<string,mixed> $task */
    public static function guard(int $userId, array $task, string $to): ?string
    {
        if (in_array($to, self::NEEDS_DEPENDENCIES_DONE, true)) {
            $open = self::unresolvedDependencies((int) $task['id']);
            if ($open !== []) {
                return 'This task depends on unfinished work: ' . implode(', ', array_map(static fn (array $d): string => '"' . $d['title'] . '"', array_slice($open, 0, 3)))
                    . (count($open) > 3 ? ' and ' . (count($open) - 3) . ' more' : '') . '. Complete those first or remove the dependency.';
            }
        }
        if ($task['status'] === 'in_progress' && $to === 'completed' && (int) $task['review_required'] === 1 && !TaskPolicy::can($userId, $task, 'tasks.review')) {
            return 'This task needs review: send it to In Review instead of completing it.';
        }
        if ($task['status'] === 'in_review' && $to === 'completed' && $task['assignee_id'] !== null && (int) $task['assignee_id'] === $userId) {
            return 'You cannot approve your own work. Another reviewer must complete this task.';
        }

        return null;
    }

    /** Tasks this task waits on that are not completed (archived ones are ignored). @return list<array<string,mixed>> */
    public static function unresolvedDependencies(int $taskId): array
    {
        return Database::all(
            "SELECT d.public_id, d.title, d.status FROM task_dependencies td JOIN tasks d ON d.id = td.depends_on_task_id
              WHERE td.task_id = ? AND d.archived_at IS NULL AND d.status <> 'completed' ORDER BY d.id",
            [$taskId],
        );
    }

    /** @param array<string,mixed> $task */
    public static function projectOpen(array $task): bool
    {
        return $task['project_archived_at'] === null && !in_array($task['project_status'], ['cancelled', 'completed'], true);
    }

    /** Review status after moving $from -> $to. @param array<string,mixed> $task */
    public static function reviewStatusAfter(array $task, string $from, string $to): string
    {
        if ($to === 'in_review') {
            return (int) $task['review_required'] === 1 ? 'pending' : 'not_required';
        }
        if ($to === 'changes_requested') {
            return 'changes_requested';
        }
        if ($to === 'completed') {
            return (int) $task['review_required'] === 1 && $from === 'in_review' ? 'approved' : 'not_required';
        }
        if ($to === 'in_progress' && $from === 'changes_requested') {
            return 'changes_requested';
        }

        return 'not_required';
    }

    /** Assumes staff access to the project was already established. @param array<string,mixed> $task */
    private static function may(int $userId, array $task, string $letters): bool
    {
        if (str_contains($letters, 'A') && $task['assignee_id'] !== null && (int) $task['assignee_id'] === $userId && Gate::can('tasks.update_assigned', $userId)) {
            return true;
        }
        if (str_contains($letters, 'E') && Gate::can('tasks.edit', $userId)) {
            return true;
        }

        return str_contains($letters, 'R') && Gate::can('tasks.review', $userId);
    }
}
