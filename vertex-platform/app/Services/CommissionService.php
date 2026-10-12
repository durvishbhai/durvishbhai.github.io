<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Config;
use Vertex\Core\Database;
use Vertex\Core\HttpException;
use Vertex\Core\ValidationException;

/**
 * The commission engine and the ledger operations. The exact rules and worked examples are in docs/14-PHASE6-REFERRAL-COMMISSIONS.md.
 *
 * evaluateProject() is the ONLY place commissions are calculated. It is idempotent: it reads the verified payments of the project and the rules in force,
 * and brings the two milestone entries of the credited referral to the state those facts imply, writing history for every change. It is called whenever
 * a payment is verified or refunded, a referral is credited or converted, and (by staff) on demand.
 *
 *   eligible(payment) = verified only; (gross - refunded) [if net_of_refunds]; minus the tax share [if exclude_tax]; a reversed payment counts for 0.
 *   Milestone 1 (advance, 10 %): the advance-role payments reached the minimum advance (or the project is paid in full).
 *   Milestone 2 (full, 5 %):     verified payments, net of refunds, reached the contract value (approved price [+ approved additional work]) minus the tolerance.
 *   cumulative:    M1 = floor(base1 x 10 %);  M2 = floor(base2 x 15 %) - M1 (net of its adjustments)      => 15 % of eligible money received, never on unpaid amounts
 *   per_milestone: M1 = floor(base1 x 10 %);  M2 = floor(base2 x 5 %), base2 = payments NOT counted in M1  => each payment is counted in exactly one milestone
 *   An entry stores its base, rate, the payments (and how much of each) behind it, the rules version and a snapshot, and the formula as text.
 */
final class CommissionService
{
    // ------------------------------------------------------------------------------------------------ engine

    public static function evaluateProject(int $projectId, ?int $actorId = null): void
    {
        Database::transaction(function () use ($projectId, $actorId): void {
            $project = Database::one('SELECT * FROM projects WHERE id = ? FOR UPDATE', [$projectId]);
            $ref = $project === null ? null : Database::one("SELECT * FROM partner_referrals WHERE credit_project_id = ? AND attribution = 'credited' AND status = 'converted' FOR UPDATE", [$projectId]);
            if ($ref === null) {
                return;
            }
            $cfg = CommissionRules::current();
            $payments = Database::all("SELECT * FROM client_payments WHERE project_id = ? AND status IN ('verified','reversed') ORDER BY received_on, id FOR UPDATE", [$projectId]);
            $now = self::context($project, $payments, $cfg['rules']);

            // ---- entries (latest generation of each milestone); a closed one (reversed, or fully clawed back) is replaced by a new generation when the facts qualify again
            $active = ['advance' => null, 'full' => null];
            foreach (Database::all("SELECT * FROM commission_entries WHERE referral_id = ? AND milestone IN ('advance','full') ORDER BY seq DESC FOR UPDATE", [$ref['id']]) as $e) {
                $active[$e['milestone']] ??= $e;
            }
            $triggers = ['advance' => $now['m1'], 'full' => $now['m2']];
            foreach ($triggers as $milestone => $trigger) {
                $cur = $active[$milestone];
                if ($cur === null || ($trigger && self::closed($cur, $now['eligible']))) {
                    $active[$milestone] = self::createEntry($ref, $project, $milestone, ($cur['seq'] ?? 0) + 1, $actorId);
                }
            }

            // ---- milestone 1 (valued under the rules it was calculated under; a pending entry uses the rules in force now)
            $m1 = $active['advance'];
            $r1 = self::rulesOf($m1, $cfg);
            $c1 = $r1 === $cfg['rules'] ? $now : self::context($project, $payments, $r1);
            $advBps = (int) $r1['advance_rate_bps'];
            $set1 = self::paymentSet($m1, $c1['eligible'], null);
            $base1 = array_sum($set1);
            $amount1 = $c1['m1'] ? Money::bps($base1, $advBps) : 0;
            $formula1 = ['milestone' => 'advance', 'mode' => $r1['formula_mode'], 'formula' => "floor(base x {$advBps} / 10000)", 'base_minor' => $base1, 'rate_bps' => $advBps, 'amount_minor' => $amount1, 'inputs' => self::inputs($c1)];
            $m1 = self::settle($m1, $ref, $amount1, $base1, $set1, $advBps, $formula1, $cfg, $r1, $c1['m1'] ? 'The advance payment condition is no longer satisfied or a payment changed.' : 'The advance payment condition is not satisfied.', $actorId);

            // ---- milestone 2
            $m2 = $active['full'];
            $r2 = self::rulesOf($m2, $cfg);
            $c2 = $r2 === $cfg['rules'] ? $now : self::context($project, $payments, $r2);
            $fullBps = (int) $r2['full_rate_bps'];
            $m1Done = !in_array($m1['status'], ['pending_eligibility', 'reversed'], true);
            $trigger2 = $c2['m2'] && $m1Done;
            if ($r2['formula_mode'] === 'cumulative') {
                $m1Bps = $m1Done ? (int) $m1['rate_bps'] : (int) $r2['advance_rate_bps'];
                $set2 = self::paymentSet($m2, $c2['eligible'], null);
                $base2 = array_sum($set2);
                $m1Net = $m1Done ? self::net($m1) : 0;
                $amount2 = $trigger2 ? max(0, Money::bps($base2, $m1Bps + $fullBps) - $m1Net) : 0;
                $formula2 = ['milestone' => 'full', 'mode' => 'cumulative', 'formula' => 'max(0, floor(base x ' . ($m1Bps + $fullBps) . ' / 10000) - advance milestone net)', 'base_minor' => $base2,
                    'rate_bps' => $fullBps, 'cumulative_rate_bps' => $m1Bps + $fullBps, 'advance_milestone_net_minor' => $m1Net, 'amount_minor' => $amount2, 'inputs' => self::inputs($c2)];
            } else {
                $exclude = $m1Done ? array_keys(self::paymentSet($m1, $c2['eligible'], null)) : array_keys($c2['eligible']);
                $set2 = self::paymentSet($m2, $c2['eligible'], $exclude);
                $base2 = array_sum($set2);
                $amount2 = $trigger2 ? Money::bps($base2, $fullBps) : 0;
                $formula2 = ['milestone' => 'full', 'mode' => 'per_milestone', 'formula' => "floor(base x {$fullBps} / 10000)", 'base_minor' => $base2, 'rate_bps' => $fullBps, 'amount_minor' => $amount2, 'inputs' => self::inputs($c2)];
            }
            self::settle($m2, $ref, $amount2, $base2, $set2, $fullBps, $formula2, $cfg, $r2, $c2['cancelled'] ? 'The project was cancelled before the full payment.' : 'The full-payment condition is not satisfied.', $actorId);
        });
    }

