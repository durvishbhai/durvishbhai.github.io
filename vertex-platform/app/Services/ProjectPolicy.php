<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Database;
use Vertex\Core\HttpException;
use Vertex\Core\Logger;

/**
 * Row-level access to projects (and, through them, every project-scoped record: files, tasks, chat, ...).
 *
 * A user may see a project when ALL of these hold:
 *   1. their account is active, AND
 *   2. one of:
 *        - they hold `projects.view_all`;
 *        - they hold `projects.view_assigned` and have a non-revoked assignment to the project;
 *        - they hold `projects.view_own` and the project belongs to their client profile.
 *
 * Project-scoped routes use `project` middleware / ProjectPolicy::requireView(). Inaccessible AND non-existent projects
 * both produce 404, so project identifiers cannot be probed. Identifiers in URLs are random public ids.
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
            'SELECT p.*, c.user_id AS client_user_id, c.company_name, cu.name AS client_name
               FROM projects p JOIN clients c ON c.id = p.client_id JOIN users cu ON cu.id = c.user_id
              WHERE p.public_id = ?',
            [$publicId],
        );
    }

    /** @param array<string,mixed> $project a row from find() */
    public static function canView(int $userId, array $project): bool
    {
        if (!self::isActive($userId)) {
            return false;
        }
        if (Gate::can('projects.view_all', $userId)) {
            return true;
        }
        if (Gate::can('projects.view_assigned', $userId) && self::isAssigned($userId, (int) $project['id'])) {
            return true;
        }

        return Gate::can('projects.view_own', $userId) && (int) $project['client_user_id'] === $userId;
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
     * Projects visible to the user (same rules as canView, expressed in SQL).
     *
     * @return list<array<string,mixed>>
     */
    public static function visibleTo(int $userId): array
    {
        if (!self::isActive($userId)) {
            return [];
        }
        $base = 'SELECT p.public_id, p.title, p.status, p.created_at, c.company_name, cu.name AS client_name
                   FROM projects p JOIN clients c ON c.id = p.client_id JOIN users cu ON cu.id = c.user_id';
        if (Gate::can('projects.view_all', $userId)) {
            return Database::all($base . ' ORDER BY p.created_at DESC, p.id DESC');
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
            return [];
        }

        return Database::all($base . ' WHERE (' . implode(' OR ', $or) . ') ORDER BY p.created_at DESC, p.id DESC', $params);
    }

    private static function isActive(int $userId): bool
    {
        $row = Database::one("SELECT 1 AS x FROM users WHERE id = ? AND status = 'active'", [$userId]);

        return $row !== null;
    }
}
