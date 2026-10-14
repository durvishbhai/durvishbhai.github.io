<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Database;
use Vertex\Core\HttpException;
use Vertex\Core\ValidationException;

/**
 * Change requests: proposed ADDITIONAL work with its scope, price and timeline, created when a revision is classified as potentially
 * chargeable. They are the safe integration point for the (not yet built) quotation module:
 *   draft -> pending_client (staff send it) -> approved | rejected (the CLIENT decides) | withdrawn | superseded
 *  - Nothing is charged, invoiced or added to the approved project price here. This class never writes projects.approved_* or
 *    price_revisions; the original scope and approved price stay exactly as agreed. An approved row is simply the client's recorded
 *    consent, to be turned into a quotation revision by the quotation module (`quotation_ref` is its pointer).
 *  - Only the owning client (`revisions.respond_cost`) can approve or decline. Staff cannot approve on the client's behalf.
 */
final class ChangeRequestService
{
    /**
     * @param array<string,mixed> $in scope_description, client_reason, amount (rupees), days, due_date
     * @return array{scope:string,reason:string,amount:int,days:?int,due:?string}
     */
    public static function validateProposal(array $in): array
    {
        $errors = [];
        $try = static function (callable $fn) use (&$errors): mixed {
            try {
                return $fn();
            } catch (ValidationException $e) {
                array_push($errors, ...$e->errors);

                return null;
            }
        };
        $scope = $try(static fn () => (string) FieldRules::text($in['scope_description'] ?? '', 'Proposed additional scope', 10, 3000));
        $reason = $try(static fn () => (string) FieldRules::text($in['client_reason'] ?? '', 'Why this is additional work (shown to the client)', 10, 3000));
        $amount = $try(static function () use ($in): int {
            $a = (int) FieldRules::moneyMinor($in['amount'] ?? '', 'Proposed extra cost', true);
            if ($a < 1) {
                throw new ValidationException(['The proposed extra cost must be greater than zero.']);
            }
            if ($a > 99_999_999_999_900) {
                throw new ValidationException(['That amount is too large.']);
            }

            return $a;
        });
        $days = $try(static fn () => FieldRules::minutes($in['days'] ?? '', 'Extra days', false, 3650));
        $due = $try(static fn () => FieldRules::date($in['due_date'] ?? '', 'Proposed delivery date'));
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return ['scope' => $scope, 'reason' => $reason, 'amount' => $amount, 'days' => $days, 'due' => $due];
    }

    /**
     * Creates the draft inside the caller's transaction. @param array<string,mixed> $r revision row @param array<string,mixed> $project
     * @param array{scope:string,reason:string,amount:int,days:?int,due:?string} $cr
     */
    public static function createDraft(int $actorId, array $r, array $project, array $cr): int
    {
        return self::insert($actorId, $project, $cr, $r['id'], null, mb_substr("Additional work for {$r['reference']}: {$r['deliverable_title']}", 0, 200), ['revision_id' => (int) $r['id']]);
    }

    /**
     * A proposal for a potentially chargeable BUG. Same record, same client approval; the warranty decision must already say
     * "potentially chargeable" and only one proposal can be open at a time. Nothing is charged or invoiced.
     *
     * @param array<string,mixed> $t bug ticket @param array{scope:string,reason:string,amount:int,days:?int,due:?string} $cr
     * @return string public id of the new draft
     */
    public static function createDraftForTicket(int $actorId, array $t, array $cr): string
    {
        TicketAccess::authorize($actorId, $t, 'tickets.propose_change');
        $project = $t['project_id'] === null ? null : ProjectPolicy::findById((int) $t['project_id']);
        if ($t['kind'] !== 'bug' || $project === null || $t['eligibility'] !== 'potentially_chargeable') {
            throw new ValidationException(['A cost proposal needs a bug report on a project that the warranty review marked as potentially chargeable.']);
        }
        if ($project['archived_at'] !== null || $project['status'] === 'cancelled') {
            throw new ValidationException(['This project is closed.']);
        }

        return Database::transaction(function () use ($actorId, $t, $project, $cr): string {
            Database::one('SELECT id FROM tickets WHERE id = ? FOR UPDATE', [$t['id']]);
            if (Database::one("SELECT 1 AS x FROM change_requests WHERE ticket_id = ? AND status IN ('draft','pending_client','approved')", [$t['id']]) !== null) {
                throw new ValidationException(['This issue already has an open or approved cost proposal.']);
            }
            $id = self::insert($actorId, $project, $cr, null, (int) $t['id'], mb_substr("Additional work for {$t['reference']}: {$t['subject']}", 0, 200), ['ticket_id' => (int) $t['id']]);
            TicketService::activity((int) $t['id'], $actorId, 'cost_proposal_drafted', null, null, null, 'internal');

            return (string) Database::one('SELECT public_id FROM change_requests WHERE id = ?', [$id])['public_id'];
        });
    }

