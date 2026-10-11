<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Config;
use Vertex\Core\Database;

/**
 * Read side of tasks: list, Kanban board and the deadline dashboard. EVERY query starts from TaskPolicy::scopeSql() so a
 * user can only ever receive rows from projects they may see; filters narrow, they never widen.
 */
final class TaskQuery
{
    public const BOARD_LIMIT = 400;

    /**
     * @param array{q?:string,project?:string,status?:string,priority?:string,assignee?:string,overdue?:bool,milestone?:string} $f
     * @return array{rows:list<array<string,mixed>>,total:int}
     */
    public static function paginate(int $userId, array $f, int $page = 1, int $perPage = 25): array
    {
        [$where, $params] = self::where($userId, $f);
        if ($where === null) {
            return ['rows' => [], 'total' => 0];
        }
        $total = (int) Database::one('SELECT COUNT(*) AS c ' . self::FROM . " WHERE {$where}", $params)['c'];
        $offset = max(0, ($page - 1) * $perPage);
        $rows = Database::all(
            self::columns() . ' ' . self::FROM . " WHERE {$where} ORDER BY (t.due_date IS NULL), t.due_date, FIELD(t.priority,'urgent','high','normal','low'), t.id LIMIT {$perPage} OFFSET {$offset}",
            $params,
        );

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * Board columns keyed by status (all eight, in workflow order, even when empty).
     *
     * @param array<string,mixed> $f same filters as paginate(); `status` narrows to one column
     * @return array{columns:array<string,list<array<string,mixed>>>,truncated:bool}
     */
    public static function board(int $userId, array $f): array
    {
        $columns = array_fill_keys(array_keys((array) Config::get('workflow.task_statuses')), []);
        [$where, $params] = self::where($userId, $f);
        if ($where === null) {
            return ['columns' => $columns, 'truncated' => false];
        }
        $limit = self::BOARD_LIMIT + 1;
        $rows = Database::all(
            self::columns() . ' ' . self::FROM . " WHERE {$where} ORDER BY FIELD(t.priority,'urgent','high','normal','low'), (t.due_date IS NULL), t.due_date, t.id LIMIT {$limit}",
            $params,
        );
        $truncated = count($rows) > self::BOARD_LIMIT;
        foreach (array_slice($rows, 0, self::BOARD_LIMIT) as $r) {
            $columns[$r['status']][] = $r;
        }

        return ['columns' => $columns, 'truncated' => $truncated];
    }

    /** Filter dropdown data limited to what the user can see. @return list<array<string,mixed>> */
    public static function projectsFor(int $userId): array
    {
        $scope = TaskPolicy::scopeSql($userId);
        if ($scope === null) {
            return [];
        }

        return Database::all(
            "SELECT p.public_id, p.code, p.title FROM projects p WHERE {$scope[0]} AND p.archived_at IS NULL AND p.status NOT IN ('cancelled') ORDER BY p.title LIMIT 300",
            $scope[1],
        );
    }

    /** @return list<array<string,mixed>> people who hold an active assignment on any visible project */
    public static function assigneesFor(int $userId): array
    {
        $scope = TaskPolicy::scopeSql($userId);
        if ($scope === null) {
            return [];
        }

        return Database::all(
            "SELECT DISTINCT u.public_id, u.name FROM users u
               JOIN project_assignments pa ON pa.user_id = u.id AND pa.revoked_at IS NULL
               JOIN projects p ON p.id = pa.project_id
              WHERE {$scope[0]} AND u.status = 'active' ORDER BY u.name LIMIT 300",
            $scope[1],
        );
    }

    /**
     * Deadline dashboard. Dates compare against the APPLICATION date (AppTime); done/cancelled work never counts as overdue.
     *
     * @return array{today:string,horizon:string,overdue_tasks:list<array<string,mixed>>,upcoming_tasks:list<array<string,mixed>>,blocked_tasks:list<array<string,mixed>>,review_tasks:list<array<string,mixed>>,overdue_milestones:list<array<string,mixed>>,upcoming_milestones:list<array<string,mixed>>,projects:list<array<string,mixed>>}
     */
    public static function deadlines(int $userId): array
    {
        $days = (int) Config::get('workflow.due_soon_days', 7);
        $today = AppTime::today();
        $horizon = AppTime::todayPlus($days);
        $out = ['today' => $today, 'horizon' => $horizon, 'overdue_tasks' => [], 'upcoming_tasks' => [], 'blocked_tasks' => [], 'review_tasks' => [],
            'overdue_milestones' => [], 'upcoming_milestones' => [], 'projects' => []];
        $scope = TaskPolicy::scopeSql($userId);
        if ($scope === null) {
            return $out;
        }
        [$sw, $sp] = $scope;
        $open = "t.archived_at IS NULL AND t.status NOT IN ('completed','cancelled') AND p.status NOT IN ('cancelled','completed') AND p.archived_at IS NULL";
        $cols = self::columns();
        $base = self::FROM . " WHERE {$sw} AND {$open}";

        $out['overdue_tasks'] = Database::all("{$cols} {$base} AND t.due_date < ? ORDER BY t.due_date, t.id LIMIT 50", [...$sp, $today]);
        $out['upcoming_tasks'] = Database::all("{$cols} {$base} AND t.due_date >= ? AND t.due_date <= ? ORDER BY t.due_date, t.id LIMIT 50", [...$sp, $today, $horizon]);
        $out['blocked_tasks'] = Database::all("{$cols} {$base} AND t.status = 'blocked' ORDER BY t.updated_at, t.id LIMIT 50", $sp);
        $out['review_tasks'] = Database::all("{$cols} {$base} AND t.status = 'in_review' ORDER BY t.updated_at, t.id LIMIT 50", $sp);

        $mBase = "FROM milestones m JOIN projects p ON p.id = m.project_id WHERE {$sw} AND m.archived_at IS NULL AND m.status IN ('planned','in_progress') AND p.status NOT IN ('cancelled','completed') AND p.archived_at IS NULL AND m.target_date IS NOT NULL";
        $mCols = 'SELECT m.public_id, m.title, m.target_date, m.status, p.public_id AS project_public_id, p.code AS project_code, p.title AS project_title';
        $out['overdue_milestones'] = Database::all("{$mCols} {$mBase} AND m.target_date < ? ORDER BY m.target_date LIMIT 50", [...$sp, $today]);
        $out['upcoming_milestones'] = Database::all("{$mCols} {$mBase} AND m.target_date >= ? AND m.target_date <= ? ORDER BY m.target_date LIMIT 50", [...$sp, $today, $horizon]);

        $out['projects'] = Database::all(
            "SELECT p.public_id, p.code, p.title, p.status, p.target_date, (p.target_date < ?) AS is_overdue
               FROM projects p WHERE {$sw} AND p.archived_at IS NULL AND p.target_date IS NOT NULL AND p.target_date <= ?
                AND p.status NOT IN ('completed','cancelled','draft') ORDER BY p.target_date LIMIT 50",
            [$today, ...$sp, $horizon],
        );

        return $out;
    }

    private const FROM = 'FROM tasks t JOIN projects p ON p.id = t.project_id LEFT JOIN users a ON a.id = t.assignee_id';

    private static function columns(): string
    {
        return "SELECT t.id, t.public_id, t.title, t.status, t.priority, t.due_date, t.start_date, t.review_status, t.estimate_minutes, t.assignee_id, t.review_required, t.archived_at,
                       p.status AS project_status, p.archived_at AS project_archived_at, p.public_id AS project_public_id, p.code AS project_code, p.title AS project_title,
                       a.public_id AS assignee_public_id, a.name AS assignee_name,
                       (t.due_date IS NOT NULL AND t.due_date < '" . AppTime::today() . "' AND t.status NOT IN ('completed','cancelled')) AS is_overdue,
                       (SELECT COUNT(*) FROM task_dependencies td JOIN tasks d ON d.id = td.depends_on_task_id
                         WHERE td.task_id = t.id AND d.archived_at IS NULL AND d.status <> 'completed') AS open_deps,
                       (SELECT COALESCE(SUM(te.minutes), 0) FROM task_time_entries te WHERE te.task_id = t.id) AS logged_minutes";
    }

    /** @param array<string,mixed> $f @return array{0:?string,1:list<mixed>} */
    private static function where(int $userId, array $f): array
    {
        $scope = TaskPolicy::scopeSql($userId);
        if ($scope === null) {
            return [null, []];
        }
        [$sw, $params] = $scope;
        $w = [$sw, 't.archived_at IS NULL', 'p.archived_at IS NULL'];
        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            $w[] = '(t.title LIKE ? OR p.code LIKE ?)';
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
            array_push($params, $like, $like);
        }
        foreach (['project' => 'p.public_id', 'status' => 't.status', 'priority' => 't.priority'] as $k => $col) {
            if (($f[$k] ?? '') !== '') {
                $w[] = "{$col} = ?";
                $params[] = $f[$k];
            }
        }
        if (($f['assignee'] ?? '') === 'me') {
            $w[] = 't.assignee_id = ?';
            $params[] = $userId;
        } elseif (($f['assignee'] ?? '') === 'none') {
            $w[] = 't.assignee_id IS NULL';
        } elseif (($f['assignee'] ?? '') !== '') {
            $w[] = 'a.public_id = ?';
            $params[] = $f['assignee'];
        }
        if (!empty($f['overdue'])) {
            $w[] = "t.due_date < ? AND t.status NOT IN ('completed','cancelled')";
            $params[] = AppTime::today();
        }

        return [implode(' AND ', $w), $params];
    }
}
