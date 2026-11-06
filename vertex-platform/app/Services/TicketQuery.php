<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Config;
use Vertex\Core\Database;

/**
 * Read side of the Support Centre. Every list is restricted by TicketAccess::scopeSql (the same rules as TicketAccess::role) and the
 * rows are shaped for the viewer here, at the data layer: clients never receive priority, assignee, internal notes or staff identities.
 */
final class TicketQuery
{
    private const BASE = 'FROM tickets t JOIN clients c ON c.id = t.client_id LEFT JOIN projects p ON p.id = t.project_id
                          LEFT JOIN users au ON au.id = t.assigned_to LEFT JOIN bug_reports b ON b.ticket_id = t.id
                          LEFT JOIN ticket_reads tr ON tr.ticket_id = t.id AND tr.user_id = :reader';
    private const COLS = 'SELECT t.id, t.public_id, t.reference, t.kind, t.category, t.subject, t.status, t.severity, t.priority, t.created_at, t.updated_at, t.submitted_at, t.assigned_to, t.escalated_at, t.reopen_count,
                                 c.contact_name AS client_name, c.company_name, p.public_id AS project_public_id, p.code AS project_code, p.title AS project_title, au.name AS assignee_name, b.eligibility,
                                 (SELECT COUNT(*) FROM ticket_messages m WHERE m.ticket_id = t.id AND m.author_role <> :side AND m.id > COALESCE(tr.last_read_message_id, 0)) AS unread';

    private const RESOLVED = "('resolved','closed','rejected','unable_to_reproduce')";

    /** True when the viewer works tickets (sees internal fields); false for clients. */
    public static function staffView(int $userId): bool
    {
        return Gate::canAny(['tickets.view_all', 'tickets.view_assigned', 'tickets.view_technical'], $userId);
    }

    /**
     * @param array{q?:string,kind?:string,status?:string,category?:string,severity?:string,priority?:string,project?:string,assignee?:string,group?:string,from?:string,to?:string,escalated?:bool} $f
     * @return array{rows:list<array<string,mixed>>,total:int}
     */
    public static function paginate(int $userId, array $f, int $page = 1, int $perPage = 20): array
    {
        $scope = TicketAccess::scopeSql($userId);
        if ($scope === null) {
            return ['rows' => [], 'total' => 0];
        }
        $staff = self::staffView($userId);
        [$where, $params] = self::where($userId, $scope, $f, $staff);
        $total = (int) Database::one('SELECT COUNT(*) AS c ' . self::BASE . " WHERE {$where}", $params['count'])['c'];
        $offset = (int) min(max(0, ($page - 1) * $perPage), 2_000_000_000);
        $rows = Database::all(
            self::COLS . ' ' . self::BASE . " WHERE {$where} ORDER BY (t.status IN " . self::RESOLVED . '), t.escalated_at IS NULL, ' . ($staff ? "FIELD(COALESCE(t.severity, 'normal'), 'critical','high','normal','low')," : '') . " t.updated_at DESC, t.id DESC LIMIT {$perPage} OFFSET {$offset}",
            $params['rows'],
        );

        return ['rows' => array_map(fn (array $r): array => self::shape($r, $staff), $rows), 'total' => $total];
    }