    /** A draft proposal that answers a client's scope request. Runs inside the caller's transaction. @param array<string,mixed> $project @param array{scope:string,reason:string,amount:int,days:?int,due:?string} $cr */
    public static function createDraftForScope(int $actorId, array $project, array $cr, string $title, int $scopeRequestId): int
    {
        return self::insert($actorId, $project, $cr, null, null, mb_substr($title, 0, 200), ['scope_request_id' => $scopeRequestId]);
    }

    /** @param array{scope:string,reason:string,amount:int,days:?int,due:?string} $cr @param array<string,mixed> $project @param array<string,mixed> $meta */
    private static function insert(int $actorId, array $project, array $cr, mixed $revisionId, ?int $ticketId, string $title, array $meta): int
    {
        $current = PriceChangeService::currentRevision((int) $project['id']);
        $approvedExtra = (int) Database::one("SELECT COALESCE(SUM(proposed_amount_minor), 0) AS s FROM change_requests WHERE project_id = ? AND status = 'approved'", [$project['id']])['s'];
        $baseline = json_encode([
            'quotation_ref' => $current['quotation_ref'] ?? null, 'approved_total_minor' => $current === null ? null : (int) $current['amount_minor'],
            'approved_additional_work_minor' => $approvedExtra, 'priority' => $project['priority'],
            'note' => $current === null ? 'No approved quotation is recorded for this project.' : 'The original approved price is unchanged by this proposal.',
        ], JSON_THROW_ON_ERROR);
        $pub = uuid_hex();
        $ref = CrmReference::next('change');
        Database::run(
            "INSERT INTO change_requests (public_id, reference, project_id, revision_request_id, ticket_id, title, scope_description, reason, proposed_amount_minor, proposed_days, proposed_due_date, baseline_json, status, proposed_by, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?, UTC_TIMESTAMP())",
            [$pub, $ref, $project['id'], $revisionId, $ticketId, $title, $cr['scope'], $cr['reason'], $cr['amount'], $cr['days'], $cr['due'], $baseline, $actorId],
        );
        $id = Database::insertId();
        Audit::record('change_request.created', 'change_request', $id, [], ['reference' => $ref, 'proposed_amount_minor' => $cr['amount'], 'status' => 'draft'], ['project_id' => (int) $project['id']] + $meta, $actorId);

        return $id;
    }

    /** Edit a DRAFT proposal. @param array<string,mixed> $c @param array<string,mixed> $in */
    public static function updateDraft(int $actorId, array $c, array $in): void
    {
        self::authorizeStaff($actorId, $c);
        $cr = self::validateProposal($in);
        Database::transaction(function () use ($actorId, $c, $cr): void {
            $locked = Database::one('SELECT status FROM change_requests WHERE id = ? FOR UPDATE', [$c['id']]);
            if ($locked === null || $locked['status'] !== 'draft') {
                throw new ValidationException(['Only a draft proposal can be edited.']);
            }
            Database::run('UPDATE change_requests SET scope_description = ?, reason = ?, proposed_amount_minor = ?, proposed_days = ?, proposed_due_date = ? WHERE id = ?', [$cr['scope'], $cr['reason'], $cr['amount'], $cr['days'], $cr['due'], $c['id']]);
            Audit::record('change_request.updated', 'change_request', (int) $c['id'], ['proposed_amount_minor' => (int) $c['proposed_amount_minor']], ['proposed_amount_minor' => $cr['amount']], ['project_id' => (int) $c['project_id']], $actorId);
        });
    }

