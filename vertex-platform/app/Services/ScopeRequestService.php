<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Database;
use Vertex\Core\HttpException;
use Vertex\Core\ValidationException;

/**
 * Module 33: client scope-change requests. A client describes extra work they want on their own project; staff assess the cost and the effect on the
 * deadline and then turn the request into a normal change proposal (which the client accepts or declines with the existing approval flow, with the original
 * price kept as the baseline). Nothing is charged and no deadline moves until the client approves that proposal.
 */
final class ScopeRequestService
{
    /** @return array<string,mixed>|null */
    public static function find(string $publicId): ?array
    {
        return preg_match('/^[a-f0-9]{32}$/', $publicId) ? Database::one(
            'SELECT s.*, p.public_id AS project_public_id, p.code AS project_code, p.title AS project_title, u.name AS requester_name, a.name AS assessor_name, cr.public_id AS change_public_id, cr.reference AS change_reference, cr.status AS change_status
               FROM scope_requests s JOIN projects p ON p.id = s.project_id JOIN users u ON u.id = s.requested_by LEFT JOIN users a ON a.id = s.assessed_by LEFT JOIN change_requests cr ON cr.id = s.change_request_id WHERE s.public_id = ?',
            [$publicId],
        ) : null;
    }

    /** @return array<string,mixed> staff view: needs access to the project */
    public static function requireStaff(int $userId, string $publicId): array
    {
        $s = Gate::can('scope.assess', $userId) ? self::find($publicId) : null;
        $project = $s === null ? null : ProjectPolicy::findById((int) $s['project_id']);
        if ($s === null || $project === null || !in_array(ProjectPolicy::level($userId, $project), ['all', 'assigned'], true)) {
            throw new HttpException(404);
        }

        return $s;
    }

    /** @return array<string,mixed> the client's own request */
    public static function requireOwn(int $userId, string $publicId): array
    {
        $s = Gate::can('scope.request', $userId) ? self::find($publicId) : null;
        if ($s === null || (int) $s['requested_by'] !== $userId) {
            throw new HttpException(404);
        }

        return $s;
    }

    /** @param array<string,mixed> $project a project the client owns */
    public static function submit(int $userId, array $project, string $description, string $wantedBy, string $submitToken = ''): array
    {
        Gate::authorize('scope.request', $userId);
        if (ProjectPolicy::level($userId, $project) !== 'own') {
            throw new HttpException(404);
        }
        if (in_array($project['status'], ['completed', 'cancelled'], true) || $project['archived_at'] !== null) {
            throw new ValidationException(['This project is closed. Please open a new enquiry for further work.']);
        }
        if (!RateLimiter::hit('scope_request_user', (string) $userId)) {
            throw new HttpException(429);
        }
        $description = (string) FieldRules::text($description, 'What would you like added or changed?', 15, 3000);
        $wanted = FieldRules::date($wantedBy, 'Wanted by', false, AppTime::today());
        if ($submitToken !== '' && preg_match('/^[a-f0-9]{32}$/', $submitToken)) {
            $dup = Database::one('SELECT public_id, reference FROM scope_requests WHERE submit_token = ?', [$submitToken]);
            if ($dup !== null) {
                return ['public_id' => $dup['public_id'], 'reference' => $dup['reference']];
            }
        }

        return Database::transaction(function () use ($userId, $project, $description, $wanted, $submitToken): array {
            $pub = uuid_hex();
            $ref = CrmReference::next('scope_request');
            Database::run("INSERT INTO scope_requests (public_id, reference, project_id, requested_by, description, wanted_by, status, submit_token, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, 'submitted', ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())",
                [$pub, $ref, $project['id'], $userId, $description, $wanted, $submitToken === '' ? null : $submitToken]);
            $id = Database::insertId();
            ProjectActivity::log((int) $project['id'], $userId, 'scope.requested', 'The client asked for a scope change (' . $ref . ').', 'internal', 'scope_request', $id);
            Audit::record('scope_request.submitted', 'scope_request', $id, [], ['reference' => $ref], ['project_id' => (int) $project['id']], $userId);
            Notifier::send(Notifier::staffWith('scope.assess', (int) $project['id']), 'scope.requested', 'Scope change requested: ' . $project['code'], $ref, '/scope-requests/' . $pub, (int) $project['id'], 'scope_request', $id, 'scope:' . $id . ':new');

            return ['public_id' => $pub, 'reference' => $ref];
        });
    }

    public static function withdraw(int $userId, array $s): void
    {
        if (!in_array($s['status'], ['submitted', 'assessing'], true)) {
            throw new ValidationException(['Only a request that has not been quoted yet can be withdrawn.']);
        }
        Database::run("UPDATE scope_requests SET status = 'withdrawn', updated_at = UTC_TIMESTAMP() WHERE id = ? AND status IN ('submitted','assessing')", [$s['id']]);
        Audit::record('scope_request.withdrawn', 'scope_request', (int) $s['id'], [], [], [], $userId);
    }

    /** Saves the assessment (cost and deadline effect) without sending anything to the client. */
    public static function assess(int $actorId, array $s, string $amount, string $days, string $note): void
    {
        self::requireStaff($actorId, (string) $s['public_id']);
        if (!in_array($s['status'], ['submitted', 'assessing'], true)) {
            throw new ValidationException(['This request is already answered.']);
        }
        $minor = FieldRules::moneyMinor($amount, 'Estimated extra cost', true);
        $d = trim($days) === '' ? null : (ctype_digit(trim($days)) && (int) $days <= 3650 ? (int) $days : throw new ValidationException(['Extra days must be a whole number.']));
        $note = (string) (FieldRules::text($note, 'Assessment note', 5, 1000, false) ?? '');
        Database::run("UPDATE scope_requests SET status = 'assessing', est_amount_minor = ?, est_days = ?, assessment_note = ?, assessed_by = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?", [$minor, $d, $note === '' ? null : $note, $actorId, $s['id']]);
        Audit::record('scope_request.assessed', 'scope_request', (int) $s['id'], [], ['est_amount_minor' => $minor, 'est_days' => $d], [], $actorId);
    }

