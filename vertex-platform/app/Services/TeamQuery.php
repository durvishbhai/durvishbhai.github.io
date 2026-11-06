<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Database;
use Vertex\Core\HttpException;

/**
 * Administrator view of who is working on what. Read-only; changing assignments stays on the project page (projects.assign).
 * Visible only to people who can see every project, so it never reveals projects a user could not already open.
 */
final class TeamQuery
{
    /**
     * @return array{people:list<array<string,mixed>>,unstaffed:list<array<string,mixed>>}
     */
    public static function overview(int $userId): array
    {
        if (!Gate::can('projects.view_all', $userId)) {
            throw new HttpException(403);
        }
        $today = AppTime::today();
        $people = Database::all(
            "SELECT u.id, u.public_id, u.name,
                    (SELECT GROUP_CONCAT(r.name ORDER BY r.level DESC SEPARATOR ', ') FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = u.id) AS roles,
                    (SELECT COUNT(*) FROM tasks t WHERE t.assignee_id = u.id AND t.archived_at IS NULL AND t.status NOT IN ('completed','cancelled')) AS open_tasks,
                    (SELECT COUNT(*) FROM tasks t WHERE t.assignee_id = u.id AND t.archived_at IS NULL AND t.status NOT IN ('completed','cancelled') AND t.due_date < ?) AS overdue_tasks,
                    (SELECT COUNT(*) FROM tasks t WHERE t.assignee_id = u.id AND t.archived_at IS NULL AND t.status = 'blocked') AS blocked_tasks
               FROM users u
              WHERE u.status = 'active' AND u.id IN (SELECT pa.user_id FROM project_assignments pa WHERE pa.revoked_at IS NULL)
              ORDER BY u.name",
            [$today],
        );
        $projects = Database::all(
            "SELECT pa.user_id, pa.project_role, p.public_id, p.code, p.title, p.status
               FROM project_assignments pa JOIN projects p ON p.id = pa.project_id
              WHERE pa.revoked_at IS NULL AND p.archived_at IS NULL AND p.status NOT IN ('completed','cancelled') ORDER BY p.title",
        );
        $byUser = [];
        foreach ($projects as $p) {
            $byUser[(int) $p['user_id']][] = $p;
        }
        foreach ($people as &$person) {
            $person['projects'] = $byUser[(int) $person['id']] ?? [];
        }
        unset($person);

        $unstaffed = Database::all(
            "SELECT p.public_id, p.code, p.title, p.status, p.target_date FROM projects p
              WHERE p.archived_at IS NULL AND p.status NOT IN ('completed','cancelled')
                AND NOT EXISTS (SELECT 1 FROM project_assignments pa WHERE pa.project_id = p.id AND pa.revoked_at IS NULL)
              ORDER BY p.created_at DESC LIMIT 100",
        );

        return ['people' => $people, 'unstaffed' => $unstaffed];
    }
}