    /**
     * Everything the rules say about the verified payments of one project. Pure: no writes.
     *
     * @param array<string,mixed> $project
     * @param list<array<string,mixed>> $payments
     * @param array<string,mixed> $rules
     * @return array{eligible:array<int,int>,advance:int,received:int,total:?int,threshold:?int,full:bool,cancelled:bool,m1:bool,m2:bool}
     */
    private static function context(array $project, array $payments, array $rules): array
    {
        $eligible = [];
        $advance = 0;
        $received = 0;
        foreach ($payments as $p) {
            $e = self::eligible($p, $rules);
            $eligible[(int) $p['id']] = $e;
            $received += $p['status'] === 'verified' ? max(0, (int) $p['gross_minor'] - (int) $p['refunded_minor']) : 0;
            if ($p['payment_role'] === 'advance') {
                $advance += $e;
            }
        }
        $total = $project['approved_amount_minor'] === null ? null : (int) $project['approved_amount_minor'];
        if ($total !== null && !empty($rules['include_change_requests'])) {
            $total += (int) Database::one("SELECT COALESCE(SUM(proposed_amount_minor), 0) AS s FROM change_requests WHERE project_id = ? AND status = 'approved'", [$project['id']])['s'];
        }
        $cancelled = $project['status'] === 'cancelled';
        $full = $total !== null && $total > 0 && $received > 0 && $received >= $total - (int) $rules['full_payment_tolerance_minor'];
        $minBps = (int) $rules['advance_min_bps'];
        $threshold = $minBps > 0 ? ($total === null ? null : max(1, intdiv($total * $minBps + 9999, 10000))) : 1;
        $m1 = ($threshold !== null && $advance >= $threshold) || ($full && !$cancelled);
        if ($cancelled && $rules['advance_without_completion'] === 'reverse') {
            $m1 = false;
        }

        return ['eligible' => $eligible, 'advance' => $advance, 'received' => $received, 'total' => $total, 'threshold' => $threshold, 'full' => $full, 'cancelled' => $cancelled, 'm1' => $m1, 'm2' => $full && !$cancelled];
    }

    /** @param array<string,mixed> $c @return array<string,mixed> */
    private static function inputs(array $c): array
    {
        return ['contract_value_minor' => $c['total'], 'received_net_of_refunds_minor' => $c['received'], 'advance_eligible_minor' => $c['advance'], 'advance_threshold_minor' => $c['threshold'],
            'full_payment' => $c['full'], 'project_cancelled' => $c['cancelled'], 'payments' => $c['eligible']];
    }

    /**
     * The rules an entry is valued under: the snapshot it was calculated with, or the rules in force now while it is still Pending Eligibility.
     * This is why a later rule change never rewrites an existing entry.
     *
     * @param array<string,mixed> $entry
     * @param array{version:int,rules:array<string,mixed>,source:string} $cfg
     * @return array<string,mixed>
     */
    private static function rulesOf(array $entry, array $cfg): array
    {
        if ($entry['status'] === 'pending_eligibility' || $entry['formula_json'] === null) {
            return $cfg['rules'];
        }
        $snap = json_decode((string) $entry['formula_json'], true);

        return is_array($snap['rules'] ?? null) ? array_replace((array) Config::get('referrals.rules'), $snap['rules']) : $cfg['rules'];
    }

