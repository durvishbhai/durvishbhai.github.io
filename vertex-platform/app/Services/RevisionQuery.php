<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Database;

/** Read side of revisions. Staff see requests on projects they can staff; the client sees only their own. */
final class RevisionQuery
{
    private const BASE = 'FROM revision_requests r JOIN projects p ON p.id = r.project_id JOIN clients c ON c.id = p.client_id
                          JOIN deliverables d ON d.id = r.deliverable_id JOIN deliverable_versions v ON v.id = r.version_id
                          LEFT JOIN users au ON au.id = r.assigned_to LEFT JOIN users ru ON ru.id = r.requested_by';
    private const COLS = 'SELECT r.public_id, r.reference, r.status, r.scope_decision, r.created_at, r.updated_at, r.completed_at, r.assigned_to, au.name AS assignee_name, ru.name AS requester_name,
                                 p.public_id AS project_public_id, p.code AS project_code, p.title AS project_title, d.public_id AS deliverable_public_id, d.title AS deliverable_title, v.version_no';

    /** @return array{0:?string,1:list<mixed>} */
    private static function scope(int $userId): array
    {
        $or = [];
        $params = [];
        if (Gate::can('revisions.manage', $userId)) {
            if (Gate::can('projects.view_all', $userId)) {
                $or[] = '1=1';
            } elseif (Gate::can('projects.view_assigned', $userId)) {
                $or[] = '(p.archived_at IS NULL AND p.id IN (SELECT pa.project_id FROM project_assignments pa WHERE pa.user_id = ? AND pa.revoked_at IS NULL))';
                $params[] = $userId;
            }
        }
        if (Gate::can('revisions.request', $userId) && Gate::can('projects.view_own', $userId)) {
            $or[] = '(c.user_id = ? AND r.requested_by = ? AND p.archived_at IS NULL)';
            array_push($params, $userId, $userId);
        }
        if ($or === [] || Database::one("SELECT 1 AS x FROM users WHERE id = ? AND status = 'active'", [$userId]) === null) {
            return [null, []];
        }

        return ['(' . implode(' OR ', $or) . ')', $params];
    }

    /**
     * @param array{q?:string,status?:string,project?:string,mine?:bool,open?:bool,scope?:string} $f
     * @return array{rows:list<array<string,mixed>>,total:int}
     */
    public static function paginate(int $userId, array $f, int $page = 1, int $perPage = 20): array
    {
        [$where, $params] = self::scope($userId);
        if ($where === null) {
            return ['rows' => [], 'total' => 0];
        }
        $w = [$where];
        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            $w[] = '(r.reference LIKE ? OR d.title LIKE ? OR p.title LIKE ?)';
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
            array_push($params, $like, $like, $like);
        }
        if (($f['status'] ?? '') !== '') {
            $w[] = 'r.status = ?';
            $params[] = $f['status'];
        }
        if (($f['project'] ?? '') !== '') {
            $w[] = 'p.public_id = ?';
            $params[] = $f['project'];
        }
        if (!empty($f['mine'])) {
            $w[] = 'r.assigned_to = ?';
            $params[] = $userId;
        }
        if (!empty($f['open'])) {
            $w[] = "r.status NOT IN ('resolved','rejected','cancelled')";
        }
        $cond = implode(' AND ', $w);
        $total = (int) Database::one('SELECT COUNT(*) AS c ' . self::BASE . " WHERE {$cond}", $params)['c'];
        $offset = (int) min(max(0, ($page - 1) * $perPage), 2_000_000_000);
        $rows = Database::all(self::COLS . ' ' . self::BASE . " WHERE {$cond} ORDER BY (r.status IN ('resolved','rejected','cancelled')), r.updated_at DESC, r.id DESC LIMIT {$perPage} OFFSET {$offset}", $params);

        return ['rows' => self::shape($userId, $rows), 'total' => $total];
    }

    /**
     * People who are not staff reviewers get client-shaped rows (see RevisionAccess::forClient).
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private static function shape(int $userId, array $rows): array
    {
        if (Gate::can('revisions.manage', $userId) && Gate::canAny(['projects.view_all', 'projects.view_assigned'], $userId)) {
            return $rows;
        }

        return array_map(static fn (array $r): array => RevisionAccess::forClient($r), $rows);
    }

    /** @param array<string,mixed> $d @return list<array<string,mixed>> */
    public static function forDeliverable(int $userId, array $d): array
    {
        [$where, $params] = self::scope($userId);
        if ($where === null) {
            return [];
        }

        return self::shape($userId, Database::all(self::COLS . ' ' . self::BASE . " WHERE {$where} AND r.deliverable_id = ? ORDER BY r.id DESC LIMIT 100", [...$params, $d['id']]));
    }

    /**
     * Status history. Clients receive only 'client' rows; internal rows never leave the data layer for them.
     *
     * @param array<string,mixed> $r
     * @return list<array<string,mixed>>
     */
    public static function history(int $userId, array $r): array
    {
        $role = RevisionAccess::role($userId, $r);
        if ($role === null) {
            return [];
        }

        return Database::all(
            'SELECT h.from_status, h.to_status, h.note, h.created_at, h.visibility, u.name AS actor_name FROM revision_status_history h LEFT JOIN users u ON u.id = h.actor_id
              WHERE h.revision_id = ?' . ($role === 'client' ? " AND h.visibility = 'client'" : '') . ' ORDER BY h.id',
            [$r['id']],
        );
    }

    /** Internal notes: staff only. @param array<string,mixed> $r @return list<array<string,mixed>> */
    public static function notes(int $userId, array $r): array
    {
        if (RevisionAccess::role($userId, $r) !== 'staff') {
            return [];
        }

        return Database::all('SELECT n.body, n.created_at, u.name FROM revision_notes n LEFT JOIN users u ON u.id = n.author_id WHERE n.revision_id = ? ORDER BY n.id', [$r['id']]);
    }

    /** @param array<string,mixed> $r @return list<array<string,mixed>> */
    public static function files(array $r): array
    {
        return Database::all('SELECT f.public_id, f.original_name, f.size_bytes FROM revision_files rf JOIN stored_files f ON f.id = rf.file_id WHERE rf.revision_id = ? ORDER BY rf.id', [$r['id']]);
    }

    /** Change requests of a revision, as the viewer may see them (clients never see drafts). @param array<string,mixed> $r @return list<array<string,mixed>> */
    public static function changes(int $userId, array $r): array
    {
        $role = RevisionAccess::role($userId, $r);
        if ($role === null) {
            return [];
        }

        return Database::all('SELECT public_id, reference, title, proposed_amount_minor, proposed_days, proposed_due_date, status, sent_at, decided_at FROM change_requests WHERE revision_request_id = ?' . ($role === 'client' ? " AND status <> 'draft'" : '') . ' ORDER BY id DESC', [$r['id']]);
    }

    public static function openCountForStaff(int $userId): int
    {
        return self::paginate($userId, ['open' => true], 1, 1)['total'];
    }
}
