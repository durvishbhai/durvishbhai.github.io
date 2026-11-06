<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Database;

/** Read side of deliverables. Every query starts from DeliverablePolicy::scopeSql(): filters narrow, they never widen. */
final class DeliverableQuery
{
    private const FROM = 'FROM deliverables d JOIN projects p ON p.id = d.project_id JOIN clients c ON c.id = p.client_id';

    /**
     * @param array{q?:string,project?:string,category?:string,status?:string,archived?:bool} $f
     * @return array{rows:list<array<string,mixed>>,total:int}
     */
    public static function paginate(int $userId, array $f, int $page = 1, int $perPage = 20): array
    {
        $scope = DeliverablePolicy::scopeSql($userId);
        if ($scope === null) {
            return ['rows' => [], 'total' => 0];
        }
        [$where, $params] = $scope;
        $w = [$where];
        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            $w[] = '(d.title LIKE ? OR d.code LIKE ? OR p.title LIKE ?)';
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
            array_push($params, $like, $like, $like);
        }
        foreach (['project' => 'p.public_id', 'category' => 'd.category', 'status' => 'd.status'] as $k => $col) {
            if (($f[$k] ?? '') !== '') {
                $w[] = "{$col} = ?";
                $params[] = $f[$k];
            }
        }
        $w[] = !empty($f['archived']) ? 'd.archived_at IS NOT NULL' : 'd.archived_at IS NULL';
        $cond = implode(' AND ', $w);
        $total = (int) Database::one('SELECT COUNT(*) AS c ' . self::FROM . " WHERE {$cond}", $params)['c'];
        $offset = (int) min(max(0, ($page - 1) * $perPage), 2_000_000_000);
        $rows = Database::all(
            'SELECT d.id, d.public_id, d.code, d.title, d.category, d.status, d.first_published_at, d.archived_at, d.created_at, d.updated_at, d.published_version_id,
                    p.public_id AS project_public_id, p.code AS project_code, p.title AS project_title,
                    (SELECT MAX(v.version_no) FROM deliverable_versions v WHERE v.deliverable_id = d.id) AS latest_no,
                    (SELECT v.version_no FROM deliverable_versions v WHERE v.id = d.published_version_id) AS published_no,
                    (SELECT v.published_at FROM deliverable_versions v WHERE v.id = d.published_version_id) AS published_at '
            . self::FROM . " WHERE {$cond} ORDER BY d.updated_at DESC, d.id DESC LIMIT {$perPage} OFFSET {$offset}",
            $params,
        );

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * Internal QA queue: versions waiting for review (needs `deliverables.review`) and approved versions waiting to be published
     * (needs `deliverables.publish`). Staff-level projects only.
     *
     * @return array{review:list<array<string,mixed>>,publish:list<array<string,mixed>>}
     */
    public static function qaQueue(int $userId): array
    {
        $out = ['review' => [], 'publish' => []];
        $scope = DeliverablePolicy::scopeSql($userId);
        if ($scope === null || !Gate::can('deliverables.view_drafts', $userId)) {
            return $out;
        }
        [$where, $params] = $scope;
        // staff only: never pick up the client branch of the scope
        $staffOnly = Gate::can('projects.view_all', $userId) ? '1=1' : 'p.id IN (SELECT pa.project_id FROM project_assignments pa WHERE pa.user_id = ? AND pa.revoked_at IS NULL)';
        $p = Gate::can('projects.view_all', $userId) ? [] : [$userId];
        $cols = 'SELECT d.public_id, d.code, d.title, d.category, p.public_id AS project_public_id, p.code AS project_code, p.title AS project_title,
                        v.public_id AS version_public_id, v.version_no, v.label, v.status, v.submitted_at, v.approved_at, v.uploaded_by, u.name AS uploader_name,
                        f.original_name, f.size_bytes ';
        $from = self::FROM . ' JOIN deliverable_versions v ON v.deliverable_id = d.id LEFT JOIN users u ON u.id = v.uploaded_by LEFT JOIN stored_files f ON f.id = v.file_id';
        $base = "WHERE {$where} AND {$staffOnly} AND d.archived_at IS NULL AND p.archived_at IS NULL";
        if (Gate::can('deliverables.review', $userId)) {
            $out['review'] = Database::all("{$cols} {$from} {$base} AND v.status = 'pending_review' ORDER BY v.submitted_at, v.id LIMIT 100", [...$params, ...$p]);
        }
        if (Gate::can('deliverables.publish', $userId)) {
            $out['publish'] = Database::all("{$cols} {$from} {$base} AND v.status = 'approved_internal' ORDER BY v.approved_at, v.id LIMIT 100", [...$params, ...$p]);
        }

        return $out;
    }

    /**
     * The client dashboard: projects the user owns, each with its client-visible deliverables and their approval state.
     *
     * @return list<array{project:array<string,mixed>,deliverables:list<array<string,mixed>>,open_revisions:int}>
     */
    public static function clientDashboard(int $userId): array
    {
        if (!Gate::can('deliverables.view', $userId) || !Gate::can('projects.view_own', $userId)) {
            return [];
        }
        $projects = Database::all(
            "SELECT p.id, p.public_id, p.code, p.title, p.status FROM projects p JOIN clients c ON c.id = p.client_id
              WHERE c.user_id = ? AND p.archived_at IS NULL ORDER BY p.created_at DESC LIMIT 100",
            [$userId],
        );
        $out = [];
        foreach ($projects as $p) {
            $ds = Database::all(
                'SELECT d.* FROM deliverables d WHERE d.project_id = ? AND d.first_published_at IS NOT NULL ORDER BY d.archived_at IS NOT NULL, d.updated_at DESC, d.id DESC',
                [$p['id']],
            );
            $rows = [];
            foreach ($ds as $d) {
                $state = ClientApprovalService::stateFor($d);
                $rows[] = ['d' => $d, 'state' => $state];
            }
            $open = (int) Database::one("SELECT COUNT(*) AS c FROM revision_requests WHERE project_id = ? AND requested_by = ? AND status NOT IN ('resolved','rejected','cancelled')", [$p['id'], $userId])['c'];
            $out[] = ['project' => $p, 'deliverables' => $rows, 'open_revisions' => $open];
        }

        return $out;
    }

    /** Deliverables of one project the user may see. @param array<string,mixed> $project @return list<array<string,mixed>> */
    public static function forProject(int $userId, array $project, bool $archived = false): array
    {
        $res = self::paginate($userId, ['project' => (string) $project['public_id'], 'archived' => $archived], 1, 200);

        return $res['rows'];
    }
}
