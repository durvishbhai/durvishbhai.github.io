<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Database;
use Vertex\Core\HttpException;
use Vertex\Core\Logger;

/**
 * Row-level access to projects and, through them, every project-scoped record (tasks, milestones, notes, files, ...).
 *
 * Access LEVEL of a user to a project (null = none):
 *   'all'      account active AND holds `projects.view_all`
 *   'assigned' active AND holds `projects.view_assigned` AND has a non-revoked assignment
 *   'own'      active AND holds `projects.view_own` AND the project belongs to their client profile
 *
 * Archived projects are visible only at level 'all'. Staff abilities (tasks, milestones, notes ...) additionally need
 * level 'all' or 'assigned': a client never gets staff abilities even if a role was misconfigured to hold them.
 * Unknown and inaccessible projects both yield 404 so identifiers cannot be probed; URLs use random public ids.
 */
final class ProjectPolicy
{
    /** @return array<string,mixed>|null */
    public static function find(string $publicId): ?array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $publicId)) {
            return null;
        }

        return Database::one(
            'SELECT p.*, c.user_id AS client_user_id, c.public_id AS client_public_id, c.company_name, c.contact_name AS client_name
               FROM projects p JOIN clients c ON c.id = p.client_id
              WHERE p.public_id = ?',
            [$publicId],
        );
    }

    /** @return array<string,mixed>|null */
    public static function findById(int $id): ?array
    {
        $row = Database::one('SELECT public_id FROM projects WHERE id = ?', [$id]);

        return $row === null ? null : self::find((string) $row['public_id']);
    }

    /** @param array<string,mixed> $project a row from find() @return 'all'|'assigned'|'own'|null */
    public static function level(int $userId, array $project): ?string
    {
        if (!self::isActive($userId)) {
            return null;
        }
        $archived = $project['archived_at'] !== null;
        if (Gate::can('projects.view_all', $userId)) {
            return 'all';
        }
        if ($archived) {
            return null;
        }
        if (Gate::can('projects.view_assigned', $userId) && self::isAssigned($userId, (int) $project['id'])) {
            return 'assigned';
        }
        if (Gate::can('projects.view_own', $userId) && $project['client_user_id'] !== null && (int) $project['client_user_id'] === $userId) {
            return 'own';
        }

        return null;
    }

    /** @param array<string,mixed> $project */
    public static function canView(int $userId, array $project): bool
    {
        return self::level($userId, $project) !== null;
    }

    /**
     * Permission AND staff-level access to the project (level 'all' or 'assigned').
     *
     * @param array<string,mixed> $project
     */
    public static function canStaff(int $userId, array $project, string $permission): bool
    {
        return in_array(self::level($userId, $project), ['all', 'assigned'], true) && Gate::can($permission, $userId);
    }

    /** @param array<string,mixed> $project */
    public static function authorizeStaff(int $userId, array $project, string $permission): void
    {
        if (!self::canStaff($userId, $project, $permission)) {
            Logger::security('Project ability denied', ['user' => $userId, 'project' => (int) $project['id'], 'permission' => $permission]);
            throw new HttpException(403);
        }
    }

    /** @return array<string,mixed> the project; throws 404 when missing or not visible to the user */
    public static function requireView(int $userId, string $publicId): array
    {
        $project = self::find($publicId);
        if ($project === null || !self::canView($userId, $project)) {
            if ($project !== null) {
                Logger::security('Project access denied', ['user' => $userId, 'project' => (int) $project['id']]);
            }
            throw new HttpException(404);
        }

        return $project;
    }

    public static function isAssigned(int $userId, int $projectId): bool
    {
        return Database::one(
            'SELECT 1 AS x FROM project_assignments WHERE project_id = ? AND user_id = ? AND revoked_at IS NULL',
            [$projectId, $userId],
        ) !== null;
    }

    /**
     * SQL fragment + params restricting `projects p` (joined with `clients c`) to what the user may see.
     * Returns null when the user can see nothing. Same rules as level().
     *
     * @return array{0:string,1:list<mixed>}|null
     */
    public static function scopeSql(int $userId): ?array
    {
        if (!self::isActive($userId)) {
            return null;
        }
        if (Gate::can('projects.view_all', $userId)) {
            return ['1=1', []];
        }
        $or = [];
        $params = [];
        if (Gate::can('projects.view_assigned', $userId)) {
            $or[] = 'p.id IN (SELECT pa.project_id FROM project_assignments pa WHERE pa.user_id = ? AND pa.revoked_at IS NULL)';
            $params[] = $userId;
        }
        if (Gate::can('projects.view_own', $userId)) {
            $or[] = 'c.user_id = ?';
            $params[] = $userId;
        }
        if ($or === []) {
            return null;
        }

        // Non-admins never see archived projects.
        return ['(' . implode(' OR ', $or) . ') AND p.archived_at IS NULL', $params];
    }

    /**
     * @param array{q?:string,status?:string,priority?:string,service?:string,client?:string,archived?:bool} $filters
     * @return array{rows:list<array<string,mixed>>,total:int}
     */
    public static function visibleTo(int $userId, array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $scope = self::scopeSql($userId);
        if ($scope === null) {
            return ['rows' => [], 'total' => 0];
        }
        [$where, $params] = $scope;
        $where = [$where];
        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
            $where[] = '(p.title LIKE ? OR p.code LIKE ? OR c.contact_name LIKE ? OR c.company_name LIKE ?)';
            array_push($params, $like, $like, $like, $like);
        }
        foreach (['status' => 'p.status', 'priority' => 'p.priority', 'service' => 'p.service_category'] as $key => $col) {
            if (($filters[$key] ?? '') !== '') {
                $where[] = "{$col} = ?";
                $params[] = $filters[$key];
            }
        }
        if (($filters['client'] ?? '') !== '') {
            $where[] = 'c.public_id = ?';
            $params[] = $filters['client'];
        }
        // Archived projects only when explicitly asked for AND the user could see them at all (view_all).
        if (!empty($filters['archived'])) {
            $where[] = 'p.archived_at IS NOT NULL';
        } else {
            $where[] = 'p.archived_at IS NULL';
        }
        $w = implode(' AND ', $where);
        $base = "FROM projects p JOIN clients c ON c.id = p.client_id WHERE {$w}";
        $total = (int) Database::one("SELECT COUNT(*) AS c {$base}", $params)['c'];
        $offset = (int) min(max(0, ($page - 1) * $perPage), 2_000_000_000);
        $rows = Database::all(
            "SELECT p.id, p.public_id, p.code, p.title, p.status, p.priority, p.service_category, p.start_date, p.target_date, p.created_at, p.archived_at,
                    c.public_id AS client_public_id, c.company_name, c.contact_name AS client_name,
                    (SELECT COUNT(*) FROM tasks t WHERE t.project_id = p.id AND t.archived_at IS NULL AND t.status <> 'cancelled') AS task_total,
                    (SELECT COUNT(*) FROM tasks t WHERE t.project_id = p.id AND t.archived_at IS NULL AND t.status = 'completed') AS task_done
               {$base} ORDER BY p.created_at DESC, p.id DESC LIMIT {$perPage} OFFSET {$offset}",
            $params,
        );

        return ['rows' => $rows, 'total' => $total];
    }

    private static function isActive(int $userId): bool
    {
        return Database::one("SELECT 1 AS x FROM users WHERE id = ? AND status = 'active'", [$userId]) !== null;
    }
}
