<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Config;
use Vertex\Core\Database;
use Vertex\Core\HttpException;
use Vertex\Core\ValidationException;

/**
 * The per-issue warranty review of a bug report. Being inside the warranty period never covers an issue by itself, and being outside
 * it never rejects one: a person decides, and the decision is recorded with the reason, the terms relied on, who decided and when.
 *
 *  - Submission only sets the QUEUE: Awaiting Technical Review (warranty active at that moment) or Submitted for Assessment.
 *  - Decisions: covered | not_covered | potentially_chargeable. The reason is written for the CLIENT and is shown to them.
 *  - The listed considerations (new feature, scope change, client modification, third party, hosting/domain/API, unsupported
 *    environment) are recorded as considerations only. None of them excludes an issue automatically.
 *  - A decision that goes against the recorded warranty position (covering an issue the warranty did not cover at submission) or that
 *    changes an earlier decision is an OVERRIDE: it needs `warranty.override` (administrators), is flagged, and is audited separately.
 *  - Potentially chargeable never charges anything: it only allows a PROPOSAL (ChangeRequestService) that the client must approve.
 */
final class BugEligibilityService
{
    public const DECISIONS = ['covered', 'not_covered', 'potentially_chargeable'];

    /**
     * @param array<string,mixed> $t bug ticket as loaded by TicketAccess
     * @param array<string,mixed> $in decision, reason (client-facing), terms (text relied on), considerations[] (keys), internal_note, quotation_required
     */
    public static function decide(int $actorId, array $t, array $in): void
    {
        if ($t['kind'] !== 'bug' || $t['status'] === 'draft') {
            throw new HttpException(404);
        }
        TicketAccess::authorize($actorId, $t, 'bugs.review_eligibility');
        $errors = [];
        $try = static function (callable $fn) use (&$errors): mixed {
            try {
                return $fn();
            } catch (ValidationException $e) {
                array_push($errors, ...$e->errors);

                return null;
            }
        };
        $decision = $try(static fn () => (string) FieldRules::choice($in['decision'] ?? '', array_intersect_key((array) Config::get('workflow.bug_eligibility'), array_flip(self::DECISIONS)), 'decision'));
        $reason = $try(static fn () => (string) FieldRules::text($in['reason'] ?? '', 'Explanation for the client', 10, 3000));
        $terms = $try(static fn () => FieldRules::line($in['terms'] ?? '', 'Warranty terms relied on', 2, 255, false));
        $internal = $try(static fn () => (string) FieldRules::text($in['internal_note'] ?? '', 'Internal note', 2, 3000, false));
        $considerations = [];
        foreach ((array) ($in['considerations'] ?? []) as $k) {
            if (!is_string($k) || !array_key_exists($k, (array) Config::get('workflow.bug_considerations'))) {
                $errors[] = 'Unknown consideration.';
                break;
            }
            $considerations[$k] = true;
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $considerations = array_keys($considerations);
        $quotation = $decision === 'potentially_chargeable' || !empty($in['quotation_required']);

        $activeAtSubmission = self::periodCoveredSubmission($t);
        $previous = $t['eligibility'];
        $isFinal = in_array($previous, self::DECISIONS, true);
        $override = ($decision === 'covered' && !$activeAtSubmission) || ($isFinal && $previous !== $decision);
        if ($override && !Gate::can('warranty.override', $actorId)) {
            throw new ValidationException(['This decision ' . ($isFinal && $previous !== $decision ? 'changes an earlier decision' : 'covers an issue the warranty did not cover when it was reported')
                . ', so it must be made by an authorised administrator.']);
        }
        if ($decision !== 'potentially_chargeable' && $previous === 'potentially_chargeable') {
            $open = Database::one("SELECT 1 AS x FROM change_requests WHERE ticket_id = ? AND status IN ('pending_client','approved')", [$t['id']]);
            if ($open !== null) {
                throw new ValidationException(['A cost proposal for this issue is pending or approved. Withdraw it before changing the decision.']);
            }
        }

        Database::transaction(function () use ($actorId, $t, $decision, $reason, $terms, $internal, $considerations, $quotation, $previous, $override, $activeAtSubmission): void {
            $locked = Database::one('SELECT eligibility FROM bug_reports WHERE ticket_id = ? FOR UPDATE', [$t['id']]);
            if ($locked === null || $locked['eligibility'] !== $previous) {
                throw new ValidationException(['This review was changed by someone else. Reload and try again.']);
            }
            Database::run(
                'UPDATE bug_reports SET eligibility = ?, eligibility_reason = ?, eligibility_terms = ?, considerations_json = ?, quotation_required = ?, eligibility_reviewer_id = ?, eligibility_reviewed_at = UTC_TIMESTAMP(), eligibility_overridden = ? WHERE ticket_id = ?',
                [$decision, $reason, $terms, json_encode($considerations, JSON_THROW_ON_ERROR), $quotation ? 1 : 0, $actorId, $override ? 1 : 0, $t['id']],
            );
            Database::run('UPDATE tickets SET updated_at = UTC_TIMESTAMP() WHERE id = ?', [$t['id']]);
            TicketService::activity((int) $t['id'], $actorId, 'eligibility', (string) $previous, $decision, $reason, 'client');
            $note = 'Warranty decision: ' . Config::get("workflow.bug_eligibility.{$decision}") . ($considerations === [] ? '' : '. Considered: ' . implode(', ', array_map(static fn (string $k): string => (string) Config::get("workflow.bug_considerations.{$k}"), $considerations))) . ($internal === '' ? '' : ". {$internal}");
            Database::run("INSERT INTO ticket_notes (ticket_id, author_id, kind, body, created_at) VALUES (?, ?, 'decision', ?, UTC_TIMESTAMP())", [$t['id'], $actorId, $note]);
            Audit::record($override ? 'ticket.eligibility_overridden' : 'ticket.eligibility_decided', 'ticket', (int) $t['id'], ['eligibility' => $previous],
                ['eligibility' => $decision, 'quotation_required' => $quotation, 'considerations' => $considerations, 'active_at_submission' => $activeAtSubmission], ['project_id' => $t['project_id'] === null ? null : (int) $t['project_id'], 'reason_recorded' => true], $actorId);
            $n = (int) Database::one('SELECT COUNT(*) AS c FROM ticket_activity WHERE ticket_id = ?', [$t['id']])['c'];
            Notifier::send([(int) $t['client_user_id']], 'ticket.eligibility', 'Warranty review of your bug report', "{$t['reference']}: " . Config::get("workflow.bug_eligibility.{$decision}"),
                '/tickets/' . $t['public_id'], $t['project_id'] === null ? null : (int) $t['project_id'], 'ticket', (int) $t['id'], "ticket:{$t['id']}:eligibility:{$n}");
        });
    }

    /**
     * Did the recorded warranty period include the day the bug was reported? True when the warranty was Active at that moment, or when
     * the dates were confirmed LATER and the recorded period (not suspended) includes the submission day in the application time zone.
     *
     * @param array<string,mixed> $t
     */
    public static function periodCoveredSubmission(array $t): bool
    {
        $snapshot = json_decode((string) $t['warranty_snapshot_json'], true);
        if (is_array($snapshot) && ($snapshot['status'] ?? null) === 'active') {
            return true;
        }
        $project = $t['project_id'] === null ? null : ProjectPolicy::findById((int) $t['project_id']);
        $w = $project === null ? null : WarrantyService::forProject($project)['row'];
        if ($w === null || $w['eligibility'] !== 'eligible' || (int) $w['suspended'] === 1 || $w['start_date'] === null || $w['end_date'] === null || ($t['submitted_at'] ?? null) === null) {
            return false;
        }
        $day = (new \DateTimeImmutable((string) $t['submitted_at'], new \DateTimeZone('UTC')))->setTimezone(AppTime::zone())->format('Y-m-d');

        return $day >= $w['start_date'] && $day <= $w['end_date'];
    }

    /** What the CLIENT may be told about the review: the outcome, the explanation and the terms relied on. Never reviewer identity or internal considerations. @param array<string,mixed> $t @return array<string,mixed> */
    public static function clientView(array $t): array
    {
        $e = (string) ($t['eligibility'] ?? '');
        $decided = in_array($e, self::DECISIONS, true);

        return [
            'label' => $e === '' ? '' : (string) Config::get("workflow.bug_eligibility.{$e}", $e),
            'decided' => $decided,
            'reason' => $decided ? (string) $t['eligibility_reason'] : '',
            'terms' => $decided ? (string) $t['eligibility_terms'] : '',
            'reviewed_at' => $decided ? $t['eligibility_reviewed_at'] : null,
            'quotation_required' => $decided && (int) $t['quotation_required'] === 1,
        ];
    }
}
