<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Config;
use Vertex\Core\Database;
use Vertex\Core\HttpException;
use Vertex\Core\ValidationException;

/**
 * Priority changes and their price consequences, with the approved price protected.
 *
 *  1. An Administrator RECORDS the approved quotation baseline of a project once (reference + base price + any priority
 *     charge already inside it). That is price revision 1 and is never edited or deleted. (The quotation/invoice modules
 *     arrive in later phases; `quotation_ref` is the integration point.)
 *  2. Changing the PROJECT priority (never a task's) on a project WITH a baseline calculates the adjustment from the current
 *     pricing rules. A non-zero adjustment creates a PENDING request; the project priority and the approved price do not
 *     change until the CLIENT approves it in the portal. Approval appends price revision N+1; rejection changes nothing.
 *  3. A zero adjustment (or a project with no baseline yet) changes the priority directly: nothing priced is affected.
 *
 * Nothing here collects money, issues an invoice or edits an existing quotation/invoice.
 */
final class PriceChangeService
{
    /** @param array<string,mixed> $project */
    public static function isOpen(array $project): bool
    {
        return $project['archived_at'] === null && !in_array($project['status'], ['cancelled', 'completed'], true);
    }

    /** Latest (= currently approved) price revision, or null when no baseline is recorded. @return array<string,mixed>|null */
    public static function currentRevision(int $projectId): ?array
    {
        return Database::one('SELECT * FROM price_revisions WHERE project_id = ? ORDER BY revision_no DESC LIMIT 1', [$projectId]);
    }

    /** Who may SEE a project's pricing tab: its client (to decide) or staff holding a pricing permission. @param array<string,mixed> $project */
    public static function canView(int $userId, array $project): bool
    {
        $level = ProjectPolicy::level($userId, $project);
        if ($level === 'own') {
            return Gate::can('pricing.respond_change', $userId);
        }

        return in_array($level, ['all', 'assigned'], true) && Gate::canAny(['pricing.view', 'pricing.request_change', 'projects.record_approval'], $userId);
    }

    /**
     * @param array<string,mixed> $project
     * @return array{revisions:list<array<string,mixed>>,requests:list<array<string,mixed>>,pending:?array<string,mixed>,current:?array<string,mixed>,staff:bool}
     */
    public static function overview(int $userId, array $project): array
    {
        if (!self::canView($userId, $project)) {
            throw new HttpException(404);
        }
        $pid = (int) $project['id'];
        $requests = Database::all(
            'SELECT r.*, ru.name AS requested_by_name, du.name AS decided_by_name FROM price_change_requests r
               LEFT JOIN users ru ON ru.id = r.requested_by LEFT JOIN users du ON du.id = r.decided_by
              WHERE r.project_id = ? ORDER BY r.id DESC LIMIT 100',
            [$pid],
        );
        $pending = null;
        foreach ($requests as $r) {
            if ($r['status'] === 'pending_client') {
                $pending = $r;
                break;
            }
        }

        return [
            'revisions' => Database::all('SELECT v.*, u.name AS author FROM price_revisions v LEFT JOIN users u ON u.id = v.created_by WHERE v.project_id = ? ORDER BY v.revision_no', [$pid]),
            'requests' => $requests,
            'pending' => $pending,
            'current' => self::currentRevision($pid),
            'staff' => in_array(ProjectPolicy::level($userId, $project), ['all', 'assigned'], true),
        ];
    }

