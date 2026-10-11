<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Database;
use Vertex\Core\HttpException;
use Vertex\Core\ValidationException;

/**
 * Only holders of `projects.assign` (Super Admin and Administrator by default) can assign or unassign team members.
 * Assignees must be active and hold `projects.view_assigned` (i.e. be a worker/manager role), so clients, referral
 * partners and finance staff cannot be put on a project by mistake.
 */
final class ProjectAssignmentService
{
    public const ROLES = ['lead', 'member'];

    public static function assign(int $actorId, int $projectId, int $userId, string $projectRole = 'member'): void
    {
        Gate::authorize('projects.assign', $actorId);
        if (!in_array($projectRole, self::ROLES, true)) {
            throw new ValidationException(['Choose a valid project role.']);
        }
        $project = Database::one('SELECT id, public_id, title FROM projects WHERE id = ?', [$projectId]) ?? throw new HttpException(404);
        $user = Database::one('SELECT id, name, status FROM users WHERE id = ?', [$userId]);
        if ($user === null || $user['status'] !== 'active' || !Gate::can('projects.view_assigned', $userId)) {
            throw new ValidationException(['That user cannot be assigned to projects (must be an active team member).']);
        }

        Database::transaction(function () use ($actorId, $project, $userId, $projectRole): void {
            $existing = Database::one('SELECT id, revoked_at, project_role FROM project_assignments WHERE project_id = ? AND user_id = ? FOR UPDATE', [$project['id'], $userId]);
            if ($existing !== null && $existing['revoked_at'] === null) {
                if ($existing['project_role'] === $projectRole) {
                    return; // already assigned, nothing to do
                }
                Database::run('UPDATE project_assignments SET project_role = ? WHERE id = ?', [$projectRole, $existing['id']]);
                Audit::record('project.assignment_role_changed', 'project', (int) $project['id'], ['user_id' => $userId, 'project_role' => $existing['project_role']], ['user_id' => $userId, 'project_role' => $projectRole], [], $actorId);

                return;
            }
            if ($existing === null) {
                Database::run(
                    'INSERT INTO project_assignments (project_id, user_id, project_role, assigned_by, assigned_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP())',
                    [$project['id'], $userId, $projectRole, $actorId],
                );
            } else {
                Database::run(
                    'UPDATE project_assignments SET project_role = ?, assigned_by = ?, assigned_at = UTC_TIMESTAMP(), revoked_at = NULL, revoked_by = NULL WHERE id = ?',
                    [$projectRole, $actorId, $existing['id']],
                );
            }
            Audit::record('project.member_assigned', 'project', (int) $project['id'], [], ['user_id' => $userId, 'project_role' => $projectRole], ['project' => $project['public_id']], $actorId);
        });
    }

    public static function revoke(int $actorId, int $projectId, int $userId): void
    {
        Gate::authorize('projects.assign', $actorId);
        Database::transaction(function () use ($actorId, $projectId, $userId): void {
            $n = Database::run(
                'UPDATE project_assignments SET revoked_at = UTC_TIMESTAMP(), revoked_by = ? WHERE project_id = ? AND user_id = ? AND revoked_at IS NULL',
                [$actorId, $projectId, $userId],
            )->rowCount();
            if ($n === 1) {
                Audit::record('project.member_unassigned', 'project', $projectId, ['user_id' => $userId], [], [], $actorId);
            }
        });
    }

    /** @return list<array<string,mixed>> */
    public static function members(int $projectId): array
    {
        return Database::all(
            'SELECT u.public_id, u.name, pa.project_role, pa.assigned_at
               FROM project_assignments pa JOIN users u ON u.id = pa.user_id
              WHERE pa.project_id = ? AND pa.revoked_at IS NULL ORDER BY pa.assigned_at',
            [$projectId],
        );
    }

    /** Active users that could be assigned (for the admin picker). @return list<array<string,mixed>> */
    public static function candidates(int $projectId): array
    {
        return Database::all(
            "SELECT DISTINCT u.public_id, u.name
               FROM users u
               JOIN user_roles ur ON ur.user_id = u.id
               JOIN role_permissions rp ON rp.role_id = ur.role_id
               JOIN permissions p ON p.id = rp.permission_id AND p.perm_key = 'projects.view_assigned'
              WHERE u.status = 'active'
                AND u.id NOT IN (SELECT user_id FROM project_assignments WHERE project_id = ? AND revoked_at IS NULL)
              ORDER BY u.name",
            [$projectId],
        );
    }
}