    /** Sends the draft to the client for approval. Revision -> Awaiting Client Approval of Additional Cost (a bug stays where it is). @param array<string,mixed> $c */
    public static function send(int $actorId, array $c): void
    {
        self::authorizeStaff($actorId, $c);
        Database::transaction(function () use ($actorId, $c): void {
            $locked = Database::one('SELECT status FROM change_requests WHERE id = ? FOR UPDATE', [$c['id']]);
            if ($c['ticket_id'] !== null) {
                $t = Database::one('SELECT t.id, t.requester_id, b.eligibility FROM tickets t JOIN bug_reports b ON b.ticket_id = t.id WHERE t.id = ? FOR UPDATE', [$c['ticket_id']]);
                if ($locked === null || $locked['status'] !== 'draft' || $t === null || $t['eligibility'] !== 'potentially_chargeable') {
                    throw new ValidationException(['This proposal cannot be sent now. It must be a draft on a potentially chargeable bug report.']);
                }
                Database::run("UPDATE change_requests SET status = 'pending_client', sent_at = UTC_TIMESTAMP() WHERE id = ?", [$c['id']]);
                TicketService::activity((int) $t['id'], $actorId, 'cost_proposed', null, null, 'An additional cost proposal was sent for your approval.', 'client');
                Audit::record('change_request.proposed', 'change_request', (int) $c['id'], ['status' => 'draft'], ['status' => 'pending_client'], ['project_id' => (int) $c['project_id'], 'proposed_amount_minor' => (int) $c['proposed_amount_minor'], 'ticket_id' => (int) $t['id']], $actorId);
                Notifier::send([(int) $t['requester_id']], 'change_request.awaiting_approval', 'Additional cost awaiting your approval', "{$c['reference']}: {$c['title']}",
                    '/changes/' . $c['public_id'], (int) $c['project_id'], 'change_request', (int) $c['id'], "change:{$c['id']}:sent");

                return;
            }
            $r = $c['revision_request_id'] === null ? null : Database::one('SELECT * FROM revision_requests WHERE id = ? FOR UPDATE', [$c['revision_request_id']]);
            if ($locked === null || $locked['status'] !== 'draft' || $r === null || $r['status'] !== 'potentially_chargeable') {
                throw new ValidationException(['This proposal cannot be sent now. It must be a draft on a potentially chargeable request.']);
            }
            Database::run("UPDATE change_requests SET status = 'pending_client', sent_at = UTC_TIMESTAMP() WHERE id = ?", [$c['id']]);
            Database::run("UPDATE revision_requests SET status = 'awaiting_cost_approval', updated_at = UTC_TIMESTAMP() WHERE id = ?", [$r['id']]);
            RevisionService::history((int) $r['id'], 'potentially_chargeable', 'awaiting_cost_approval', $actorId, 'Additional cost proposed to the client', 'client');
            ProjectActivity::log((int) $c['project_id'], $actorId, 'change_request.sent', "{$c['reference']}: an additional cost proposal was sent to the client", 'client', 'change_request', (int) $c['id']);
            Audit::record('change_request.proposed', 'change_request', (int) $c['id'], ['status' => 'draft'], ['status' => 'pending_client'], ['project_id' => (int) $c['project_id'], 'proposed_amount_minor' => (int) $c['proposed_amount_minor'], 'revision_id' => (int) $r['id']], $actorId);
            Notifier::send(Notifier::clientOf((int) $c['project_id']), 'change_request.awaiting_approval', 'Additional cost awaiting your approval', "{$c['reference']}: {$c['title']}",
                '/changes/' . $c['public_id'], (int) $c['project_id'], 'change_request', (int) $c['id'], "change:{$c['id']}:sent");
        });
    }

    /** Staff pull a draft or pending proposal back. The revision returns to Potentially Chargeable. @param array<string,mixed> $c */
    public static function withdraw(int $actorId, array $c): void
    {
        self::authorizeStaff($actorId, $c);
        Database::transaction(function () use ($actorId, $c): void {
            $locked = Database::one('SELECT status FROM change_requests WHERE id = ? FOR UPDATE', [$c['id']]);
            if ($locked === null || !in_array($locked['status'], ['draft', 'pending_client'], true)) {
                throw new ValidationException(['That proposal has already been decided or withdrawn.']);
            }
            Database::run("UPDATE change_requests SET status = 'withdrawn', decided_by = ?, decided_at = UTC_TIMESTAMP() WHERE id = ?", [$actorId, $c['id']]);
            if ($c['ticket_id'] !== null) {
                TicketService::activity((int) $c['ticket_id'], $actorId, 'cost_proposal_withdrawn', null, null, $locked['status'] === 'pending_client' ? 'The additional cost proposal was withdrawn.' : null, $locked['status'] === 'pending_client' ? 'client' : 'internal');
            }
            if ($locked['status'] === 'pending_client' && $c['revision_request_id'] !== null) {
                Database::run("UPDATE revision_requests SET status = 'potentially_chargeable', updated_at = UTC_TIMESTAMP() WHERE id = ? AND status = 'awaiting_cost_approval'", [$c['revision_request_id']]);
                RevisionService::history((int) $c['revision_request_id'], 'awaiting_cost_approval', 'potentially_chargeable', $actorId, null, 'internal');
            }
            Audit::record('change_request.withdrawn', 'change_request', (int) $c['id'], ['status' => $locked['status']], ['status' => 'withdrawn'], ['project_id' => (int) $c['project_id']], $actorId);
        });
    }