    /**
     * Records the approved quotation baseline (once). Needs `projects.record_approval` (sensitive: the controller also asks for
     * the actor's password).
     *
     * @param array<string,mixed> $project
     * @param array<string,mixed> $in quotation_ref, base (rupees), surcharge (rupees, default 0)
     */
    public static function recordApproval(int $actorId, array $project, array $in): void
    {
        ProjectPolicy::authorizeStaff($actorId, $project, 'projects.record_approval');
        if (!self::isOpen($project)) {
            throw new ValidationException(['This project is closed; a quotation cannot be recorded.']);
        }
        $ref = (string) FieldRules::line($in['quotation_ref'] ?? '', 'Quotation reference', 3, 60);
        $base = (int) FieldRules::moneyMinor($in['base'] ?? '', 'Base price', true);
        $surcharge = (int) FieldRules::moneyMinor($in['surcharge'] ?? '', 'Priority charge included in the quotation');
        if ($base < 1) {
            throw new ValidationException(['The base price must be greater than zero.']);
        }
        $total = $base + $surcharge;
        if ($total > 99_999_999_999_900) {
            throw new ValidationException(['That amount is too large.']);
        }

        Database::transaction(function () use ($actorId, $project, $ref, $base, $surcharge, $total): void {
            $locked = Database::one('SELECT approved_at FROM projects WHERE id = ? FOR UPDATE', [$project['id']]);
            if ($locked === null || $locked['approved_at'] !== null) {
                throw new ValidationException(['An approved quotation is already recorded for this project. Its price can only change through a client-approved revision.']);
            }
            Database::run(
                'UPDATE projects SET approved_quotation_ref = ?, approved_amount_minor = ?, approved_at = UTC_TIMESTAMP(), approved_by = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?',
                [$ref, $total, $actorId, $project['id']],
            );
            Database::run(
                "INSERT INTO price_revisions (project_id, revision_no, kind, base_minor, surcharge_minor, amount_minor, quotation_ref, note, created_by, created_at)
                 VALUES (?, 1, 'original', ?, ?, ?, ?, 'Approved quotation recorded', ?, UTC_TIMESTAMP())",
                [$project['id'], $base, $surcharge, $total, $ref, $actorId],
            );
            ProjectActivity::log((int) $project['id'], $actorId, 'pricing.baseline_recorded', 'Approved quotation recorded: ' . $ref, 'client', 'project', (int) $project['id']);
            Audit::record('pricing.baseline_recorded', 'project', (int) $project['id'], [], ['quotation_ref' => $ref, 'base_minor' => $base, 'surcharge_minor' => $surcharge, 'amount_minor' => $total], [], $actorId);
        });
    }

    /**
     * Changes the PROJECT priority. @param array<string,mixed> $project
     *
     * @return array{applied:bool,request:?string,adjustment_minor:int} applied=false means a client-approval request was created instead
     */
    public static function changePriority(int $actorId, array $project, string $to, string $note = ''): array
    {
        ProjectPolicy::authorizeStaff($actorId, $project, 'projects.set_priority');
        if (!self::isOpen($project)) {
            throw new ValidationException(['This project is closed; its priority cannot be changed.']);
        }
        $to = (string) FieldRules::choice($to, (array) Config::get('workflow.priorities'), 'priority');
        $note = (string) FieldRules::line($note, 'Note', 3, 255, false);

        $out = Database::transaction(function () use ($actorId, $project, $to, $note): array {
            $locked = Database::one('SELECT priority, service_category, approved_at FROM projects WHERE id = ? FOR UPDATE', [$project['id']]);
            if ($locked === null || $locked['priority'] !== $project['priority']) {
                throw new ValidationException(['The project priority changed while you were working. Reload and try again.']);
            }
            $from = (string) $locked['priority'];
            if ($from === $to) {
                throw new ValidationException(['The project already has that priority.']);
            }
            $labels = (array) Config::get('workflow.priorities');
            $cur = $locked['approved_at'] === null ? null : self::currentRevision((int) $project['id']);

            if ($cur === null) {
                self::applyPriority($actorId, $project, $from, $to, 'No approved quotation is recorded, so nothing priced changes.');

                return ['applied' => true, 'request' => null, 'adjustment_minor' => 0];
            }

            $rule = PricingService::ruleFor($locked['service_category'], $to);
            $proposed = PricingService::surchargeFor($rule, (int) $cur['base_minor']);
            $adjustment = $proposed - (int) $cur['surcharge_minor'];
            if ($adjustment === 0) {
                self::applyPriority($actorId, $project, $from, $to, 'The pricing rules give the same priority charge, so the approved price is unchanged.');

                return ['applied' => true, 'request' => null, 'adjustment_minor' => 0];
            }
            Gate::authorize('pricing.request_change', $actorId); // only people trusted to propose price changes may trigger one

            Database::run("UPDATE price_change_requests SET status = 'superseded', decided_at = UTC_TIMESTAMP() WHERE project_id = ? AND status = 'pending_client'", [$project['id']]);
            $pub = uuid_hex();
            $snapshot = json_encode([
                'rule_id' => $rule === null ? null : (int) $rule['id'], 'rule_version' => $rule === null ? null : (int) $rule['version'],
                'rule_service' => $rule === null ? null : $rule['service_category'], 'type' => $rule['surcharge_type'] ?? 'none',
                'fixed_minor' => (int) ($rule['fixed_minor'] ?? 0), 'percent_bps' => (int) ($rule['percent_bps'] ?? 0),
                'base_minor' => (int) $cur['base_minor'], 'revision_no' => (int) $cur['revision_no'], 'staff_note' => $note === '' ? null : $note,
            ], JSON_THROW_ON_ERROR);
            Database::run(
                "INSERT INTO price_change_requests (public_id, project_id, from_priority, to_priority, service_category, base_minor, current_surcharge_minor,
                        proposed_surcharge_minor, adjustment_minor, rule_snapshot_json, status, requested_by, requested_at, decision_note)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending_client', ?, UTC_TIMESTAMP(), NULL)",
                [$pub, $project['id'], $from, $to, $locked['service_category'], $cur['base_minor'], $cur['surcharge_minor'], $proposed, $adjustment, $snapshot, $actorId],
            );
            $id = Database::insertId();
            ProjectActivity::log((int) $project['id'], $actorId, 'pricing.change_requested', "Priority change to {$labels[$to]} proposed; it needs the client's approval before anything changes.", 'client', 'price_change', $id);
            Audit::record('pricing.change_requested', 'project', (int) $project['id'], ['priority' => $from], ['priority' => $to], [
                'request_id' => $id, 'adjustment_minor' => $adjustment, 'proposed_surcharge_minor' => $proposed, 'rule_version' => $rule['version'] ?? null,
            ], $actorId);

            return ['applied' => false, 'request' => $pub, 'adjustment_minor' => $adjustment];
        });

