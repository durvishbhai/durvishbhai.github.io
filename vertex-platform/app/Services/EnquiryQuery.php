<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Database;

/** Read-side queries for the CRM screens. Every query is restricted by EnquiryPolicy::scopeSql(). */
final class EnquiryQuery
{
    /**
     * @param array{q?:string,stage?:string,owner?:string,source?:string,priority?:string,service?:string,followup?:string,archived?:bool} $f
     * @return array{rows:list<array<string,mixed>>,total:int}
     */
    public static function paginate(int $userId, array $f, int $page, int $perPage): array
    {
        $scope = EnquiryPolicy::scopeSql($userId);
        if ($scope === null) {
            return ['rows' => [], 'total' => 0];
        }
        [$sql, $params] = $scope;
        $where = [$sql];
        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
            $digits = FieldRules::phoneDigits($q);
            $where[] = '(e.reference LIKE ? OR e.contact_name LIKE ? OR e.email LIKE ? OR e.company_name LIKE ?' . ($digits !== null ? ' OR e.phone_digits = ?' : '') . ')';
            array_push($params, $like, $like, $like, $like);
            if ($digits !== null) {
                $params[] = $digits;
            }
        }
        foreach (['stage' => 's.code', 'source' => 'e.source', 'priority' => 'e.priority', 'service' => 'e.service'] as $k => $col) {
            if (($f[$k] ?? '') !== '') {
                $where[] = "{$col} = ?";
                $params[] = $f[$k];
            }
        }
        $owner = (string) ($f['owner'] ?? '');
        if ($owner === 'none') {
            $where[] = 'e.owner_id IS NULL';
        } elseif ($owner === 'me') {
            $where[] = 'e.owner_id = ?';
            $params[] = $userId;
        } elseif (preg_match('/^[a-f0-9]{32}$/', $owner)) {
            $where[] = 'o.public_id = ?';
            $params[] = $owner;
        }
        if (($f['followup'] ?? '') === 'overdue') {
            $where[] = "e.next_followup_date < ? AND s.kind = 'open'";
            $params[] = AppTime::today();
        } elseif (($f['followup'] ?? '') === 'due') {
            $where[] = "e.next_followup_date <= ? AND s.kind = 'open'";
            $params[] = AppTime::today();
        }
        $where[] = !empty($f['archived']) ? 'e.archived_at IS NOT NULL' : 'e.archived_at IS NULL';
        $w = implode(' AND ', $where);
        $from = 'FROM enquiries e JOIN crm_stages s ON s.id = e.stage_id LEFT JOIN users o ON o.id = e.owner_id';
        $total = (int) Database::one("SELECT COUNT(*) AS c {$from} WHERE {$w}", $params)['c'];
        $offset = max(0, ($page - 1) * $perPage);
        $rows = Database::all(
            "SELECT e.public_id, e.reference, e.contact_name, e.company_name, e.email, e.service, e.source, e.priority, e.next_followup_date, e.created_at, e.duplicate_status,
                    e.converted_project_id, e.archived_at, s.name AS stage_name, s.code AS stage_code, s.kind AS stage_kind, o.name AS owner_name
               {$from} WHERE {$w} ORDER BY e.created_at DESC, e.id DESC LIMIT {$perPage} OFFSET {$offset}",
            $params,
        );

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * @return array{stages:list<array<string,mixed>>,overdue:list<array<string,mixed>>,today:list<array<string,mixed>>,upcoming:list<array<string,mixed>>,unassigned:list<array<string,mixed>>,recent:list<array<string,mixed>>,total:int}
     */
    public static function dashboard(int $userId): array
    {
        $empty = ['stages' => [], 'overdue' => [], 'today' => [], 'upcoming' => [], 'unassigned' => [], 'recent' => [], 'total' => 0];
        $scope = EnquiryPolicy::scopeSql($userId);
        if ($scope === null) {
            return $empty;
        }
        [$sql, $params] = $scope;
        $stages = Database::all(
            "SELECT s.id, s.code, s.name, s.kind, s.position, COUNT(e.id) AS n
               FROM crm_stages s LEFT JOIN enquiries e ON e.stage_id = s.id AND e.archived_at IS NULL AND ({$sql})
              WHERE s.is_active = 1 GROUP BY s.id ORDER BY s.position, s.id",
            $params,
        );
        $today = AppTime::today();
        $due = static function (string $cond, array $extra) use ($sql, $params): array {
            return Database::all(
                "SELECT e.public_id, e.reference, e.contact_name, e.company_name, e.next_followup_date, s.name AS stage_name, f.note AS followup_note
                   FROM enquiries e JOIN crm_stages s ON s.id = e.stage_id
                   JOIN enquiry_followups f ON f.enquiry_id = e.id AND f.completed_at IS NULL AND {$cond}
                  WHERE ({$sql}) AND e.archived_at IS NULL AND s.kind = 'open' ORDER BY f.due_date, e.id LIMIT 15",
                array_merge($extra, $params),
            );
        };
        $out = [
            'stages' => $stages,
            'overdue' => $due('f.due_date < ?', [$today]),
            'today' => $due('f.due_date = ?', [$today]),
            'upcoming' => $due('f.due_date > ? AND f.due_date <= ?', [$today, AppTime::todayPlus(7)]),
            'unassigned' => [],
            'recent' => Database::all(
                "SELECT e.public_id, e.reference, e.contact_name, e.company_name, e.created_at, s.name AS stage_name
                   FROM enquiries e JOIN crm_stages s ON s.id = e.stage_id WHERE ({$sql}) AND e.archived_at IS NULL ORDER BY e.id DESC LIMIT 8",
                $params,
            ),
            'total' => (int) Database::one("SELECT COUNT(*) AS c FROM enquiries e WHERE ({$sql}) AND e.archived_at IS NULL", $params)['c'],
        ];
        if (Gate::can('enquiries.view_all', $userId)) {
            $out['unassigned'] = Database::all(
                "SELECT e.public_id, e.reference, e.contact_name, e.company_name, e.created_at, e.source
                   FROM enquiries e JOIN crm_stages s ON s.id = e.stage_id
                  WHERE e.owner_id IS NULL AND e.archived_at IS NULL AND s.kind = 'open' ORDER BY e.id DESC LIMIT 10",
            );
        }

        return $out;
    }
}
