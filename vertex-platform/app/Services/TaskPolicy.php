<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Database;
use Vertex\Core\HttpException;
use Vertex\Core\Logger;

/**
 * Row-level access to tasks. Tasks are INTERNAL working items: a task is reachable only by staff who hold `tasks.view`
 * and have staff-level access (level 'all' or 'assigned') to the task's PROJECT. The Client role never reaches a task,
 * even if a role were misconfigured to hold tasks.view, because ProjectPolicy::level() would be 'own'.
 *
 * Inside an assigned project a worker sees every task of that project (they collaborate on it); they can only change
 * the tasks the permissions allow (see TaskWorkflow and TaskService). Unknown and inaccessible tasks both yield 404.
 */
final class TaskPolicy
{
    private const SELECT = 'SELECT t.*, p.public_id AS project_public_id, p.code AS project_code, p.title AS project_title, p.status AS project_status,
                                   p.archived_at AS project_archived_at, p.target_date AS project_target_date,
                                   a.public_id AS assignee_public_id, a.name AS assignee_name,
                                   m.public_id AS milestone_public_id, m.title AS milestone_title
                              FROM tasks t
                              JOIN projects p ON p.id = t.project_id
                              LEFT JOIN users a ON a.id = t.assignee_id
                              LEFT JOIN milestones m ON m.id = t.milestone_id';

    /** @return array<string,mixed>|null */
    public static function find(string $publicId): ?array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $publicId)) {
            return null;
        }

        return Database::one(self::SELECT . ' WHERE t.public_id = ?', [$publicId]);
    }

    /** @return array<string,mixed>|null */
    public static function findById(int $id): ?array
    {
        return Database::one(self::SELECT . ' WHERE t.id = ?', [$id]);
    }

    /** The project row (ProjectPolicy shape) a task belongs to. @param array<string,mixed> $task @return array<string,mixed> */
    public static function project(array $task): array
    {
        return ProjectPolicy::findById((int) $task['project_id']) ?? throw new HttpException(404);
    }

    /** @param array<string,mixed> $task */
    public static function canView(int $userId, array $task): bool
    {
        return Gate::can('tasks.view', $userId) && in_array(ProjectPolicy::level($userId, self::project($task)), ['all', 'assigned'], true);
    }

    /** Permission AND staff access to the task's project. @param array<string,mixed> $task */
    public static function can(int $userId, array $task, string $permission): bool
    {
        return Gate::can('tasks.view', $userId) && ProjectPolicy::canStaff($userId, self::project($task), $permission);
    }

    /** @param array<string,mixed> $task */
    public static function authorize(int $userId, array $task, string $permission): void
    {
        if (!self::can($userId, $task, $permission)) {
            Logger::security('Task ability denied', ['user' => $userId, 'task' => (int) $task['id'], 'permission' => $permission]);
            throw new HttpException(403);
        }
    }

    /** @return array<string,mixed> the task; 404 when missing or not visible */
    public static function requireView(int $userId, string $publicId): array
    {
        $task = self::find($publicId);
        if ($task === null || !self::canView($userId, $task)) {
            if ($task !== null) {
                Logger::security('Task access denied', ['user' => $userId, 'task' => (int) $task['id']]);
            }
            throw new HttpException(404);
        }

        return $task;
    }

    /**
     * SQL fragment restricting `tasks t JOIN projects p` to what the user may see; null = nothing.
     * Same rules as canView(). Clients (level 'own') never get tasks.
     *
     * @return array{0:string,1:list<mixed>}|null
     */
    public static function scopeSql(int $userId): ?array
    {
        if (!Gate::can('tasks.view', $userId) || Database::one("SELECT 1 AS x FROM users WHERE id = ? AND status = 'active'", [$userId]) === null) {
            return null;
        }
        if (Gate::can('projects.view_all', $userId)) {
            return ['1=1', []];
        }
        if (Gate::can('projects.view_assigned', $userId)) {
            return ['(p.archived_at IS NULL AND p.id IN (SELECT pa.project_id FROM project_assignments pa WHERE pa.user_id = ? AND pa.revoked_at IS NULL))', [$userId]];
        }

        return null;
    }
}
