<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Database;
use Vertex\Core\HttpException;
use Vertex\Core\Logger;

/**
 * Row-level access to revision requests and change requests.
 *  - STAFF need `revisions.manage` AND staff-level access (all / assigned) to the project.
 *  - The CLIENT needs `revisions.request` and sees only revision requests THEY submitted on a project THEY own.
 * Missing and forbidden are both 404.
 */
final class RevisionAccess
{
    private const SELECT = 'SELECT r.*, p.public_id AS project_public_id, p.code AS project_code, p.title AS project_title, p.status AS project_status, p.archived_at AS project_archived_at,
                                   p.service_category AS project_service,
                                   d.public_id AS deliverable_public_id, d.code AS deliverable_code, d.title AS deliverable_title, d.archived_at AS deliverable_archived_at,
                                   v.public_id AS version_public_id, v.version_no, v.label AS version_label, rv.version_no AS resolved_version_no,
                                   ru.name AS requester_name, au.name AS assignee_name, au.public_id AS assignee_public_id
                              FROM revision_requests r
                              JOIN projects p ON p.id = r.project_id
                              JOIN deliverables d ON d.id = r.deliverable_id
                              JOIN deliverable_versions v ON v.id = r.version_id
                              LEFT JOIN deliverable_versions rv ON rv.id = r.resolved_version_id
                              LEFT JOIN users ru ON ru.id = r.requested_by
                              LEFT JOIN users au ON au.id = r.assigned_to';

    /** @return array<string,mixed>|null */
    public static function find(string $publicId): ?array
    {
        return preg_match('/^[a-f0-9]{32}$/', $publicId) ? Database::one(self::SELECT . ' WHERE r.public_id = ?', [$publicId]) : null;
    }

    /** @return array<string,mixed>|null */
    public static function findById(int $id): ?array
    {
        return Database::one(self::SELECT . ' WHERE r.id = ?', [$id]);
    }

    /** @param array<string,mixed> $r @return array<string,mixed> */
    public static function project(array $r): array
    {
        return ProjectPolicy::findById((int) $r['project_id']) ?? throw new HttpException(404);
    }

    /** @param array<string,mixed> $r @return 'staff'|'client'|null */
    public static function role(int $userId, array $r): ?string
    {
        $level = ProjectPolicy::level($userId, self::project($r));
        if (in_array($level, ['all', 'assigned'], true) && Gate::can('revisions.manage', $userId)) {
            return 'staff';
        }
        if ($level === 'own' && Gate::can('revisions.request', $userId) && $r['requested_by'] !== null && (int) $r['requested_by'] === $userId) {
            return 'client';
        }

        return null;
    }

    /**
     * The revision row as the CLIENT may hold it: no classification, internal reason, policy snapshot or reviewer detail, and the
     * internal "potentially chargeable" assessment is shown as plain "under review".
     *
     * @param array<string,mixed> $r
     * @return array<string,mixed>
     */
    public static function forClient(array $r): array
    {
        foreach (['scope_decision', 'scope_reason', 'policy_snapshot_json', 'assigned_to', 'assignee_name', 'assignee_public_id', 'submit_token'] as $k) {
            unset($r[$k]);
        }
        if (($r['status'] ?? '') === 'potentially_chargeable') {
            $r['status'] = 'under_review';
        }

        return $r;
    }

    /** @param array<string,mixed> $r */
    public static function canView(int $userId, array $r): bool
    {
        return self::role($userId, $r) !== null;
    }

    /** Permission AND staff access. @param array<string,mixed> $r */
    public static function canStaff(int $userId, array $r, string $permission): bool
    {
        return self::role($userId, $r) === 'staff' && Gate::can($permission, $userId);
    }

    /** @param array<string,mixed> $r */
    public static function authorizeStaff(int $userId, array $r, string $permission): void
    {
        if (!self::canStaff($userId, $r, $permission)) {
            Logger::security('Revision ability denied', ['user' => $userId, 'revision' => (int) $r['id'], 'permission' => $permission]);
            throw new HttpException(403);
        }
    }

    /** @return array<string,mixed> 404 when missing or not visible */
    public static function requireView(int $userId, string $publicId): array
    {
        $r = self::find($publicId);
        if ($r === null || !self::canView($userId, $r)) {
            if ($r !== null) {
                Logger::security('Revision access denied', ['user' => $userId, 'revision' => (int) $r['id']]);
            }
            throw new HttpException(404);
        }

        return $r;
    }

    /** @return array<string,mixed>|null */
    public static function findChange(string $publicId): ?array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $publicId)) {
            return null;
        }

        return Database::one(
            'SELECT c.*, p.public_id AS project_public_id, p.code AS project_code, p.title AS project_title, p.status AS project_status, p.archived_at AS project_archived_at,
                    r.public_id AS revision_public_id, r.reference AS revision_reference, r.status AS revision_status, r.requested_by AS revision_requested_by, pu.name AS proposed_by_name
               FROM change_requests c JOIN projects p ON p.id = c.project_id LEFT JOIN revision_requests r ON r.id = c.revision_request_id LEFT JOIN users pu ON pu.id = c.proposed_by
              WHERE c.public_id = ?',
            [$publicId],
        );
    }

    /**
     * Staff see every change request of a project they can staff (`revisions.manage`); the client sees only PROPOSALS ADDRESSED TO THEM
     * (pending/decided, never drafts) on their own project.
     *
     * @param array<string,mixed> $c
     * @return 'staff'|'client'|null
     */
    public static function changeRole(int $userId, array $c): ?string
    {
        $project = ProjectPolicy::findById((int) $c['project_id']);
        if ($project === null) {
            return null;
        }
        $level = ProjectPolicy::level($userId, $project);
        if (in_array($level, ['all', 'assigned'], true) && Gate::can('revisions.manage', $userId)) {
            return 'staff';
        }
        if ($level === 'own' && Gate::can('revisions.respond_cost', $userId) && $c['status'] !== 'draft') {
            return 'client';
        }

        return null;
    }

    /** @return array<string,mixed> */
    public static function requireChange(int $userId, string $publicId): array
    {
        $c = self::findChange($publicId);
        if ($c === null || self::changeRole($userId, $c) === null) {
            throw new HttpException(404);
        }

        return $c;
    }
}
