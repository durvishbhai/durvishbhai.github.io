<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Config;
use Vertex\Core\Database;
use Vertex\Core\HttpException;
use Vertex\Core\Logger;

/**
 * Row-level access to tickets (bug reports and support tickets).
 *
 *  - CLIENT: the ticket belongs to the user's own client record (`tickets.view_own`). Drafts are visible only to the person who wrote them.
 *  - STAFF: `tickets.view_all` (every ticket), or `tickets.view_assigned` for tickets assigned to the user and tickets on projects the
 *    user is assigned to. Staff never see drafts.
 *  - WORKER: `tickets.view_technical` for TECHNICAL categories only (bug reports, technical support, project queries, domain/hosting)
 *    on projects the user is assigned to. A worker never sees billing, payment or account-access tickets and never replies to clients.
 *  - Everyone else (referral partners, finance staff, other clients, unassigned staff) gets 404.
 */
final class TicketAccess
{
    private const SELECT = 'SELECT t.*, c.public_id AS client_public_id, c.contact_name AS client_name, c.company_name, c.user_id AS client_user_id,
                                   p.public_id AS project_public_id, p.code AS project_code, p.title AS project_title, p.status AS project_status, p.archived_at AS project_archived_at, p.service_category AS project_service,
                                   au.name AS assignee_name, au.public_id AS assignee_public_id, ru.name AS requester_name,
                                   b.affected_url, b.steps_to_reproduce, b.expected_behaviour, b.actual_behaviour, b.browser_details, b.device_details, b.captured_user_agent,
                                   b.warranty_snapshot_json, b.eligibility, b.eligibility_reason, b.eligibility_terms, b.considerations_json, b.quotation_required,
                                   b.eligibility_reviewer_id, b.eligibility_reviewed_at, b.eligibility_overridden
                              FROM tickets t
                              JOIN clients c ON c.id = t.client_id
                              LEFT JOIN projects p ON p.id = t.project_id
                              LEFT JOIN users au ON au.id = t.assigned_to
                              LEFT JOIN users ru ON ru.id = t.requester_id
                              LEFT JOIN bug_reports b ON b.ticket_id = t.id';

    /** @return array<string,mixed>|null */
    public static function find(string $publicId): ?array
    {
        return preg_match('/^[a-f0-9]{32}$/', $publicId) ? Database::one(self::SELECT . ' WHERE t.public_id = ?', [$publicId]) : null;
    }

    /** @return array<string,mixed>|null */
    public static function findById(int $id): ?array
    {
        return Database::one(self::SELECT . ' WHERE t.id = ?', [$id]);
    }

    /** @param array<string,mixed> $t @return 'client'|'staff'|'worker'|null */
    public static function role(int $userId, array $t): ?string
    {
        if (Database::one("SELECT 1 AS x FROM users WHERE id = ? AND status = 'active'", [$userId]) === null) {
            return null;
        }
        if ($t['client_user_id'] !== null && (int) $t['client_user_id'] === $userId && Gate::can('tickets.view_own', $userId)) {
            return $t['status'] !== 'draft' || (int) $t['requester_id'] === $userId ? 'client' : null;
        }
        if ($t['status'] === 'draft') {
            return null;
        }
        if (Gate::can('tickets.view_all', $userId)) {
            return 'staff';
        }
        if (Gate::can('tickets.view_assigned', $userId) && ($t['assigned_to'] !== null && (int) $t['assigned_to'] === $userId || self::onProject($userId, $t))) {
            return 'staff';
        }
        if (Gate::can('tickets.view_technical', $userId) && self::onProject($userId, $t) && in_array($t['category'], (array) Config::get('workflow.ticket_technical_categories'), true)) {
            return 'worker';
        }

        return null;
    }

    /**
     * May this person be given the ticket? They must be able to work tickets: either they already can open it (staff on the project,
     * a worker on its project for a technical category) or they hold `tickets.view_assigned`, which the assignment itself satisfies.
     *
     * @param array<string,mixed> $t
     */
    public static function canBeAssigned(int $userId, array $t): bool
    {
        if (Database::one("SELECT 1 AS x FROM users WHERE id = ? AND status = 'active'", [$userId]) === null || !Gate::canAny(['tickets.reply', 'tickets.note', 'bugs.fix', 'tickets.update_status'], $userId)) {
            return false;
        }
        if ($t['client_user_id'] !== null && (int) $t['client_user_id'] === $userId) {
            return false;
        }

        return in_array(self::role($userId, $t), ['staff', 'worker'], true) || Gate::can('tickets.view_assigned', $userId);
    }