    /**
     * @param array{0:string,1:list<mixed>} $scope
     * @param array<string,mixed> $f
     * @return array{0:string,1:array{count:array<string,mixed>,rows:array<string,mixed>}}
     */
    private static function where(int $userId, array $scope, array $f, bool $staff): array
    {
        // scopeSql uses positional placeholders: renumber them as named ones so they never mix with the named filter parameters.
        $i = 0;
        $named = [];
        $sql = preg_replace_callback('/\?/', static function () use (&$i, $scope, &$named): string {
            $k = 's' . $i;
            $named[$k] = $scope[1][$i];
            $i++;

            return ':' . $k;
        }, $scope[0]);
        $w = [(string) $sql];
        $p = $named;
        $set = static function (string $k, mixed $v) use (&$p): string {
            $p[$k] = $v;

            return ':' . $k;
        };
        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
            $a = $set('q1', $like);
            $b = $set('q2', $like);
            $cc = $set('q3', $like);
            $w[] = "(t.reference LIKE {$a} OR t.subject LIKE {$b} OR p.title LIKE {$cc}" . ($staff ? ' OR ' . 'c.contact_name LIKE ' . $set('q4', $like) . ' OR c.company_name LIKE ' . $set('q5', $like) : '') . ')';
        }
        foreach (['kind' => ['t.kind', ['bug', 'support']], 'category' => ['t.category', array_keys((array) Config::get('workflow.ticket_categories'))], 'severity' => ['t.severity', ['low', 'normal', 'high', 'critical']]] as $key => [$col, $allowed]) {
            if (($f[$key] ?? '') !== '' && in_array($f[$key], $allowed, true)) {
                $w[] = "{$col} = " . $set('f_' . $key, $f[$key]);
            }
        }
        if (($f['status'] ?? '') !== '' && array_key_exists((string) $f['status'], array_merge((array) Config::get('workflow.bug_statuses'), (array) Config::get('workflow.support_statuses')))) {
            $w[] = 't.status = ' . $set('f_status', $f['status']);
        }
        if (($f['project'] ?? '') !== '' && preg_match('/^[a-f0-9]{32}$/', (string) $f['project'])) {
            $w[] = 'p.public_id = ' . $set('f_project', $f['project']);
        }
        if ($staff) {
            if (($f['priority'] ?? '') !== '' && in_array($f['priority'], ['low', 'normal', 'high', 'urgent'], true)) {
                $w[] = 't.priority = ' . $set('f_priority', $f['priority']);
            }
            if (($f['assignee'] ?? '') === 'me') {
                $w[] = 't.assigned_to = ' . $set('f_me', $userId);
            } elseif (($f['assignee'] ?? '') === 'none') {
                $w[] = 't.assigned_to IS NULL';
            } elseif (preg_match('/^[a-f0-9]{32}$/', (string) ($f['assignee'] ?? ''))) {
                $w[] = 'au.public_id = ' . $set('f_assignee', $f['assignee']);
            }
            if (!empty($f['escalated'])) {
                $w[] = 't.escalated_at IS NOT NULL';
            }
        }
        // dates are calendar days in the application time zone, compared against UTC timestamps
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($f['from'] ?? ''))) {
            $w[] = 't.created_at >= ' . $set('f_from', self::utcStart((string) $f['from']));
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($f['to'] ?? ''))) {
            $w[] = 't.created_at < ' . $set('f_to', self::utcStart(date('Y-m-d', strtotime($f['to'] . ' +1 day'))));
        }
        $w[] = match ($f['group'] ?? '') {
            'open' => "t.status NOT IN ('draft','resolved','closed','rejected','unable_to_reproduce')",
            'resolved' => "t.status IN ('resolved','closed','rejected','unable_to_reproduce')",
            'draft' => "t.status = 'draft'",
            'unassigned' => "t.assigned_to IS NULL AND t.status NOT IN ('draft','resolved','closed','rejected','unable_to_reproduce')",
            'high_critical' => "(t.severity IN ('high','critical') OR t.priority IN ('high','urgent')) AND t.status NOT IN ('draft','resolved','closed','rejected','unable_to_reproduce')",
            'awaiting_client' => "t.status IN ('awaiting_client_response','need_more_information')",
            'awaiting_internal' => "t.status = 'awaiting_internal_team'",
            'fixed_verification' => "t.status IN ('fixed','client_verification')",
            'overdue' => self::overdueSql($set),
            default => '1=1',
        };
        $rows = $p + ['reader' => $userId, 'side' => $staff ? 'staff' : 'client'];

        return [implode(' AND ', $w), ['count' => $p + ['reader' => $userId], 'rows' => $rows]];
    }

    /** Overdue exists only when a target is configured (config/support.php `sla`); with none configured the group matches nothing. */
    private static function overdueSql(callable $set): string
    {
        $h = (int) Config::get('support.sla.first_response_hours', 0);
        if ($h < 1) {
            return '1=0';
        }

        return "t.first_staff_response_at IS NULL AND t.submitted_at IS NOT NULL AND t.status NOT IN ('draft','resolved','closed','rejected','unable_to_reproduce') AND t.submitted_at < " . $set('sla_cut', gmdate('Y-m-d H:i:s', time() - $h * 3600));
    }

    private static function utcStart(string $day): string
    {
        return (new \DateTimeImmutable($day . ' 00:00:00', AppTime::zone()))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /** @param array<string,mixed> $r @return array<string,mixed> */
    private static function shape(array $r, bool $staff): array
    {
        unset($r['id']);
        if ($staff) {
            return $r;
        }
        foreach (['priority', 'assigned_to', 'assignee_name', 'escalated_at', 'client_name', 'company_name', 'eligibility'] as $k) {
            unset($r[$k]);
        }

        return $r;
    }

    /**
     * Dashboard figures for the people who work the queue. Counts are computed under the same visibility scope as the list.
     *
     * @return array<string,int|null> overdue is null when no target is configured
     */
    public static function dashboard(int $userId): array
    {
        $out = [];
        foreach (['open', 'unassigned', 'high_critical', 'awaiting_client', 'awaiting_internal', 'fixed_verification'] as $g) {
            $out[$g] = self::paginate($userId, ['group' => $g], 1, 1)['total'];
        }
        $out['escalated'] = self::paginate($userId, ['group' => 'open', 'escalated' => true], 1, 1)['total'];
        $out['overdue'] = (int) Config::get('support.sla.first_response_hours', 0) >= 1 ? self::paginate($userId, ['group' => 'overdue'], 1, 1)['total'] : null;

        return $out;
    }

    // -------------------------------------------------------------------------------------------------- one ticket

    /** The client-visible conversation. Staff names are not shown to clients. @param array<string,mixed> $t @return list<array<string,mixed>> */
    public static function conversation(int $userId, array $t): array
    {
        $role = TicketAccess::role($userId, $t);
        if ($role === null) {
            return [];
        }
        $rows = Database::all(
            "SELECT m.id, m.author_role, m.body, m.created_at, u.name AS author_name FROM ticket_messages m LEFT JOIN users u ON u.id = m.author_id WHERE m.ticket_id = ? ORDER BY m.id",
            [$t['id']],
        );
        foreach ($rows as &$r) {
            if ($role === 'client' && $r['author_role'] === 'staff') {
                $r['author_name'] = 'Krazy support team';
            }
            $r['files'] = self::filesOf($role, (int) $t['id'], (int) $r['id']);
        }

        return $rows;
    }

    /** @return list<array<string,mixed>> */
    private static function filesOf(string $role, int $ticketId, ?int $messageId): array
    {
        return Database::all(
            'SELECT f.public_id, f.original_name, f.size_bytes, tf.visibility FROM ticket_files tf JOIN stored_files f ON f.id = tf.file_id
              WHERE tf.ticket_id = ? AND ' . ($messageId === null ? 'tf.message_id IS NULL' : 'tf.message_id = ?') . ($role === 'client' ? " AND tf.visibility = 'client'" : '') . ' ORDER BY tf.id',
            $messageId === null ? [$ticketId] : [$ticketId, $messageId],
        );
    }

    /** Files attached at creation (not to a message). @param array<string,mixed> $t @return list<array<string,mixed>> */
    public static function initialFiles(int $userId, array $t): array
    {
        $role = TicketAccess::role($userId, $t);

        return $role === null ? [] : self::filesOf($role, (int) $t['id'], null);
    }

    /**
     * One file the viewer may download, or null: the file must belong to this ticket and be visible to this role (internal files are
     * staff-only). The caller already holds a ticket the viewer may open.
     *
     * @param array<string,mixed> $t @return array<string,mixed>|null
     */
    public static function fileFor(int $userId, array $t, string $filePublicId): ?array
    {
        $role = TicketAccess::role($userId, $t);
        if ($role === null || !preg_match('/^[a-f0-9]{32}$/', $filePublicId)) {
            return null;
        }
        $row = Database::one(
            'SELECT f.id, tf.visibility FROM ticket_files tf JOIN stored_files f ON f.id = tf.file_id WHERE tf.ticket_id = ? AND f.public_id = ?',
            [$t['id'], $filePublicId],
        );
        if ($row === null || ($role === 'client' && $row['visibility'] !== 'client')) {
            return null;
        }

        return FileStore::find((int) $row['id']);
    }

    /** Status history. Clients receive only the rows meant for them. @param array<string,mixed> $t @return list<array<string,mixed>> */
    public static function activity(int $userId, array $t): array
    {
        $role = TicketAccess::role($userId, $t);
        if ($role === null) {
            return [];
        }
        $rows = Database::all(
            'SELECT a.event, a.from_value, a.to_value, a.note, a.visibility, a.created_at, a.actor_id, u.name AS actor_name FROM ticket_activity a LEFT JOIN users u ON u.id = a.actor_id
              WHERE a.ticket_id = ?' . ($role === 'client' ? " AND a.visibility = 'client' AND a.event NOT IN ('message')" : '') . ' ORDER BY a.id',
            [$t['id']],
        );
        if ($role === 'client') {
            foreach ($rows as &$r) {
                $r['actor_name'] = (int) $r['actor_id'] === $userId ? 'You' : 'Krazy support team';
                unset($r['actor_id'], $r['visibility']);
            }
        }

        return $rows;
    }

    /** Internal notes: staff and workers only, never a client. @param array<string,mixed> $t @return list<array<string,mixed>> */
    public static function notes(int $userId, array $t): array
    {
        if (!in_array(TicketAccess::role($userId, $t), ['staff', 'worker'], true) || !Gate::canAny(['tickets.note', 'tickets.reply', 'tickets.update_status', 'bugs.manage'], $userId)) {
            return [];
        }

        return Database::all('SELECT n.kind, n.body, n.created_at, u.name AS author_name FROM ticket_notes n LEFT JOIN users u ON u.id = n.author_id WHERE n.ticket_id = ? ORDER BY n.id', [$t['id']]);
    }

    /** Cost proposals attached to a bug, as the viewer may see them (clients never see drafts). @param array<string,mixed> $t @return list<array<string,mixed>> */
    public static function changes(int $userId, array $t): array
    {
        $role = TicketAccess::role($userId, $t);
        if ($role === null) {
            return [];
        }
        if ($role === 'worker') { // financial detail is for the roles that handle it
            return [];
        }

        return Database::all('SELECT public_id, reference, title, proposed_amount_minor, proposed_days, proposed_due_date, status, sent_at, decided_at FROM change_requests WHERE ticket_id = ?' . ($role === 'client' ? " AND status <> 'draft'" : '') . ' ORDER BY id DESC', [$t['id']]);
    }

    /** Staff who could be assigned: active people who may work on this ticket. @param array<string,mixed> $t @return list<array<string,mixed>> */
    public static function assignable(array $t): array
    {
        $out = [];
        foreach (Database::all("SELECT id, public_id, name FROM users WHERE status = 'active' ORDER BY name LIMIT 500") as $u) {
            if (TicketAccess::canBeAssigned((int) $u['id'], $t)) {
                $out[] = ['public_id' => $u['public_id'], 'name' => $u['name']];
            }
        }

        return $out;
    }

    /** The signed-in client's own projects that can take a bug or ticket. @return list<array<string,mixed>> */
    public static function ownProjects(int $userId): array
    {
        return Database::all(
            "SELECT p.public_id, p.code, p.title FROM projects p JOIN clients c ON c.id = p.client_id WHERE c.user_id = ? AND c.status = 'active' AND p.archived_at IS NULL AND p.status <> 'cancelled' ORDER BY p.created_at DESC",
            [$userId],
        );
    }

    public static function unreadForClient(int $userId): int
    {
        return (int) Database::one(
            "SELECT COUNT(*) AS c FROM ticket_messages m JOIN tickets t ON t.id = m.ticket_id JOIN clients c ON c.id = t.client_id
              LEFT JOIN ticket_reads tr ON tr.ticket_id = t.id AND tr.user_id = ? WHERE c.user_id = ? AND t.status <> 'draft' AND m.author_role = 'staff' AND m.id > COALESCE(tr.last_read_message_id, 0)",
            [$userId, $userId],
        )['c'];
    }
}
