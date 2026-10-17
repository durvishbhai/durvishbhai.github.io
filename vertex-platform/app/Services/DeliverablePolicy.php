<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Database;
use Vertex\Core\HttpException;
use Vertex\Core\Logger;

/**
 * Row-level access to deliverables, their versions and files.
 *
 * Who sees what (project level from ProjectPolicy: all / assigned / own):
 *  - STAFF (all, assigned) need `deliverables.view`. With `deliverables.view_drafts` they see every deliverable and version
 *    (drafts, pending review, approved, withdrawn). Without it they see only deliverables that have been published at some point,
 *    and only the versions that were published to the client.
 *  - The CLIENT (own project) needs `deliverables.view` and sees only deliverables that were published at some point, and only
 *    versions with status published/superseded (never drafts, withdrawn versions, internal notes or QA discussions).
 *  - Referral partners, finance staff and everyone else have no project level, hence no access.
 * Unknown and inaccessible ids both give 404. Files are never addressed by their own id: a download is "version of deliverable".
 */
final class DeliverablePolicy
{
    private const SELECT = 'SELECT d.*, p.public_id AS project_public_id, p.code AS project_code, p.title AS project_title, p.status AS project_status,
                                   p.archived_at AS project_archived_at,
                                   t.public_id AS task_public_id, t.title AS task_title, m.public_id AS milestone_public_id, m.title AS milestone_title,
                                   cu.name AS creator_name
                              FROM deliverables d
                              JOIN projects p ON p.id = d.project_id
                              LEFT JOIN tasks t ON t.id = d.task_id
                              LEFT JOIN milestones m ON m.id = d.milestone_id
                              LEFT JOIN users cu ON cu.id = d.created_by';