    /**
     * A calculated entry that can no longer carry a commission (reversed, or every payment behind it was refunded) is replaced by a new generation when
     * qualifying payments exist again. The closed entry stays in the ledger.
     *
     * @param array<string,mixed> $entry
     * @param array<int,int> $eligible
     */
    private static function closed(array $entry, array $eligible): bool
    {
        if ($entry['status'] === 'reversed') {
            return true;
        }
        if (in_array($entry['status'], ['pending_eligibility', 'eligible_for_review'], true) || (int) $entry['amount_minor'] <= 0) {
            return false;
        }
        $stored = array_column(Database::all('SELECT payment_id FROM commission_entry_payments WHERE entry_id = ?', [$entry['id']]), 'payment_id');
        foreach ($stored as $id) {
            if (($eligible[(int) $id] ?? 0) > 0) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string,mixed> $payment @param array<string,mixed> $rules */
    private static function eligible(array $payment, array $rules): int
    {
        if ($payment['status'] !== 'verified') {
            return 0;
        }
        $gross = (int) $payment['gross_minor'];
        $net = !empty($rules['net_of_refunds']) ? max(0, $gross - (int) $payment['refunded_minor']) : $gross;
        if (!empty($rules['exclude_tax']) && $gross > 0) {
            return Money::mulDiv($net, $gross - (int) $payment['tax_minor'], $gross);
        }

        return $net;
    }

    /**
     * The payments (id => eligible amount NOW) that make up an entry. Before the first calculation: every payment with an eligible amount (minus $exclude).
     * Afterwards: exactly the payments stored with the entry, re-valued, so a refund reduces them but a later payment never silently joins.
     *
     * @param array<string,mixed>|null $entry
     * @param array<int,int> $eligible
     * @param list<int>|null $exclude
     * @return array<int,int>
     */
    private static function paymentSet(?array $entry, array $eligible, ?array $exclude): array
    {
        $stored = $entry === null || $entry['status'] === 'pending_eligibility' ? [] : array_column(Database::all('SELECT payment_id FROM commission_entry_payments WHERE entry_id = ?', [$entry['id']]), 'payment_id');
        if ($stored !== []) {
            $out = [];
            foreach ($stored as $id) {
                $out[(int) $id] = $eligible[(int) $id] ?? 0;
            }

            return $out;
        }
        $out = [];
        foreach ($eligible as $id => $e) {
            if ($e > 0 && ($exclude === null || !in_array($id, $exclude, true))) {
                $out[$id] = $e;
            }
        }

        return $out;
    }

    /** Original calculation plus its adjustment lines. @param array<string,mixed> $entry */
    private static function net(array $entry): int
    {
        return (int) $entry['amount_minor'] + (int) Database::one("SELECT COALESCE(SUM(amount_minor), 0) AS s FROM commission_entries WHERE parent_entry_id = ? AND status <> 'reversed'", [$entry['id']])['s'];
    }

    /** @param array<string,mixed> $ref @param array<string,mixed> $project @return array<string,mixed> */
    private static function createEntry(array $ref, array $project, string $milestone, int $seq, ?int $actorId): array
    {
        $reference = CrmReference::next('commission');
        Database::run(
            "INSERT INTO commission_entries (public_id, reference, partner_id, referral_id, client_id, project_id, milestone, seq, status, created_by, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending_eligibility', ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())",
            [uuid_hex(), $reference, $ref['partner_id'], $ref['id'], $project['client_id'], $project['id'], $milestone, $seq, $actorId],
        );
        $id = Database::insertId();
        self::history($id, 'created', null, ['status' => 'pending_eligibility'], null, $actorId);

        return Database::one('SELECT * FROM commission_entries WHERE id = ?', [$id]);
    }

    /**
     * Brings one milestone entry to the amount the facts imply. See the class comment: before approval the entry is recalculated in place (history keeps
     * before/after); after approval the original amount is never edited and the difference becomes an adjustment line.
     *
     * @param array<string,mixed> $entry
     * @param array<string,mixed> $ref
     * @param array<int,int> $set
     * @param array<string,mixed> $formula
     * @param array{version:int,rules:array<string,mixed>,source:string} $cfg
     * @param array<string,mixed> $rulesUsed
     * @return array<string,mixed>
     */
    private static function settle(array $entry, array $ref, int $should, int $base, array $set, int $rateBps, array $formula, array $cfg, array $rulesUsed, string $why, ?int $actorId): array
    {
        $status = $entry['status'];
        $version = $entry['status'] === 'pending_eligibility' || $entry['rules_version'] === null ? $cfg['version'] : (int) $entry['rules_version'];
        $json = json_encode($formula + ['rules_version' => $version, 'rules' => $rulesUsed], JSON_THROW_ON_ERROR);
        if ($status === 'reversed') {
            return $entry;
        }
        if ($status === 'pending_eligibility') {
            if ($should <= 0) {
                return $entry;
            }
            Database::run("UPDATE commission_entries SET status = 'eligible_for_review', rate_bps = ?, base_minor = ?, amount_minor = ?, rules_version = ?, formula_json = ?, calculated_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP(),
                           partner_note = NULL WHERE id = ?", [$rateBps, $base, $should, $version, $json, $entry['id']]);
            self::storeSet((int) $entry['id'], $set);
            Database::run('UPDATE partner_referrals SET attribution_locked = 1, updated_at = UTC_TIMESTAMP() WHERE id = ?', [$ref['id']]);
            self::history((int) $entry['id'], 'calculated', ['status' => 'pending_eligibility'], ['status' => 'eligible_for_review', 'amount_minor' => $should, 'base_minor' => $base, 'rate_bps' => $rateBps, 'payments' => $set], 'Milestone reached', $actorId);
            Audit::record('commission.calculated', 'commission_entry', (int) $entry['id'], [], ['reference' => $entry['reference'], 'milestone' => $entry['milestone'], 'base_minor' => $base, 'rate_bps' => $rateBps, 'amount_minor' => $should, 'rules_version' => $version],
                ['partner_id' => (int) $entry['partner_id'], 'referral_id' => (int) $entry['referral_id'], 'project_id' => (int) $entry['project_id']], $actorId);
            Notifier::send([(int) Database::one('SELECT user_id FROM referral_partners WHERE id = ?', [$entry['partner_id']])['user_id']], 'commission.milestone', 'Commission milestone reached',
                $entry['reference'] . ': our team will review it before it becomes payable.', '/partner/commissions', null, 'commission_entry', (int) $entry['id'], "commission:{$entry['id']}:calculated");
            Notifier::send(Notifier::withPermission('commissions.approve'), 'commission.review_needed', 'A commission awaits review', $entry['reference'], '/commissions/' . $entry['public_id'], null, 'commission_entry', (int) $entry['id'], "commission:{$entry['id']}:review", $actorId);

            return Database::one('SELECT * FROM commission_entries WHERE id = ?', [$entry['id']]);
        }
        if ($status === 'eligible_for_review') {
            $oldSet = array_column(Database::all('SELECT payment_id, eligible_minor FROM commission_entry_payments WHERE entry_id = ?', [$entry['id']]), 'eligible_minor', 'payment_id');
            if ((int) $entry['amount_minor'] === $should && (int) $entry['base_minor'] === $base && $oldSet == $set) {
                return $entry;
            }
            $to = $should > 0 ? 'eligible_for_review' : 'pending_eligibility';
            Database::run('UPDATE commission_entries SET status = ?, base_minor = ?, amount_minor = ?, rules_version = ?, formula_json = ?, calculated_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE id = ?',
                [$to, $base, $should, $version, $json, $entry['id']]);
            self::storeSet((int) $entry['id'], $set);
            self::history((int) $entry['id'], 'recalculated', ['amount_minor' => (int) $entry['amount_minor'], 'base_minor' => (int) $entry['base_minor'], 'payments' => $oldSet], ['amount_minor' => $should, 'base_minor' => $base, 'payments' => $set], $why, $actorId);
            Audit::record('commission.recalculated', 'commission_entry', (int) $entry['id'], ['amount_minor' => (int) $entry['amount_minor']], ['amount_minor' => $should], ['reason' => 'payment_change'], $actorId);

            return Database::one('SELECT * FROM commission_entries WHERE id = ?', [$entry['id']]);
        }

        // approved / payable / on_hold / payout_requested / paid: the original is never edited
        $net = self::net($entry);
        $delta = $should - $net;
        if ($delta === 0) {
            return $entry;
        }
        $allocated = (int) Database::one('SELECT COALESCE(SUM(amount_minor), 0) AS s FROM payout_allocations WHERE entry_id = ? AND released_at IS NULL', [$entry['id']])['s'];
        $hasChildren = Database::one('SELECT 1 AS x FROM commission_entries WHERE parent_entry_id = ?', [$entry['id']]) !== null;
        if ($should === 0 && !$hasChildren && $allocated === 0 && in_array($status, ['approved', 'payable', 'on_hold'], true)) {
            self::reverseUnpaid($entry, $why, 'The related client payment changed, so this commission no longer applies.', $actorId);

            return Database::one('SELECT * FROM commission_entries WHERE id = ?', [$entry['id']]);
        }
        self::addAdjustment($entry, $delta, $why, $delta < 0 ? 'Adjusted because a related client payment changed.' : 'Adjusted after a recalculation; awaiting review.', ['formula' => $formula['formula'] ?? null, 'base_minor' => $base, 'should_minor' => $should, 'net_before_minor' => $net], $actorId);

        return $entry;
    }

    /** @param array<int,int> $set */
    private static function storeSet(int $entryId, array $set): void
    {
        Database::run('DELETE FROM commission_entry_payments WHERE entry_id = ?', [$entryId]);
        foreach ($set as $paymentId => $amount) {
            Database::run('INSERT INTO commission_entry_payments (entry_id, payment_id, eligible_minor) VALUES (?, ?, ?)', [$entryId, $paymentId, $amount]);
        }
    }

    /** @param array<string,mixed> $entry */
    private static function reverseUnpaid(array $entry, string $reason, string $partnerNote, ?int $actorId): void
    {
        Database::run("UPDATE commission_entries SET status = 'reversed', held_from = NULL, reason = ?, partner_note = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?", [mb_substr($reason, 0, 500), $partnerNote, $entry['id']]);
        self::history((int) $entry['id'], 'reversed', ['status' => $entry['status'], 'amount_minor' => (int) $entry['amount_minor']], ['status' => 'reversed'], $reason, $actorId);
        Audit::record('commission.reversed', 'commission_entry', (int) $entry['id'], ['status' => $entry['status'], 'amount_minor' => (int) $entry['amount_minor']], ['status' => 'reversed'], ['reason_recorded' => true], $actorId);
        self::notifyPartner((int) $entry['partner_id'], 'commission.reversed', 'A commission was reversed', $entry['reference'] . ': ' . $partnerNote, "commission:{$entry['id']}:reversed");
    }

    /**
     * An adjustment LINE linked to its parent. Negative lines are payable immediately (they reduce what the partner can withdraw, even below zero:
     * the debt is carried against future commissions); positive lines are Eligible for Review and need approval like any earning.
     *
     * @param array<string,mixed> $parent
     * @param array<string,mixed> $formula
     */
    private static function addAdjustment(array $parent, int $delta, string $reason, string $partnerNote, array $formula, ?int $actorId): int
    {
        $seq = (int) Database::one("SELECT COALESCE(MAX(seq), 0) + 1 AS n FROM commission_entries WHERE referral_id = ? AND milestone = 'adjustment'", [$parent['referral_id']])['n'];
        $reference = CrmReference::next('commission');
        Database::run(
            "INSERT INTO commission_entries (public_id, reference, partner_id, referral_id, client_id, project_id, milestone, seq, parent_entry_id, status, rate_bps, base_minor, amount_minor, rules_version, formula_json,
                    reason, partner_note, calculated_at, created_by, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, 'adjustment', ?, ?, ?, 0, 0, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())",
            [uuid_hex(), $reference, $parent['partner_id'], $parent['referral_id'], $parent['client_id'], $parent['project_id'], $seq, $parent['id'], $delta < 0 ? 'payable' : 'eligible_for_review', $delta, $parent['rules_version'],
                json_encode(['adjusts' => $parent['reference']] + $formula, JSON_THROW_ON_ERROR), mb_substr($reason, 0, 500), $partnerNote, $actorId],
        );
        $id = Database::insertId();
        self::history($id, 'created', null, ['status' => $delta < 0 ? 'payable' : 'eligible_for_review', 'amount_minor' => $delta, 'parent' => $parent['reference']], $reason, $actorId);
        self::history((int) $parent['id'], 'adjusted', ['net_minor' => self::net($parent) - $delta], ['net_minor' => self::net($parent)], $reason, $actorId);
        Audit::record('commission.adjusted', 'commission_entry', (int) $parent['id'], [], ['adjustment_reference' => $reference, 'amount_minor' => $delta], ['partner_id' => (int) $parent['partner_id'], 'reason_recorded' => true], $actorId);
        self::notifyPartner((int) $parent['partner_id'], 'commission.adjusted', 'A commission was adjusted', $parent['reference'] . ': ' . $partnerNote, "commission:{$id}:adjusted");

        return $id;
    }

    // ------------------------------------------------------------------------------------------------ ledger operations

    /** @param array<string,mixed> $e */
    public static function approve(int $actorId, array $e): void
    {
        Gate::authorize('commissions.approve', $actorId);
        Database::transaction(function () use ($actorId, $e): void {
            $row = Database::one('SELECT * FROM commission_entries WHERE id = ? FOR UPDATE', [$e['id']]);
            if ($row === null || $row['status'] !== 'eligible_for_review') {
                throw new ValidationException(['Only an entry that is Eligible for Review can be approved.']);
            }
            $rules = CommissionRules::current()['rules'];
            if (!empty($rules['separation_of_duties'])) {
                $people = Database::all('SELECT DISTINCT p.recorded_by, p.verified_by FROM commission_entry_payments cep JOIN client_payments p ON p.id = cep.payment_id WHERE cep.entry_id = ?', [$row['id']]);
                foreach ($people as $p) {
                    if ((int) $p['recorded_by'] === $actorId || (int) $p['verified_by'] === $actorId) {
                        throw new ValidationException(['You recorded or verified a payment behind this commission, so someone else must approve it.']);
                    }
                }
                if ((int) $row['created_by'] === $actorId && $row['milestone'] === 'adjustment') {
                    throw new ValidationException(['The person who created an adjustment cannot approve it.']);
                }
            }
            $hold = (int) $rules['payable_hold_days'];
            $to = $hold > 0 ? 'approved' : 'payable';
            Database::run('UPDATE commission_entries SET status = ?, approved_by = ?, approved_at = UTC_TIMESTAMP(), payable_after = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?', [$to, $actorId, $hold > 0 ? AppTime::todayPlus($hold) : null, $row['id']]);
            self::history((int) $row['id'], 'approved', ['status' => 'eligible_for_review'], ['status' => $to, 'payable_after' => $hold > 0 ? AppTime::todayPlus($hold) : null], null, $actorId);
            Audit::record('commission.approved', 'commission_entry', (int) $row['id'], ['status' => 'eligible_for_review'], ['status' => $to, 'amount_minor' => (int) $row['amount_minor']], ['partner_id' => (int) $row['partner_id']], $actorId);
            self::notifyPartner((int) $row['partner_id'], 'commission.approved', 'A commission was approved', $row['reference'] . ($to === 'payable' ? ': it is now payable.' : ': it becomes payable on ' . AppTime::todayPlus($hold) . '.'), "commission:{$row['id']}:approved");
        });
    }

    /** @param array<string,mixed> $e */
    public static function hold(int $actorId, array $e, string $reason, string $partnerNote = ''): void
    {
        Gate::authorize('commissions.approve', $actorId);
        $reason = (string) FieldRules::text($reason, 'Reason (internal)', 10, 500);
        $partnerNote = (string) FieldRules::line($partnerNote, 'Note for the partner', 5, 255, false);
        Database::transaction(function () use ($actorId, $e, $reason, $partnerNote): void {
            $row = Database::one('SELECT * FROM commission_entries WHERE id = ? FOR UPDATE', [$e['id']]);
            if ($row === null || !in_array($row['status'], ['eligible_for_review', 'approved', 'payable'], true) || $row['milestone'] === 'adjustment' && (int) $row['amount_minor'] < 0) {
                throw new ValidationException(['This entry cannot be put on hold in its current state.']);
            }
            if ((int) Database::one('SELECT COALESCE(SUM(amount_minor), 0) AS s FROM payout_allocations WHERE entry_id = ? AND released_at IS NULL', [$row['id']])['s'] !== 0) {
                throw new ValidationException(['Part of this entry is reserved by a payout request. Resolve the payout first.']);
            }
            Database::run("UPDATE commission_entries SET status = 'on_hold', held_from = ?, reason = ?, partner_note = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?", [$row['status'], $reason, $partnerNote === '' ? null : $partnerNote, $row['id']]);
            self::history((int) $row['id'], 'hold', ['status' => $row['status']], ['status' => 'on_hold'], $reason, $actorId);
            Audit::record('commission.held', 'commission_entry', (int) $row['id'], ['status' => $row['status']], ['status' => 'on_hold'], ['reason_recorded' => true], $actorId);
            self::notifyPartner((int) $row['partner_id'], 'commission.held', 'A commission is on hold', $row['reference'] . ': ' . ($partnerNote !== '' ? $partnerNote : 'our team is checking it.'), "commission:{$row['id']}:held:" . self::eventCount((int) $row['id']));
        });
    }

    /** @param array<string,mixed> $e */
    public static function release(int $actorId, array $e): void
    {
        Gate::authorize('commissions.approve', $actorId);
        Database::transaction(function () use ($actorId, $e): void {
            $row = Database::one('SELECT * FROM commission_entries WHERE id = ? FOR UPDATE', [$e['id']]);
            if ($row === null || $row['status'] !== 'on_hold') {
                throw new ValidationException(['This entry is not on hold.']);
            }
            $to = in_array($row['held_from'], ['eligible_for_review', 'approved', 'payable'], true) ? (string) $row['held_from'] : 'eligible_for_review';
            if ($to === 'approved' && $row['payable_after'] !== null && $row['payable_after'] <= AppTime::today()) {
                $to = 'payable';
            }
            Database::run('UPDATE commission_entries SET status = ?, held_from = NULL, partner_note = NULL, updated_at = UTC_TIMESTAMP() WHERE id = ?', [$to, $row['id']]);
            self::history((int) $row['id'], 'released', ['status' => 'on_hold'], ['status' => $to], null, $actorId);
            Audit::record('commission.released', 'commission_entry', (int) $row['id'], ['status' => 'on_hold'], ['status' => $to], [], $actorId);
        });
    }

    /**
     * A manual correction line (sensitive; reason required). A negative line is payable at once; a positive one needs approval.
     *
     * @param array<string,mixed> $e
     */
    public static function adjust(int $actorId, array $e, int $deltaMinor, string $reason): int
    {
        Gate::authorize('commissions.adjust', $actorId);
        $reason = (string) FieldRules::text($reason, 'Reason', 10, 500);
        if ($deltaMinor === 0 || abs($deltaMinor) > 99_999_999_999_900) {
            throw new ValidationException(['Enter a non-zero adjustment amount.']);
        }

        return Database::transaction(function () use ($actorId, $e, $deltaMinor, $reason): int {
            $row = Database::one('SELECT * FROM commission_entries WHERE id = ? FOR UPDATE', [$e['id']]);
            if ($row === null || $row['milestone'] === 'adjustment' || in_array($row['status'], ['pending_eligibility', 'reversed', 'eligible_for_review'], true)) {
                throw new ValidationException(['Only an approved, payable, held or paid commission entry can be adjusted. An entry still under review is recalculated instead.']);
            }
            if ($deltaMinor < 0 && -$deltaMinor > self::net($row)) {
                throw new ValidationException(['The adjustment cannot be larger than the entry\'s current value. Use a reversal.']);
            }

            return self::addAdjustment($row, $deltaMinor, $reason, 'Adjusted after a review by our team.', ['formula' => 'manual adjustment', 'manual' => true], $actorId);
        });
    }

    /** Full reversal of an entry that has not been paid or reserved; a PAID entry gets a negative line for its whole net value. @param array<string,mixed> $e */
    public static function reverse(int $actorId, array $e, string $reason): void
    {
        Gate::authorize('commissions.adjust', $actorId);
        $reason = (string) FieldRules::text($reason, 'Reason', 10, 500);
        Database::transaction(function () use ($actorId, $e, $reason): void {
            $row = Database::one('SELECT * FROM commission_entries WHERE id = ? FOR UPDATE', [$e['id']]);
            if ($row === null || $row['milestone'] === 'adjustment' || in_array($row['status'], ['pending_eligibility', 'reversed'], true)) {
                throw new ValidationException(['This entry cannot be reversed.']);
            }
            $allocated = (int) Database::one('SELECT COALESCE(SUM(amount_minor), 0) AS s FROM payout_allocations WHERE entry_id = ? AND released_at IS NULL', [$row['id']])['s'];
            if ($row['status'] === 'paid' || $allocated !== 0) {
                if ($allocated !== 0 && $row['status'] !== 'paid' && Database::one("SELECT 1 AS x FROM payout_allocations a JOIN payout_requests p ON p.id = a.payout_id WHERE a.entry_id = ? AND a.released_at IS NULL AND p.status <> 'paid'", [$row['id']]) !== null) {
                    throw new ValidationException(['Part of this entry is reserved by an open payout request. Reject or cancel that request first.']);
                }
                $net = self::net($row);
                if ($net > 0) {
                    self::addAdjustment($row, -$net, $reason, 'Reversed after a review by our team; the amount is carried against future commissions.', ['formula' => 'reversal of a paid commission', 'manual' => true], $actorId);
                }

                return;
            }
            // unpaid and unreserved: close the entry together with its (unpaid) adjustment lines so nothing is left counting in the balance
            foreach (Database::all("SELECT * FROM commission_entries WHERE parent_entry_id = ? AND status <> 'reversed' FOR UPDATE", [$row['id']]) as $child) {
                if ((int) Database::one('SELECT COALESCE(SUM(amount_minor), 0) AS s FROM payout_allocations WHERE entry_id = ? AND released_at IS NULL', [$child['id']])['s'] !== 0) {
                    throw new ValidationException(['An adjustment on this entry is already part of a payout. Resolve the payout first.']);
                }
                self::reverseUnpaid($child, $reason, 'Reversed together with ' . $row['reference'] . '.', $actorId);
            }
            self::reverseUnpaid($row, $reason, 'Reversed after a review by our team.', $actorId);
        });
    }

    /** Approved entries whose hold period has passed become Payable. */
    public static function releaseDue(int $partnerId): void
    {
        foreach (Database::all("SELECT id, status FROM commission_entries WHERE partner_id = ? AND status = 'approved' AND payable_after IS NOT NULL AND payable_after <= ? FOR UPDATE", [$partnerId, AppTime::today()]) as $row) {
            Database::run("UPDATE commission_entries SET status = 'payable', updated_at = UTC_TIMESTAMP() WHERE id = ? AND status = 'approved'", [$row['id']]);
            self::history((int) $row['id'], 'payable', ['status' => 'approved'], ['status' => 'payable'], 'Hold period ended', null);
        }
    }

    /**
     * What the partner can withdraw and the rest of the picture, in paise.
     *
     * @return array{available:int,reserved:int,paid:int,pending_eligibility:int,eligible_for_review:int,approved:int,on_hold:int,pending_payouts:int}
     */
    public static function balance(int $partnerId): array
    {
        $one = static fn (string $sql, array $p = []): int => (int) Database::one($sql, $p)['s'];
        $available = $one("SELECT COALESCE(SUM(e.amount_minor - COALESCE(a.alloc, 0)), 0) AS s FROM commission_entries e
                             LEFT JOIN (SELECT entry_id, SUM(amount_minor) AS alloc FROM payout_allocations WHERE released_at IS NULL GROUP BY entry_id) a ON a.entry_id = e.id
                            WHERE e.partner_id = ? AND e.status IN ('payable','payout_requested')", [$partnerId]);
        $sum = static fn (string $status): int => $one('SELECT COALESCE(SUM(amount_minor), 0) AS s FROM commission_entries WHERE partner_id = ? AND status = ?', [$partnerId, $status]);

        return [
            'available' => $available,
            'reserved' => $one("SELECT COALESCE(SUM(amount_minor), 0) AS s FROM payout_requests WHERE partner_id = ? AND status IN ('requested','under_review','approved','processing')", [$partnerId]),
            'paid' => $one("SELECT COALESCE(SUM(paid_amount_minor), 0) AS s FROM payout_requests WHERE partner_id = ? AND status = 'paid'", [$partnerId]),
            'pending_payouts' => $one("SELECT COUNT(*) AS s FROM payout_requests WHERE partner_id = ? AND status IN ('requested','under_review','approved','processing')", [$partnerId]),
            'pending_eligibility' => (int) Database::one("SELECT COUNT(*) AS s FROM commission_entries WHERE partner_id = ? AND status = 'pending_eligibility' AND milestone <> 'adjustment'", [$partnerId])['s'],
            'eligible_for_review' => $sum('eligible_for_review'),
            'approved' => $sum('approved'),
            'on_hold' => $sum('on_hold'),
        ];
    }

    /** Recomputes an entry's payable / payout_requested / paid status from its allocations (called by PayoutService inside its transaction). */
    public static function syncStatus(int $entryId): void
    {
        $e = Database::one('SELECT id, amount_minor, status FROM commission_entries WHERE id = ? FOR UPDATE', [$entryId]);
        if ($e === null || !in_array($e['status'], ['payable', 'payout_requested', 'paid'], true)) {
            return;
        }
        $alloc = (int) Database::one('SELECT COALESCE(SUM(amount_minor), 0) AS s FROM payout_allocations WHERE entry_id = ? AND released_at IS NULL', [$entryId])['s'];
        $to = 'payable';
        if ($alloc !== 0 && $alloc === (int) $e['amount_minor']) {
            $open = Database::one("SELECT 1 AS x FROM payout_allocations a JOIN payout_requests p ON p.id = a.payout_id WHERE a.entry_id = ? AND a.released_at IS NULL AND p.status <> 'paid'", [$entryId]);
            $to = $open === null ? 'paid' : 'payout_requested';
        }
        if ($to !== $e['status']) {
            Database::run('UPDATE commission_entries SET status = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?', [$to, $entryId]);
            self::history($entryId, 'payout_state', ['status' => $e['status']], ['status' => $to], null, null);
        }
    }

    // ------------------------------------------------------------------------------------------------ read side

    /** @return array<string,mixed>|null */
    public static function find(string $publicId): ?array
    {
        return preg_match('/^[a-f0-9]{32}$/', $publicId) ? Database::one(
            'SELECT e.*, pu.name AS partner_name, p.public_id AS partner_public_id, r.public_id AS referral_public_id, r.reference AS referral_reference, r.prospect_name,
                    pj.public_id AS project_public_id, pj.code AS project_code, pj.title AS project_title, pj.approved_amount_minor, c.contact_name AS client_name, ab.name AS approved_by_name,
                    par.reference AS parent_reference
               FROM commission_entries e JOIN referral_partners p ON p.id = e.partner_id JOIN users pu ON pu.id = p.user_id JOIN partner_referrals r ON r.id = e.referral_id
               LEFT JOIN projects pj ON pj.id = e.project_id LEFT JOIN clients c ON c.id = e.client_id LEFT JOIN users ab ON ab.id = e.approved_by LEFT JOIN commission_entries par ON par.id = e.parent_entry_id
              WHERE e.public_id = ?',
            [$publicId],
        ) : null;
    }

    /** @return array<string,mixed> */
    public static function requireView(int $userId, string $publicId): array
    {
        $e = Gate::can('commissions.view_all', $userId) ? self::find($publicId) : null;
        if ($e === null) {
            throw new HttpException(404);
        }

        return $e;
    }

    /** @param array<string,mixed> $e @return array{payments:list<array<string,mixed>>,history:list<array<string,mixed>>,notes:list<array<string,mixed>>,children:list<array<string,mixed>>} */
    public static function detail(int $userId, array $e): array
    {
        if (!Gate::can('commissions.view_all', $userId)) {
            return ['payments' => [], 'history' => [], 'notes' => [], 'children' => []];
        }

        return [
            'payments' => Database::all('SELECT cp.public_id, cp.reference, cp.invoice_ref, cp.payment_role, cp.gross_minor, cp.refunded_minor, cp.status, cep.eligible_minor FROM commission_entry_payments cep JOIN client_payments cp ON cp.id = cep.payment_id WHERE cep.entry_id = ? ORDER BY cp.id', [$e['id']]),
            'history' => Database::all('SELECT h.event, h.before_json, h.after_json, h.reason, h.created_at, u.name AS actor FROM commission_history h LEFT JOIN users u ON u.id = h.actor_id WHERE h.entry_id = ? ORDER BY h.id', [$e['id']]),
            'notes' => Database::all("SELECT n.body, n.created_at, u.name AS author FROM partner_notes n LEFT JOIN users u ON u.id = n.author_id WHERE n.subject_type = 'commission' AND n.subject_id = ? ORDER BY n.id", [$e['id']]),
            'children' => Database::all('SELECT public_id, reference, status, amount_minor, reason, created_at FROM commission_entries WHERE parent_entry_id = ? ORDER BY id', [$e['id']]),
        ];
    }

    /** @param array<string,mixed> $e */
    public static function addNote(int $actorId, array $e, string $body): void
    {
        Gate::authorize('commissions.view_all', $actorId);
        $body = (string) FieldRules::text($body, 'Note', 2, 3000);
        Database::transaction(function () use ($actorId, $e, $body): void {
            Database::run("INSERT INTO partner_notes (subject_type, subject_id, author_id, body, created_at) VALUES ('commission', ?, ?, ?, UTC_TIMESTAMP())", [$e['id'], $actorId, $body]);
            Audit::record('commission.note_added', 'commission_entry', (int) $e['id'], [], ['length' => mb_strlen($body)], [], $actorId);
        });
    }

    /**
     * @param array{q?:string,status?:string,milestone?:string,partner?:string,from?:string,to?:string,review?:bool} $f
     * @return array{rows:list<array<string,mixed>>,total:int}
     */
    public static function paginate(int $userId, array $f, int $page = 1, int $perPage = 20): array
    {
        if (!Gate::can('commissions.view_all', $userId)) {
            return ['rows' => [], 'total' => 0];
        }
        $w = ['1=1'];
        $p = [];
        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
            $w[] = '(e.reference LIKE ? OR pu.name LIKE ? OR r.reference LIKE ? OR pj.code LIKE ?)';
            array_push($p, $like, $like, $like, $like);
        }
        if (array_key_exists((string) ($f['status'] ?? ''), (array) Config::get('referrals.commission_statuses'))) {
            $w[] = 'e.status = ?';
            $p[] = $f['status'];
        }
        if (in_array($f['milestone'] ?? '', ['advance', 'full', 'adjustment'], true)) {
            $w[] = 'e.milestone = ?';
            $p[] = $f['milestone'];
        }
        if (preg_match('/^[a-f0-9]{32}$/', (string) ($f['partner'] ?? ''))) {
            $w[] = 'p.public_id = ?';
            $p[] = $f['partner'];
        }
        if (!empty($f['review'])) {
            $w[] = "e.status = 'eligible_for_review'";
        }
        [$w, $p] = PartnerApplicationService::dateRange($w, $p, 'e.created_at', $f);
        $cond = implode(' AND ', $w);
        $base = 'FROM commission_entries e JOIN referral_partners p ON p.id = e.partner_id JOIN users pu ON pu.id = p.user_id JOIN partner_referrals r ON r.id = e.referral_id LEFT JOIN projects pj ON pj.id = e.project_id';
        $total = (int) Database::one("SELECT COUNT(*) AS c {$base} WHERE {$cond}", $p)['c'];
        $offset = (int) min(max(0, ($page - 1) * $perPage), 2_000_000_000);
        $rows = Database::all("SELECT e.public_id, e.reference, e.milestone, e.status, e.rate_bps, e.base_minor, e.amount_minor, e.created_at, pu.name AS partner_name, r.reference AS referral_reference, pj.code AS project_code
                               {$base} WHERE {$cond} ORDER BY (e.status = 'eligible_for_review') DESC, e.id DESC LIMIT {$perPage} OFFSET {$offset}", $p);

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * The signed-in partner's own ledger, shaped for a partner: no payment, invoice, project price or internal reason.
     *
     * @return array{rows:list<array<string,mixed>>,total:int}
     */
    public static function paginateOwn(int $userId, int $page = 1, int $perPage = 20): array
    {
        $partner = PartnerService::require($userId, 'commissions.view_own');
        $total = (int) Database::one('SELECT COUNT(*) AS c FROM commission_entries WHERE partner_id = ?', [$partner['id']])['c'];
        $offset = (int) min(max(0, ($page - 1) * $perPage), 2_000_000_000);
        $rows = Database::all(
            "SELECT e.reference, e.milestone, e.status, e.rate_bps, e.base_minor, e.amount_minor, e.partner_note, e.payable_after, e.created_at, r.reference AS referral_reference, r.prospect_name, r.attribution
               FROM commission_entries e JOIN partner_referrals r ON r.id = e.referral_id WHERE e.partner_id = ? ORDER BY e.id DESC LIMIT {$perPage} OFFSET {$offset}",
            [$partner['id']],
        );
        foreach ($rows as &$r) {
            $r['explanation'] = self::explain($r);
            unset($r['attribution'], $r['partner_note']);
        }

        return ['rows' => $rows, 'total' => $total];
    }

    /** Plain-language reason shown to the partner. It never contains internal reasons, payment or invoice details. @param array<string,mixed> $r */
    public static function explain(array $r): string
    {
        $m = $r['milestone'];

        return match ($r['status']) {
            'pending_eligibility' => ($r['attribution'] ?? 'credited') !== 'credited'
                ? 'Waiting for our team to confirm that you are credited for this referral.'
                : ($m === 'advance' ? 'Waiting for the client\'s advance payment to be received and verified.' : 'Waiting for the client to complete the full payment.'),
            'eligible_for_review' => 'The milestone was reached. Our team is checking it before it becomes payable.',
            'approved' => 'Approved. It becomes payable' . ($r['payable_after'] ? ' on ' . $r['payable_after'] : ' shortly') . '.',
            'payable' => 'Payable: it counts toward your available balance.',
            'payout_requested' => 'Included in a payout request.',
            'paid' => 'Paid.',
            'on_hold' => $r['partner_note'] ?: 'Temporarily on hold while our team checks it.',
            'adjusted' => $r['partner_note'] ?: 'Adjusted after a change to a related client payment.',
            'reversed' => $r['partner_note'] ?: 'Reversed.',
            default => '',
        } . ($m === 'adjustment' && $r['partner_note'] ? ' ' . $r['partner_note'] : '');
    }

    /** @return array<string,int> counts for the staff dashboard */
    public static function dashboard(int $userId): array
    {
        if (!Gate::can('commissions.view_all', $userId)) {
            return [];
        }
        $c = static fn (string $sql): int => (int) Database::one($sql)['c'];

        return [
            'awaiting_review' => $c("SELECT COUNT(*) AS c FROM commission_entries WHERE status = 'eligible_for_review'"),
            'on_hold' => $c("SELECT COUNT(*) AS c FROM commission_entries WHERE status = 'on_hold'"),
            'eligible_balance_minor' => $c("SELECT COALESCE(SUM(amount_minor), 0) AS c FROM commission_entries WHERE status IN ('payable','payout_requested')")
                - $c("SELECT COALESCE(SUM(amount_minor), 0) AS c FROM payout_allocations WHERE released_at IS NULL"),
            'adjustments' => $c("SELECT COUNT(*) AS c FROM commission_entries WHERE milestone = 'adjustment'"),
            'reversals' => $c("SELECT COUNT(*) AS c FROM commission_entries WHERE status = 'reversed'"),
        ];
    }

    // ------------------------------------------------------------------------------------------------ internals

    /** @param array<string,mixed>|null $before @param array<string,mixed>|null $after */
    private static function history(int $entryId, string $event, ?array $before, ?array $after, ?string $reason, ?int $actorId): void
    {
        Database::run('INSERT INTO commission_history (entry_id, event, before_json, after_json, reason, actor_id, created_at) VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())',
            [$entryId, $event, $before === null ? null : json_encode($before), $after === null ? null : json_encode($after), $reason === null ? null : mb_substr($reason, 0, 500), $actorId]);
    }

    private static function eventCount(int $entryId): int
    {
        return (int) Database::one('SELECT COUNT(*) AS c FROM commission_history WHERE entry_id = ?', [$entryId])['c'];
    }

    private static function notifyPartner(int $partnerId, string $event, string $title, string $body, string $dedupe): void
    {
        $uid = Database::one('SELECT user_id FROM referral_partners WHERE id = ?', [$partnerId]);
        if ($uid !== null) {
            Notifier::send([(int) $uid['user_id']], $event, $title, $body, '/partner/commissions', null, 'commission', $partnerId, $dedupe);
        }
    }
}