    /** Turns the assessed request into a change proposal for the client to approve. @return string public id of the change request */
    public static function convert(int $actorId, array $s, string $scope, string $reason): string
    {
        self::requireStaff($actorId, (string) $s['public_id']);
        Gate::authorize('revisions.classify', $actorId); // the same people who may propose extra cost on a revision
        if ($s['status'] !== 'assessing' || $s['est_amount_minor'] === null) {
            throw new ValidationException(['Save the assessment (cost and days) first.']);
        }
        $project = ProjectPolicy::findById((int) $s['project_id']) ?? throw new HttpException(404);
        $cr = ChangeRequestService::validateProposal([
            'scope_description' => $scope !== '' ? $scope : (string) $s['description'], 'client_reason' => $reason !== '' ? $reason : 'You asked for this extra work: ' . mb_substr((string) $s['description'], 0, 300),
            'amount' => number_format(((int) $s['est_amount_minor']) / 100, 2, '.', ''), 'days' => $s['est_days'] === null ? '' : (string) $s['est_days'], 'due_date' => '',
        ]);

        return Database::transaction(function () use ($actorId, $s, $project, $cr): string {
            $cur = Database::one('SELECT * FROM scope_requests WHERE id = ? FOR UPDATE', [$s['id']]);
            if ($cur['status'] !== 'assessing') {
                throw new ValidationException(['This request was already answered.']);
            }
            $id = ChangeRequestService::createDraftForScope($actorId, $project, $cr, 'Scope change ' . $s['reference'], (int) $s['id']);
            Database::run("UPDATE scope_requests SET status = 'quoted', change_request_id = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?", [$id, $s['id']]);
            Audit::record('scope_request.converted', 'scope_request', (int) $s['id'], [], ['change_request_id' => $id], [], $actorId);

            return (string) Database::one('SELECT public_id FROM change_requests WHERE id = ?', [$id])['public_id'];
        });
    }

    public static function decline(int $actorId, array $s, string $reason): void
    {
        self::requireStaff($actorId, (string) $s['public_id']);
        $reason = (string) FieldRules::text($reason, 'Reason shown to the client', 5, 1000);
        Database::transaction(function () use ($actorId, $s, $reason): void {
            $n = Database::run("UPDATE scope_requests SET status = 'declined', assessment_note = ?, assessed_by = ?, updated_at = UTC_TIMESTAMP() WHERE id = ? AND status IN ('submitted','assessing')", [$reason, $actorId, $s['id']])->rowCount();
            if ($n !== 1) {
                throw new ValidationException(['This request is already answered.']);
            }
            Audit::record('scope_request.declined', 'scope_request', (int) $s['id'], [], [], ['reason_recorded' => true], $actorId);
            Notifier::send([(int) $s['requested_by']], 'scope.declined', 'About your scope change request ' . $s['reference'], mb_substr($reason, 0, 200), '/my/scope-requests/' . $s['public_id'], (int) $s['project_id'], 'scope_request', (int) $s['id'], 'scope:' . $s['id'] . ':declined');
        });
    }

    /** @param array<string,mixed> $f @return array{rows:list<array<string,mixed>>,total:int} */
    public static function paginate(int $userId, array $f, int $page = 1, int $perPage = 20): array
    {
        Gate::authorize('scope.assess', $userId);
        $scope = ProjectPolicy::scopeSql($userId);
        if ($scope === null) {
            return ['rows' => [], 'total' => 0];
        }
        $w = [$scope[0]];
        $p = $scope[1];
        if (in_array($f['status'] ?? '', ['submitted', 'assessing', 'quoted', 'declined', 'withdrawn'], true)) {
            $w[] = 's.status = ?';
            $p[] = $f['status'];
        }
        $cond = implode(' AND ', $w);
        $perPage = max(1, min(100, $perPage));
        $total = (int) Database::one("SELECT COUNT(*) AS n FROM scope_requests s JOIN projects p ON p.id = s.project_id JOIN clients c ON c.id = p.client_id WHERE {$cond}", $p)['n'];
        $offset = (int) min(max(0, ($page - 1) * $perPage), 2_000_000_000);
        $rows = Database::all("SELECT s.public_id, s.reference, s.status, s.created_at, s.wanted_by, s.est_amount_minor, p.code AS project_code, p.title AS project_title, u.name AS requester_name FROM scope_requests s JOIN projects p ON p.id = s.project_id JOIN clients c ON c.id = p.client_id JOIN users u ON u.id = s.requested_by WHERE {$cond} ORDER BY (s.status IN ('submitted','assessing')) DESC, s.id DESC LIMIT {$perPage} OFFSET {$offset}", $p);

        return ['rows' => $rows, 'total' => $total];
    }

    /** @return list<array<string,mixed>> */
    public static function forClient(int $userId): array
    {
        return Database::all('SELECT s.public_id, s.reference, s.status, s.created_at, s.description, p.code AS project_code, p.title AS project_title FROM scope_requests s JOIN projects p ON p.id = s.project_id WHERE s.requested_by = ? ORDER BY s.id DESC', [$userId]);
    }
}