    /** @return array<string,mixed>|null */
    public static function find(string $publicId): ?array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $publicId)) {
            return null;
        }

        return Database::one(self::SELECT . ' WHERE d.public_id = ?', [$publicId]);
    }

    /** @return array<string,mixed>|null */
    public static function findById(int $id): ?array
    {
        return Database::one(self::SELECT . ' WHERE d.id = ?', [$id]);
    }

    /** @param array<string,mixed> $d @return array<string,mixed> the project row (ProjectPolicy shape) */
    public static function project(array $d): array
    {
        return ProjectPolicy::findById((int) $d['project_id']) ?? throw new HttpException(404);
    }

    /** @param array<string,mixed> $d @return 'all'|'assigned'|'own'|null */
    public static function level(int $userId, array $d): ?string
    {
        return ProjectPolicy::level($userId, self::project($d));
    }

    /** True when this user may see unpublished work on this deliverable's project. @param array<string,mixed> $d */
    public static function seesDrafts(int $userId, array $d): bool
    {
        return in_array(self::level($userId, $d), ['all', 'assigned'], true) && Gate::can('deliverables.view_drafts', $userId);
    }

    /** @param array<string,mixed> $d */
    public static function canView(int $userId, array $d): bool
    {
        if (!Gate::can('deliverables.view', $userId)) {
            return false;
        }
        $level = self::level($userId, $d);
        if ($level === null) {
            return false;
        }
        if (in_array($level, ['all', 'assigned'], true) && Gate::can('deliverables.view_drafts', $userId)) {
            return true;
        }

        return $d['first_published_at'] !== null; // clients, and staff without draft access: only what has reached the client
    }

    /** Permission AND staff-level access to the project. @param array<string,mixed> $d */
    public static function can(int $userId, array $d, string $permission): bool
    {
        return Gate::can('deliverables.view', $userId) && ProjectPolicy::canStaff($userId, self::project($d), $permission) && self::canView($userId, $d);
    }

    /** @param array<string,mixed> $d */
    public static function authorize(int $userId, array $d, string $permission): void
    {
        if (!self::can($userId, $d, $permission)) {
            Logger::security('Deliverable ability denied', ['user' => $userId, 'deliverable' => (int) $d['id'], 'permission' => $permission]);
            throw new HttpException(403);
        }
    }

    /** @return array<string,mixed> 404 when missing or not visible */
    public static function requireView(int $userId, string $publicId): array
    {
        $d = self::find($publicId);
        if ($d === null || !self::canView($userId, $d)) {
            if ($d !== null) {
                Logger::security('Deliverable access denied', ['user' => $userId, 'deliverable' => (int) $d['id']]);
            }
            throw new HttpException(404);
        }

        return $d;
    }

    /** Is this version visible to the user? @param array<string,mixed> $d @param array<string,mixed> $v */
    public static function canViewVersion(int $userId, array $d, array $v): bool
    {
        if ((int) $v['deliverable_id'] !== (int) $d['id'] || !self::canView($userId, $d)) {
            return false;
        }
        if (self::seesDrafts($userId, $d)) {
            return true;
        }

        return self::isClientVisible($v);
    }

    /** A version the client may see: it was published and is not withdrawn. @param array<string,mixed> $v */
    public static function isClientVisible(array $v): bool
    {
        return $v['published_at'] !== null && in_array($v['status'], ['published', 'superseded'], true);
    }

    /** Download = view + `files.download` + the version has a stored file. @param array<string,mixed> $d @param array<string,mixed> $v */
    public static function canDownload(int $userId, array $d, array $v): bool
    {
        return $v['file_id'] !== null && Gate::can('files.download', $userId) && self::canViewVersion($userId, $d, $v);
    }

    /** SQL restriction for lists over `deliverables d JOIN projects p JOIN clients c`. Same rules as canView(). @return array{0:string,1:list<mixed>}|null */
    public static function scopeSql(int $userId): ?array
    {
        if (!Gate::can('deliverables.view', $userId) || Database::one("SELECT 1 AS x FROM users WHERE id = ? AND status = 'active'", [$userId]) === null) {
            return null;
        }
        $drafts = Gate::can('deliverables.view_drafts', $userId);
        $or = [];
        $params = [];
        if (Gate::can('projects.view_all', $userId)) {
            $or[] = $drafts ? '1=1' : 'd.first_published_at IS NOT NULL';
        } elseif (Gate::can('projects.view_assigned', $userId)) {
            $or[] = '(p.archived_at IS NULL AND p.id IN (SELECT pa.project_id FROM project_assignments pa WHERE pa.user_id = ? AND pa.revoked_at IS NULL)'
                . ($drafts ? '' : ' AND d.first_published_at IS NOT NULL') . ')';
            $params[] = $userId;
        }
        if (Gate::can('projects.view_own', $userId)) {
            $or[] = '(c.user_id = ? AND p.archived_at IS NULL AND d.first_published_at IS NOT NULL)';
            $params[] = $userId;
        }

        return $or === [] ? null : ['(' . implode(' OR ', $or) . ')', $params];
    }

    /**
     * Versions the user may see, newest first. Internal columns (change notes, approver) are returned only to people who see drafts.
     *
     * @param array<string,mixed> $d
     * @return list<array<string,mixed>>
     */
    public static function versionsFor(int $userId, array $d): array
    {
        if (!self::canView($userId, $d)) {
            return [];
        }
        $staff = self::seesDrafts($userId, $d);
        $where = 'v.deliverable_id = ?' . ($staff ? '' : " AND v.published_at IS NOT NULL AND v.status IN ('published','superseded')");
        $rows = Database::all(
            "SELECT v.*, f.original_name, f.size_bytes, f.mime, f.sha256, f.scan_status, u.name AS uploader_name, au.name AS approver_name, pu.name AS publisher_name
               FROM deliverable_versions v
               LEFT JOIN stored_files f ON f.id = v.file_id
               LEFT JOIN users u ON u.id = v.uploaded_by
               LEFT JOIN users au ON au.id = v.approved_by
               LEFT JOIN users pu ON pu.id = v.published_by
              WHERE {$where} ORDER BY v.version_no DESC",
            [$d['id']],
        );
        if (!$staff) {
            foreach ($rows as &$r) { // what a client must never receive, removed at the data layer rather than hidden in a template
                foreach (['change_notes', 'approver_name', 'approved_by', 'approved_at', 'submitted_at', 'uploaded_by', 'uploader_name', 'withdrawn_by', 'withdrawn_at', 'sha256', 'scan_status'] as $k) {
                    unset($r[$k]);
                }
            }
            unset($r);
        }

        return $rows;
    }

    /** @param array<string,mixed> $d @return array<string,mixed>|null */
    public static function findVersion(array $d, string $publicId): ?array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $publicId)) {
            return null;
        }

        return Database::one(
            'SELECT v.*, f.original_name, f.size_bytes, f.mime FROM deliverable_versions v LEFT JOIN stored_files f ON f.id = v.file_id WHERE v.public_id = ? AND v.deliverable_id = ?',
            [$publicId, $d['id']],
        );
    }
}