    /** @param array<string,mixed> $t */
    private static function onProject(int $userId, array $t): bool
    {
        return $t['project_id'] !== null && ProjectPolicy::isAssigned($userId, (int) $t['project_id']);
    }

    /** @param array<string,mixed> $t */
    public static function canView(int $userId, array $t): bool
    {
        return self::role($userId, $t) !== null;
    }

    /** Staff-side permission (workers hold only the few ticket permissions they were given). @param array<string,mixed> $t */
    public static function can(int $userId, array $t, string $permission): bool
    {
        return in_array(self::role($userId, $t), ['staff', 'worker'], true) && Gate::can($permission, $userId);
    }

    /** @param array<string,mixed> $t */
    public static function authorize(int $userId, array $t, string $permission): void
    {
        if (!self::can($userId, $t, $permission)) {
            Logger::security('Ticket ability denied', ['user' => $userId, 'ticket' => (int) $t['id'], 'permission' => $permission]);
            throw new HttpException(403);
        }
    }

    /** @return array<string,mixed> */
    public static function requireView(int $userId, string $publicId): array
    {
        $t = self::find($publicId);
        if ($t === null || !self::canView($userId, $t)) {
            if ($t !== null) {
                Logger::security('Ticket access denied', ['user' => $userId, 'ticket' => (int) $t['id']]);
            }
            throw new HttpException(404);
        }

        return $t;
    }

    /**
     * SQL restriction over `tickets t JOIN clients c` (aliases t, c; projects aliased p when joined). Same rules as role().
     *
     * @return array{0:string,1:list<mixed>}|null
     */
    public static function scopeSql(int $userId): ?array
    {
        if (Database::one("SELECT 1 AS x FROM users WHERE id = ? AND status = 'active'", [$userId]) === null) {
            return null;
        }
        $or = [];
        $params = [];
        if (Gate::can('tickets.view_own', $userId)) {
            $or[] = "(c.user_id = ? AND (t.status <> 'draft' OR t.requester_id = ?))";
            array_push($params, $userId, $userId);
        }
        $assigned = 't.project_id IN (SELECT pa.project_id FROM project_assignments pa WHERE pa.user_id = ? AND pa.revoked_at IS NULL)';
        if (Gate::can('tickets.view_all', $userId)) {
            $or[] = "t.status <> 'draft'";
        } else {
            if (Gate::can('tickets.view_assigned', $userId)) {
                $or[] = "(t.status <> 'draft' AND (t.assigned_to = ? OR {$assigned}))";
                array_push($params, $userId, $userId);
            }
            if (Gate::can('tickets.view_technical', $userId)) {
                $cats = implode(',', array_map(static fn (string $c): string => "'" . $c . "'", array_keys(array_flip((array) Config::get('workflow.ticket_technical_categories')))));
                $or[] = "(t.status <> 'draft' AND t.category IN ({$cats}) AND {$assigned})";
                $params[] = $userId;
            }
        }

        return $or === [] ? null : ['(' . implode(' OR ', $or) . ')', $params];
    }

    /**
     * The ticket as the CLIENT may hold it. Internal assignment, priority, escalation, tokens and reviewer identity are removed
     * (at the data layer, not hidden in a template).
     *
     * @param array<string,mixed> $t
     * @return array<string,mixed>
     */
    public static function forClient(array $t): array
    {
        foreach (['assigned_to', 'assignee_name', 'assignee_public_id', 'priority', 'escalated_at', 'escalated_by', 'escalation_reason', 'submit_token', 'requester_id', 'requester_name',
            'client_user_id', 'eligibility_reviewer_id', 'eligibility_overridden', 'considerations_json', 'captured_user_agent', 'first_staff_response_at', 'client_id'] as $k) {
            unset($t[$k]);
        }

        return $t;
    }

    /** Label of a status for this viewer. @param array<string,mixed> $t */
    public static function statusLabel(array $t, ?string $status = null): string
    {
        $s = $status ?? (string) $t['status'];
        $key = $t['kind'] === 'bug' ? 'workflow.bug_statuses.' : 'workflow.support_statuses.';

        return (string) Config::get($key . $s, $s);
    }
}