    /**
     * The client's explicit decision. Approval = consent to the proposed scope, price and timeline; the revision becomes
     * "Approved for Work". Decline returns the revision to Potentially Chargeable for staff to follow up. Idempotent: a second
     * decision is refused.
     *
     * @param array<string,mixed> $c
     */
    public static function clientDecide(int $actorId, array $c, string $decision, string $note): void
    {
        $project = ProjectPolicy::findById((int) $c['project_id']) ?? throw new HttpException(404);
        if (ProjectPolicy::level($actorId, $project) !== 'own' || !Gate::can('revisions.respond_cost', $actorId) || $c['status'] === 'draft') {
            throw new HttpException(403);
        }
        if ($c['revision_requested_by'] === null || (int) $c['revision_requested_by'] !== $actorId) {
            throw new HttpException(403);
        }
        if (!in_array($decision, ['approve', 'reject'], true)) {
            throw new ValidationException(['Choose approve or decline.']);
        }
        $note = (string) FieldRules::line($note, 'Comment', 2, 255, false);
        Database::transaction(function () use ($actorId, $c, $project, $decision, $note): void {
            Database::one('SELECT id FROM projects WHERE id = ? FOR UPDATE', [$project['id']]);
            $locked = Database::one('SELECT status FROM change_requests WHERE id = ? FOR UPDATE', [$c['id']]);
            $isTicket = $c['ticket_id'] !== null;
            $r = $isTicket ? null : Database::one('SELECT * FROM revision_requests WHERE id = ? FOR UPDATE', [$c['revision_request_id']]);
            if ($locked === null || $locked['status'] !== 'pending_client' || (!$isTicket && ($r === null || $r['status'] !== 'awaiting_cost_approval'))) {
                throw new ValidationException(['This proposal has already been decided or is no longer open.']);
            }
            if ($project['archived_at'] !== null || $project['status'] === 'cancelled') {
                throw new ValidationException(['This project is closed.']);
            }
            $approve = $decision === 'approve';
            Database::run('UPDATE change_requests SET status = ?, decided_by = ?, decided_at = UTC_TIMESTAMP(), decision_note = ? WHERE id = ?', [$approve ? 'approved' : 'rejected', $actorId, $note === '' ? null : $note, $c['id']]);
            $meta = ['project_id' => (int) $project['id'], 'proposed_amount_minor' => (int) $c['proposed_amount_minor']];
            if ($isTicket) {
                TicketService::activity((int) $c['ticket_id'], $actorId, $approve ? 'cost_approved' : 'cost_declined', null, null, $approve ? 'The client approved the additional cost.' : 'The client declined the additional cost.', 'client');
                $meta['ticket_id'] = (int) $c['ticket_id'];
                $recipients = array_values(array_unique([...Notifier::withPermission('tickets.propose_change')]));
                $ticket = TicketAccess::findById((int) $c['ticket_id']);
                if ($ticket !== null && $ticket['assigned_to'] !== null) {
                    $recipients[] = (int) $ticket['assigned_to'];
                }
                $recipients = array_values(array_unique(array_filter($recipients, static fn (int $u): bool => $ticket !== null && TicketAccess::role($u, $ticket) === 'staff')));
            } else {
                $to = $approve ? 'approved_for_work' : 'potentially_chargeable';
                Database::run('UPDATE revision_requests SET status = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?', [$to, $r['id']]);
                RevisionService::history((int) $r['id'], 'awaiting_cost_approval', $to, $actorId, $approve ? 'The client approved the additional cost' : 'The client declined the additional cost', 'client');
                $meta['revision_id'] = (int) $r['id'];
                $recipients = Notifier::staffWith('revisions.manage', (int) $project['id']);
            }
            ProjectActivity::log((int) $project['id'], $actorId, $approve ? 'change_request.approved' : 'change_request.declined', $approve ? "Client approved additional work {$c['reference']}" : "Client declined additional work {$c['reference']}", 'client', 'change_request', (int) $c['id']);
            Audit::record($approve ? 'change_request.client_approved' : 'change_request.client_declined', 'change_request', (int) $c['id'], ['status' => 'pending_client'], ['status' => $approve ? 'approved' : 'rejected'], $meta, $actorId);
            Notifier::send($recipients, 'change_request.client_decision', $approve ? 'Client approved the additional cost' : 'Client declined the additional cost', "{$c['reference']}: {$c['title']}",
                '/changes/' . $c['public_id'], (int) $project['id'], 'change_request', (int) $c['id'], "change:{$c['id']}:decided");
        });
    }

    /** @param array<string,mixed> $c */
    private static function authorizeStaff(int $actorId, array $c): void
    {
        $project = ProjectPolicy::findById((int) $c['project_id']) ?? throw new HttpException(404);
        if ($c['ticket_id'] !== null) {
            $t = TicketAccess::findById((int) $c['ticket_id']);
            if ($t === null) {
                throw new HttpException(404);
            }
            TicketAccess::authorize($actorId, $t, 'tickets.propose_change');
        } elseif (!ProjectPolicy::canStaff($actorId, $project, 'revisions.classify') || !Gate::can('revisions.manage', $actorId)) {
            throw new HttpException(403);
        }
        if ($project['archived_at'] !== null || $project['status'] === 'cancelled') {
            throw new ValidationException(['This project is closed.']);
        }
    }
}
