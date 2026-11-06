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

        Database::transaction(function () use ($actorId, $project, $user, $userId, $projectRole): void {
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
            ProjectActivity::log((int) $project['id'], $actorId, 'team.assigned', "{$user['name']} assigned to the project as {$projectRole}", 'internal', 'user', $userId);
            Audit::record('project.member_assigned', 'project', (int) $project['id'], [], ['user_id' => $userId, 'project_role' => $projectRole], ['project' => $project['public_id']], $actorId);
        });
    }

    /**
     * Removes a member. Their open tasks on the project are unassigned (they lose access to the project, so they must not
     * keep work they can no longer see); every change is on the timeline and audit log.
     */
    public static function revoke(int $actorId, int $projectId, int $userId): void
    {
        Gate::authorize('projects.assign', $actorId);
        Database::transaction(function () use ($actorId, $projectId, $userId): void {
            $n = Database::run(
                'UPDATE project_assignments SET revoked_at = UTC_TIMESTAMP(), revoked_by = ? WHERE project_id = ? AND user_id = ? AND revoked_at IS NULL',
                [$actorId, $projectId, $userId],
            )->rowCount();
            if ($n === 1) {
                $freed = self::releaseTasks($projectId, $userId, null);
                $name = (string) (Database::one('SELECT name FROM users WHERE id = ?', [$userId])['name'] ?? 'A team member');
                ProjectActivity::log($projectId, $actorId, 'team.unassigned', "{$name} removed from the project" . ($freed > 0 ? " ({$freed} open task(s) unassigned)" : ''), 'internal', 'user', $userId);
                Audit::record('project.member_unassigned', 'project', $projectId, ['user_id' => $userId], [], ['tasks_unassigned' => $freed], $actorId);
            }
        });
    }

    /**
     * Replaces one member with another in a single step; optionally hands the open tasks over. Admin-only like assign().
     */
    public static function reassign(int $actorId, int $projectId, int $fromUserId, int $toUserId, bool $moveTasks = true): void
    {
        Gate::authorize('projects.assign', $actorId);
        if ($fromUserId === $toUserId) {
            throw new ValidationException(['Choose a different team member.']);
        }
        Database::transaction(function () use ($actorId, $projectId, $fromUserId, $toUserId, $moveTasks): void {
            $role = Database::one('SELECT project_role FROM project_assignments WHERE project_id = ? AND user_id = ? AND revoked_at IS NULL FOR UPDATE', [$projectId, $fromUserId]);
            if ($role === null) {
                throw new ValidationException(['That person is not currently assigned to this project.']);
            }
            self::assign($actorId, $projectId, $toUserId, (string) $role['project_role']);
            Database::run('UPDATE project_assignments SET revoked_at = UTC_TIMESTAMP(), revoked_by = ? WHERE project_id = ? AND user_id = ? AND revoked_at IS NULL', [$actorId, $projectId, $fromUserId]);
            $moved = self::releaseTasks($projectId, $fromUserId, $moveTasks ? $toUserId : null);
            $names = Database::all('SELECT id, name FROM users WHERE id IN (?, ?)', [$fromUserId, $toUserId]);
            $n = array_column($names, 'name', 'id');
            ProjectActivity::log($projectId, $actorId, 'team.reassigned', "Reassigned from {$n[$fromUserId]} to {$n[$toUserId]}" . ($moved > 0 ? ($moveTasks ? " ({$moved} open task(s) handed over)" : " ({$moved} open task(s) unassigned)") : ''), 'internal', 'user', $toUserId, ['from' => $fromUserId, 'to' => $toUserId]);
            Audit::record('project.member_reassigned', 'project', $projectId, ['user_id' => $fromUserId], ['user_id' => $toUserId], ['tasks_moved' => $moveTasks ? $moved : 0, 'tasks_unassigned' => $moveTasks ? 0 : $moved], $actorId);
        });
    }

    public static function hasMembers(int $projectId): bool
    {
        return Database::one('SELECT 1 AS x FROM project_assignments WHERE project_id = ? AND revoked_at IS NULL', [$projectId]) !== null;
    }

    /** Hands a leaving member's open tasks to $to (or unassigns them when null). @return int number of tasks affected */
    private static function releaseTasks(int $projectId, int $fromUserId, ?int $to): int
    {
        return Database::run(
            "UPDATE tasks SET assignee_id = ?, updated_at = UTC_TIMESTAMP() WHERE project_id = ? AND assignee_id = ? AND archived_at IS NULL AND status NOT IN ('completed','cancelled')",
            [$to, $projectId, $fromUserId],
        )->rowCount();
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