        return $out;
    }

    /** Staff withdraw a pending proposal (nothing had changed, so nothing is reverted). @param array<string,mixed> $project */
    public static function withdraw(int $actorId, array $project, string $requestPublicId): void
    {
        ProjectPolicy::authorizeStaff($actorId, $project, 'pricing.request_change');
        Database::transaction(function () use ($actorId, $project, $requestPublicId): void {
            $r = self::lockPending($project, $requestPublicId);
            Database::run("UPDATE price_change_requests SET status = 'withdrawn', decided_by = ?, decided_at = UTC_TIMESTAMP() WHERE id = ?", [$actorId, $r['id']]);
            ProjectActivity::log((int) $project['id'], $actorId, 'pricing.change_withdrawn', 'The proposed priority change was withdrawn.', 'client', 'price_change', (int) $r['id']);
            Audit::record('pricing.change_withdrawn', 'project', (int) $project['id'], [], [], ['request_id' => (int) $r['id']], $actorId);
        });
    }

    /**
     * The CLIENT's decision. Only the owning client with `pricing.respond_change` may decide; staff cannot approve on the
     * client's behalf. Approval appends a price revision and applies the priority; rejection changes nothing.
     *
     * @param array<string,mixed> $project
     */
    public static function respond(int $actorId, array $project, string $requestPublicId, string $decision, string $note = ''): void
    {
        if (ProjectPolicy::level($actorId, $project) !== 'own' || !Gate::can('pricing.respond_change', $actorId)) {
            throw new HttpException(403);
        }
        if (!in_array($decision, ['approve', 'reject'], true)) {
            throw new ValidationException(['Choose approve or reject.']);
        }
        $note = (string) FieldRules::line($note, 'Comment', 2, 255, false);
        $stale = false;
        Database::transaction(function () use ($actorId, $project, $requestPublicId, $decision, $note, &$stale): void {
            $locked = Database::one('SELECT priority, archived_at, status FROM projects WHERE id = ? FOR UPDATE', [$project['id']]);
            $r = self::lockPending($project, $requestPublicId);
            $cur = self::currentRevision((int) $project['id']);
            $open = $locked !== null && $locked['archived_at'] === null && !in_array($locked['status'], ['cancelled', 'completed'], true);
            if (!$open || $cur === null || $locked['priority'] !== $r['from_priority'] || (int) $cur['base_minor'] !== (int) $r['base_minor'] || (int) $cur['surcharge_minor'] !== (int) $r['current_surcharge_minor']) {
                Database::run("UPDATE price_change_requests SET status = 'superseded', decided_at = UTC_TIMESTAMP() WHERE id = ?", [$r['id']]);
                $stale = true;

                return;
            }
            $labels = (array) Config::get('workflow.priorities');
            if ($decision === 'reject') {
                Database::run("UPDATE price_change_requests SET status = 'rejected', decided_by = ?, decided_at = UTC_TIMESTAMP(), decision_note = ? WHERE id = ?", [$actorId, $note === '' ? null : $note, $r['id']]);
                ProjectActivity::log((int) $project['id'], $actorId, 'pricing.change_rejected', 'The client declined the proposed priority change. Priority and price are unchanged.', 'client', 'price_change', (int) $r['id']);
                Audit::record('pricing.change_rejected', 'project', (int) $project['id'], [], [], ['request_id' => (int) $r['id']], $actorId);

                return;
            }
            $newTotal = (int) $r['base_minor'] + (int) $r['proposed_surcharge_minor'];
            Database::run(
                "INSERT INTO price_revisions (project_id, revision_no, kind, base_minor, surcharge_minor, amount_minor, quotation_ref, source_request_id, note, created_by, created_at)
                 VALUES (?, ?, 'priority_change', ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())",
                [$project['id'], (int) $cur['revision_no'] + 1, $r['base_minor'], $r['proposed_surcharge_minor'], $newTotal, $cur['quotation_ref'], $r['id'],
                    mb_substr("Priority {$labels[$r['from_priority']]} → {$labels[$r['to_priority']]}, approved by the client", 0, 255), $actorId],
            );
            $revisionId = Database::insertId();
            Database::run("UPDATE price_change_requests SET status = 'approved', decided_by = ?, decided_at = UTC_TIMESTAMP(), decision_note = ?, applied_revision_id = ? WHERE id = ?", [$actorId, $note === '' ? null : $note, $revisionId, $r['id']]);
            Database::run('UPDATE projects SET priority = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?', [$r['to_priority'], $project['id']]);
            ProjectActivity::log((int) $project['id'], $actorId, 'pricing.change_approved', "The client approved the priority change to {$labels[$r['to_priority']]}. A new price revision was recorded.", 'client', 'price_change', (int) $r['id']);
            Audit::record('pricing.change_approved', 'project', (int) $project['id'], ['priority' => $r['from_priority']], ['priority' => $r['to_priority']], [
                'request_id' => (int) $r['id'], 'revision_id' => $revisionId, 'adjustment_minor' => (int) $r['adjustment_minor'], 'new_amount_minor' => $newTotal,
            ], $actorId);
        });
        if ($stale) {
            throw new ValidationException(['This proposal is out of date because the project changed after it was made. Nothing was changed. Ask the team to send a new proposal if it is still needed.']);
        }
    }

    /** @param array<string,mixed> $project @return array<string,mixed> the pending request row, locked */
    private static function lockPending(array $project, string $publicId): array
    {
        $r = preg_match('/^[a-f0-9]{32}$/', $publicId)
            ? Database::one('SELECT * FROM price_change_requests WHERE public_id = ? AND project_id = ? FOR UPDATE', [$publicId, $project['id']])
            : null;
        if ($r === null) {
            throw new HttpException(404);
        }
        if ($r['status'] !== 'pending_client') {
            throw new ValidationException(['That proposal has already been decided or replaced.']);
        }

        return $r;
    }

    /** @param array<string,mixed> $project */
    private static function applyPriority(int $actorId, array $project, string $from, string $to, string $why): void
    {
        $labels = (array) Config::get('workflow.priorities');
        Database::run('UPDATE projects SET priority = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?', [$to, $project['id']]);
        ProjectActivity::log((int) $project['id'], $actorId, 'project.priority_changed', "Project priority: {$labels[$from]} → {$labels[$to]}", 'client', 'project', (int) $project['id']);
        Audit::record('project.priority_changed', 'project', (int) $project['id'], ['priority' => $from], ['priority' => $to], ['note' => $why], $actorId);
    }
}
