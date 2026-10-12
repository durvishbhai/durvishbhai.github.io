<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Config;
use Vertex\Core\Database;
use Vertex\Core\HttpException;
use Vertex\Core\ValidationException;

/**
 * Payout requests and their manual payment.
 *
 *  - The partner can request up to their AVAILABLE balance (payable commission minus amounts already reserved by open requests, plus any carried negative
 *    adjustment). The request transaction first locks the partner row, so concurrent requests are serialised and can never reserve the same money twice.
 *  - A request RESERVES part of specific ledger entries (payout_allocations, oldest first; negative adjustment lines are netted first). Rejecting or
 *    cancelling releases the reservation; paying consumes it.
 *  - APPROVING is not paying: approve (payouts.approve) and record the payment (payouts.record) are separate steps by separate permissions, and while
 *    separation of duties is on, by separate people. A payout becomes Paid only when the payment is recorded (method, transaction reference, date, amount).
 *  - Payout details are stored encrypted; a payer sees them only for an approved request, and each view is audited.
 */
final class PayoutService
{
    private const OPEN = ['requested', 'under_review', 'approved', 'processing'];

    /**
     * @param array<string,mixed> $in amount (rupees), notes, token
     * @return array{public_id:string,reference:string,created:bool}
     */
    public static function request(int $userId, array $in): array
    {
        $partner = PartnerService::require($userId, 'payouts.request');
        $token = preg_match('/^[a-f0-9]{32}$/', (string) ($in['token'] ?? '')) ? (string) $in['token'] : null;
        if ($token !== null && ($d = Database::one('SELECT public_id, reference FROM payout_requests WHERE partner_id = ? AND submit_token = ?', [$partner['id'], $token])) !== null) {
            return ['public_id' => (string) $d['public_id'], 'reference' => (string) $d['reference'], 'created' => false];
        }
        $amount = (int) FieldRules::moneyMinor($in['amount'] ?? '', 'Amount', true);
        $notes = FieldRules::line($in['notes'] ?? '', 'Notes', 2, 500, false);
        if (!RateLimiter::hit('payout_request_user', (string) $userId)) {
            throw new HttpException(429);
        }

        return Database::transaction(function () use ($partner, $userId, $amount, $notes, $token): array {
            $locked = Database::one('SELECT * FROM referral_partners WHERE id = ? FOR UPDATE', [$partner['id']]);
            if ($locked['status'] !== 'active') {
                throw new HttpException(403);
            }
            if ($locked['payout_details_enc'] === null) {
                throw new ValidationException(['Add your payout details before requesting a payout.']);
            }
            CommissionService::releaseDue((int) $partner['id']);
            $balance = CommissionService::balance((int) $partner['id']);
            $min = (int) CommissionRules::current()['rules']['min_payout_minor'];
            if ($amount < 1) {
                throw new ValidationException(['Enter an amount greater than zero.']);
            }
            if ($amount < $min) {
                throw new ValidationException(['The smallest payout is ₹' . FieldRules::formatMinor($min) . '.']);
            }
            if ($amount > $balance['available']) {
                throw new ValidationException(['You can request at most your available balance of ₹' . FieldRules::formatMinor(max(0, $balance['available'])) . '.']);
            }
            $ref = CrmReference::next('payout');
            $pub = uuid_hex();
            Database::run(
                "INSERT INTO payout_requests (public_id, reference, partner_id, amount_minor, available_minor, status, partner_notes, details_enc, details_hint, submit_token, requested_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, 'requested', ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())",
                [$pub, $ref, $partner['id'], $amount, $balance['available'], $notes, $locked['payout_details_enc'], $locked['payout_details_hint'], $token],
            );
            $id = Database::insertId();
            self::allocate($id, (int) $partner['id'], $amount);
            self::history($id, null, 'requested', null, 'partner', $userId);
            Audit::record('payout.requested', 'payout_request', $id, [], ['reference' => $ref, 'amount_minor' => $amount, 'available_minor' => $balance['available']], ['partner_id' => (int) $partner['id']], $userId);
            Notifier::send(Notifier::withPermission('payouts.approve'), 'payout.requested', 'A payout was requested', $ref . ': ₹' . FieldRules::formatMinor($amount), '/payouts/' . $pub, null, 'payout_request', $id, "payout:{$id}:requested");

            return ['public_id' => $pub, 'reference' => $ref, 'created' => true];
        });
    }

    /** Reserves $amount of the partner's payable entries: negative lines first (netting), then the oldest positive entries. */
    private static function allocate(int $payoutId, int $partnerId, int $amount): void
    {
        $rows = Database::all(
            "SELECT e.id, e.amount_minor - COALESCE(a.alloc, 0) AS remaining FROM commission_entries e
               LEFT JOIN (SELECT entry_id, SUM(amount_minor) AS alloc FROM payout_allocations WHERE released_at IS NULL GROUP BY entry_id) a ON a.entry_id = e.id
              WHERE e.partner_id = ? AND e.status IN ('payable','payout_requested') AND e.amount_minor - COALESCE(a.alloc, 0) <> 0 ORDER BY (e.amount_minor < 0) DESC, e.id FOR UPDATE",
            [$partnerId],
        );
        $need = $amount;
        $touched = [];
        foreach ($rows as $r) {
            $rem = (int) $r['remaining'];
            if ($rem < 0) {
                $take = $rem; // every carried negative is netted, which raises what must be taken from positives
                $need += -$rem;
            } else {
                $take = min($rem, $need);
                if ($take <= 0) {
                    continue;
                }
                $need -= $take;
            }
            Database::run('INSERT INTO payout_allocations (payout_id, entry_id, amount_minor) VALUES (?, ?, ?)', [$payoutId, $r['id'], $take]);
            $touched[] = (int) $r['id'];
        }
        if ($need !== 0) {
            throw new ValidationException(['Your available balance changed. Please try again.']);
        }
        foreach ($touched as $id) {
            CommissionService::syncStatus($id);
        }
    }

    /** @param array<string,mixed> $payout */
    public static function cancelByPartner(int $userId, array $payout): void
    {
        $partner = PartnerService::require($userId, 'payouts.request');
        Database::transaction(function () use ($userId, $partner, $payout): void {
            $row = Database::one('SELECT * FROM payout_requests WHERE id = ? FOR UPDATE', [$payout['id']]);
            if ($row === null || (int) $row['partner_id'] !== (int) $partner['id']) {
                throw new HttpException(404);
            }
            if (!in_array($row['status'], ['requested', 'under_review'], true)) {
                throw new ValidationException(['This request can no longer be cancelled. Contact us if you need to change it.']);
            }
            self::release((int) $row['id']);
            Database::run("UPDATE payout_requests SET status = 'cancelled', updated_at = UTC_TIMESTAMP() WHERE id = ?", [$row['id']]);
            self::history((int) $row['id'], $row['status'], 'cancelled', 'Cancelled by the partner.', 'partner', $userId);
            Audit::record('payout.cancelled', 'payout_request', (int) $row['id'], ['status' => $row['status']], ['status' => 'cancelled'], [], $userId);
        });
    }

    /** Frees every reservation of the request and refreshes the entries it touched. */
    private static function release(int $payoutId): void
    {
        $entries = array_column(Database::all('SELECT DISTINCT entry_id FROM payout_allocations WHERE payout_id = ? AND released_at IS NULL', [$payoutId]), 'entry_id');
        Database::run('UPDATE payout_allocations SET released_at = UTC_TIMESTAMP() WHERE payout_id = ? AND released_at IS NULL', [$payoutId]);
        foreach ($entries as $id) {
            CommissionService::syncStatus((int) $id);
        }
    }

    // ------------------------------------------------------------------------------------------------ staff workflow

    /** @param array<string,mixed> $p */
    public static function startReview(int $actorId, array $p): void
    {
        Gate::authorize('payouts.approve', $actorId);
        self::move($actorId, $p, ['requested'], 'under_review', 'payout.review_started', null, ['reviewed_by' => $actorId, 'reviewed_at' => true]);
    }

    /**
     * Approval: the ledger and the balance are re-verified. It does NOT pay anything.
     *
     * @param array<string,mixed> $p
     */
    public static function approve(int $actorId, array $p, string $note = ''): void
    {
        Gate::authorize('payouts.approve', $actorId);
        $note = (string) FieldRules::line($note, 'Note', 2, 500, false);
        Database::transaction(function () use ($actorId, $p, $note): void {
            $row = Database::one('SELECT * FROM payout_requests WHERE id = ? FOR UPDATE', [$p['id']]);
            if ($row === null || !in_array($row['status'], ['requested', 'under_review'], true)) {
                throw new ValidationException(['This request is not waiting for approval.']);
            }
            Database::one('SELECT id FROM referral_partners WHERE id = ? FOR UPDATE', [$row['partner_id']]);
            $net = (int) Database::one('SELECT COALESCE(SUM(amount_minor), 0) AS s FROM payout_allocations WHERE payout_id = ? AND released_at IS NULL', [$row['id']])['s'];
            $bad = Database::one(
                "SELECT 1 AS x FROM payout_allocations a JOIN commission_entries e ON e.id = a.entry_id WHERE a.payout_id = ? AND a.released_at IS NULL AND e.status NOT IN ('payable','payout_requested')",
                [$row['id']],
            );
            $free = CommissionService::balance((int) $row['partner_id'])['available']; // reservations of THIS request are already excluded
            if ($net !== (int) $row['amount_minor'] || $bad !== null || $free < 0) {
                throw new ValidationException(['The commission ledger changed since this request (a hold, reversal or adjustment). Reject the request with an explanation and let the partner request again.']);
            }
            Database::run("UPDATE payout_requests SET status = 'approved', approved_by = ?, approved_at = UTC_TIMESTAMP(), reviewed_by = COALESCE(reviewed_by, ?), reviewed_at = COALESCE(reviewed_at, UTC_TIMESTAMP()), updated_at = UTC_TIMESTAMP() WHERE id = ?", [$actorId, $actorId, $row['id']]);
            self::history((int) $row['id'], $row['status'], 'approved', $note === '' ? null : $note, 'internal', $actorId);
            self::history((int) $row['id'], 'approved', 'approved', 'Approved. Payment is the next step.', 'partner', $actorId);
            Audit::record('payout.approved', 'payout_request', (int) $row['id'], ['status' => $row['status']], ['status' => 'approved', 'amount_minor' => (int) $row['amount_minor']], ['partner_id' => (int) $row['partner_id']], $actorId);
            self::notifyPartner($row, 'payout.approved', 'Your payout request was approved', $row['reference'] . ': payment will follow.', 'approved');
            Notifier::send(Notifier::withPermission('payouts.record'), 'payout.ready_to_pay', 'A payout is approved and ready to pay', $row['reference'], '/payouts/' . $row['public_id'], null, 'payout_request', (int) $row['id'], "payout:{$row['id']}:ready", $actorId);
        });
    }

    /** @param array<string,mixed> $p */
    public static function reject(int $actorId, array $p, string $reason): void
    {
        Gate::authorize('payouts.approve', $actorId);
        $reason = (string) FieldRules::text($reason, 'Reason (the partner will read this)', 10, 500);
        Database::transaction(function () use ($actorId, $p, $reason): void {
            $row = Database::one('SELECT * FROM payout_requests WHERE id = ? FOR UPDATE', [$p['id']]);
            if ($row === null || !in_array($row['status'], ['requested', 'under_review', 'approved'], true)) {
                throw new ValidationException(['This request cannot be rejected in its current state.']);
            }
            self::release((int) $row['id']);
            Database::run("UPDATE payout_requests SET status = 'rejected', reason = ?, reviewed_by = COALESCE(reviewed_by, ?), reviewed_at = COALESCE(reviewed_at, UTC_TIMESTAMP()), updated_at = UTC_TIMESTAMP() WHERE id = ?", [$reason, $actorId, $row['id']]);
            self::history((int) $row['id'], $row['status'], 'rejected', $reason, 'partner', $actorId);
            Audit::record('payout.rejected', 'payout_request', (int) $row['id'], ['status' => $row['status']], ['status' => 'rejected'], ['partner_id' => (int) $row['partner_id'], 'reason_recorded' => true, 'released_minor' => (int) $row['amount_minor']], $actorId);
            self::notifyPartner($row, 'payout.rejected', 'Your payout request was not approved', $row['reference'] . ': ' . mb_substr($reason, 0, 180), 'rejected');
        });
    }

    /** The payer has started the transfer. Separate from approval. @param array<string,mixed> $p */
    public static function markProcessing(int $actorId, array $p): void
    {
        Gate::authorize('payouts.record', $actorId);
        Database::transaction(function () use ($actorId, $p): void {
            $row = Database::one('SELECT * FROM payout_requests WHERE id = ? FOR UPDATE', [$p['id']]);
            if ($row === null || $row['status'] !== 'approved') {
                throw new ValidationException(['Only an approved request can be marked as processing.']);
            }
            self::assertDifferentPerson($actorId, (int) $row['approved_by'], 'approved');
            Database::run("UPDATE payout_requests SET status = 'processing', processing_by = ?, processing_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE id = ?", [$actorId, $row['id']]);
            self::history((int) $row['id'], 'approved', 'processing', 'Payment is being processed.', 'partner', $actorId);
            Audit::record('payout.processing', 'payout_request', (int) $row['id'], ['status' => 'approved'], ['status' => 'processing'], [], $actorId);
        });
    }

    /**
     * Records the manual payment: method, transaction reference, date and amount. Only now does the request become Paid.
     *
     * @param array<string,mixed> $p
     * @param array<string,mixed> $in method, reference, date, amount (rupees), note
     */
    public static function recordPayment(int $actorId, array $p, array $in): void
    {
        Gate::authorize('payouts.record', $actorId);
        $method = (string) FieldRules::choice($in['method'] ?? '', ['bank_transfer' => 'Bank transfer', 'upi' => 'UPI', 'cheque' => 'Cheque', 'cash' => 'Cash', 'other' => 'Other'], 'payment method');
        $reference = (string) FieldRules::line($in['reference'] ?? '', 'Transaction reference', 3, 120);
        $date = (string) FieldRules::date($in['date'] ?? '', 'Payment date', true);
        $amount = (int) FieldRules::moneyMinor($in['amount'] ?? '', 'Amount paid', true);
        $note = (string) FieldRules::line($in['note'] ?? '', 'Note', 2, 500, false);
        if ($date > AppTime::today()) {
            throw new ValidationException(['The payment date cannot be in the future.']);
        }
        try {
            Database::transaction(function () use ($actorId, $p, $method, $reference, $date, $amount, $note): void {
                $row = Database::one('SELECT * FROM payout_requests WHERE id = ? FOR UPDATE', [$p['id']]);
                if ($row === null || !in_array($row['status'], ['approved', 'processing'], true)) {
                    throw new ValidationException(['Only an approved request can be recorded as paid. Approval is a separate step.']);
                }
                self::assertDifferentPerson($actorId, (int) $row['approved_by'], 'approved');
                if ($amount !== (int) $row['amount_minor']) {
                    throw new ValidationException(['The amount paid must equal the approved amount (₹' . FieldRules::formatMinor((int) $row['amount_minor']) . ').']);
                }
                if ($date < date('Y-m-d', strtotime((string) $row['requested_at'] . ' UTC -1 day'))) {
                    throw new ValidationException(['The payment date cannot be before the payout was requested.']);
                }
                Database::run("UPDATE payout_requests SET status = 'paid', paid_by = ?, paid_at = UTC_TIMESTAMP(), paid_amount_minor = ?, payment_method = ?, payment_reference = ?, payment_date = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?",
                    [$actorId, $amount, $method, $reference, $date, $row['id']]);
                foreach (Database::all('SELECT DISTINCT entry_id FROM payout_allocations WHERE payout_id = ? AND released_at IS NULL', [$row['id']]) as $a) {
                    CommissionService::syncStatus((int) $a['entry_id']);
                }
                self::history((int) $row['id'], $row['status'], 'paid', 'Payment recorded: ' . ($note !== '' ? $note : 'see your payment method.'), 'partner', $actorId);
                Audit::record('payout.paid', 'payout_request', (int) $row['id'], ['status' => $row['status']], ['status' => 'paid', 'amount_minor' => $amount, 'method' => $method, 'payment_date' => $date],
                    ['partner_id' => (int) $row['partner_id'], 'approved_by' => (int) $row['approved_by'], 'paid_by' => $actorId], $actorId);
                self::notifyPartner($row, 'payout.paid', 'Your payout was paid', $row['reference'] . ': ₹' . FieldRules::formatMinor($amount) . ' by ' . $method . '.', 'paid');
            });
        } catch (\PDOException $e) {
            if (($e->errorInfo[1] ?? 0) === 1062) {
                throw new ValidationException(['That transaction reference has already been used for another payout.']);
            }
            throw $e;
        }
    }

    private static function assertDifferentPerson(int $actorId, int $other, string $role): void
    {
        if (!empty(CommissionRules::current()['rules']['separation_of_duties']) && $actorId === $other) {
            throw new ValidationException(["The person who {$role} this request cannot also record its payment. Ask a second authorised person."]);
        }
    }

    /**
     * @param array<string,mixed> $p
     * @param list<string> $from
     * @param array<string,mixed> $stamp
     */
    private static function move(int $actorId, array $p, array $from, string $to, string $audit, ?string $note, array $stamp): void
    {
        Database::transaction(function () use ($actorId, $p, $from, $to, $audit, $note): void {
            $row = Database::one('SELECT * FROM payout_requests WHERE id = ? FOR UPDATE', [$p['id']]);
            if ($row === null || !in_array($row['status'], $from, true)) {
                throw new ValidationException(['This request is not in a state that allows that.']);
            }
            Database::run('UPDATE payout_requests SET status = ?, reviewed_by = ?, reviewed_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE id = ?', [$to, $actorId, $row['id']]);
            self::history((int) $row['id'], $row['status'], $to, $note, 'partner', $actorId);
            Audit::record($audit, 'payout_request', (int) $row['id'], ['status' => $row['status']], ['status' => $to], [], $actorId);
        });
    }

    /** @param array<string,mixed> $p */
    public static function addNote(int $actorId, array $p, string $body): void
    {
        Gate::authorize('payouts.view_all', $actorId);
        $body = (string) FieldRules::text($body, 'Note', 2, 3000);
        Database::transaction(function () use ($actorId, $p, $body): void {
            Database::run("INSERT INTO partner_notes (subject_type, subject_id, author_id, body, created_at) VALUES ('payout', ?, ?, ?, UTC_TIMESTAMP())", [$p['id'], $actorId, $body]);
            Audit::record('payout.note_added', 'payout_request', (int) $p['id'], [], ['length' => mb_strlen($body)], [], $actorId);
        });
    }

    /** @param array<string,mixed> $row */
    private static function notifyPartner(array $row, string $event, string $title, string $body, string $key): void
    {
        $u = Database::one('SELECT user_id FROM referral_partners WHERE id = ?', [$row['partner_id']]);
        Notifier::send([(int) $u['user_id']], $event, $title, $body, '/partner/payouts/' . $row['public_id'], null, 'payout_request', (int) $row['id'], "payout:{$row['id']}:{$key}");
    }

    private static function history(int $payoutId, ?string $from, string $to, ?string $note, string $visibility, ?int $actorId): void
    {
        Database::run('INSERT INTO payout_history (payout_id, from_status, to_status, note, visibility, actor_id, created_at) VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())', [$payoutId, $from, $to, $note, $visibility, $actorId]);
    }

    // ------------------------------------------------------------------------------------------------ read side

    /** @return array<string,mixed>|null */
    public static function find(string $publicId): ?array
    {
        return preg_match('/^[a-f0-9]{32}$/', $publicId) ? Database::one(
            'SELECT po.*, p.public_id AS partner_public_id, p.reference AS partner_reference, pu.name AS partner_name, pu.id AS partner_user_id,
                    ap.name AS approved_by_name, pd.name AS paid_by_name
               FROM payout_requests po JOIN referral_partners p ON p.id = po.partner_id JOIN users pu ON pu.id = p.user_id
               LEFT JOIN users ap ON ap.id = po.approved_by LEFT JOIN users pd ON pd.id = po.paid_by WHERE po.public_id = ?',
            [$publicId],
        ) : null;
    }

    /** @return array<string,mixed> */
    public static function requireStaff(int $userId, string $publicId): array
    {
        $r = Gate::canAny(['payouts.view_all', 'payouts.approve', 'payouts.record'], $userId) ? self::find($publicId) : null;
        if ($r === null) {
            throw new HttpException(404);
        }

        return $r;
    }

    /** @return array<string,mixed> the partner's OWN payout request */
    public static function requireOwn(int $userId, string $publicId): array
    {
        $partner = PartnerService::require($userId, 'payouts.view_own');
        $r = self::find($publicId);
        if ($r === null || (int) $r['partner_id'] !== (int) $partner['id']) {
            throw new HttpException(404);
        }

        return $r;
    }

    /** What a partner sees of a payout: amounts, status, the reason written for them and the payment method/date; never the reference of other records or encrypted details. @param array<string,mixed> $r @return array<string,mixed> */
    public static function partnerView(array $r): array
    {
        return ['public_id' => $r['public_id'], 'reference' => $r['reference'], 'amount_minor' => $r['amount_minor'], 'available_minor' => $r['available_minor'], 'status' => $r['status'],
            'reason' => $r['status'] === 'rejected' ? $r['reason'] : null, 'requested_at' => $r['requested_at'], 'paid_at' => $r['paid_at'], 'payment_method' => $r['status'] === 'paid' ? $r['payment_method'] : null,
            'payment_date' => $r['status'] === 'paid' ? $r['payment_date'] : null, 'payment_reference' => $r['status'] === 'paid' ? $r['payment_reference'] : null, 'partner_notes' => $r['partner_notes'], 'details_hint' => $r['details_hint']];
    }

    /** @param array<string,mixed> $r @return list<array<string,mixed>> */
    public static function history_for(array $r, bool $staff): array
    {
        return Database::all('SELECT h.from_status, h.to_status, h.note, h.visibility, h.created_at, ' . ($staff ? 'u.name AS actor' : 'NULL AS actor') . ' FROM payout_history h LEFT JOIN users u ON u.id = h.actor_id
                               WHERE h.payout_id = ?' . ($staff ? '' : " AND h.visibility = 'partner'") . ' ORDER BY h.id', [$r['id']]);
    }

    /** @param array<string,mixed> $r @return list<array<string,mixed>> */
    public static function notes(int $userId, array $r): array
    {
        return Gate::canAny(['payouts.view_all', 'payouts.approve', 'payouts.record'], $userId)
            ? Database::all("SELECT n.body, n.created_at, u.name AS author FROM partner_notes n LEFT JOIN users u ON u.id = n.author_id WHERE n.subject_type = 'payout' AND n.subject_id = ? ORDER BY n.id", [$r['id']]) : [];
    }

    /** The entries a request reserves (staff). @param array<string,mixed> $r @return list<array<string,mixed>> */
    public static function allocations(array $r): array
    {
        return Database::all('SELECT e.reference, e.milestone, e.status, a.amount_minor, a.released_at FROM payout_allocations a JOIN commission_entries e ON e.id = a.entry_id WHERE a.payout_id = ? ORDER BY a.id', [$r['id']]);
    }

    /**
     * @param array{q?:string,status?:string,partner?:string,from?:string,to?:string} $f
     * @return array{rows:list<array<string,mixed>>,total:int}
     */
    public static function paginate(int $userId, array $f, int $page = 1, int $perPage = 20): array
    {
        if (!Gate::canAny(['payouts.view_all', 'payouts.approve', 'payouts.record'], $userId)) {
            return ['rows' => [], 'total' => 0];
        }
        $w = ['1=1'];
        $p = [];
        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
            $w[] = '(po.reference LIKE ? OR pu.name LIKE ? OR po.payment_reference LIKE ?)';
            array_push($p, $like, $like, $like);
        }
        if (array_key_exists((string) ($f['status'] ?? ''), (array) Config::get('referrals.payout_statuses'))) {
            $w[] = 'po.status = ?';
            $p[] = $f['status'];
        }
        if (preg_match('/^[a-f0-9]{32}$/', (string) ($f['partner'] ?? ''))) {
            $w[] = 'p.public_id = ?';
            $p[] = $f['partner'];
        }
        [$w, $p] = PartnerApplicationService::dateRange($w, $p, 'po.requested_at', $f);
        $cond = implode(' AND ', $w);
        $base = 'FROM payout_requests po JOIN referral_partners p ON p.id = po.partner_id JOIN users pu ON pu.id = p.user_id';
        $total = (int) Database::one("SELECT COUNT(*) AS c {$base} WHERE {$cond}", $p)['c'];
        $offset = (int) min(max(0, ($page - 1) * $perPage), 2_000_000_000);
        $rows = Database::all("SELECT po.public_id, po.reference, po.amount_minor, po.status, po.requested_at, po.paid_at, pu.name AS partner_name {$base} WHERE {$cond}
                               ORDER BY FIELD(po.status, 'requested','under_review','approved','processing','paid','rejected','cancelled'), po.id DESC LIMIT {$perPage} OFFSET {$offset}", $p);

        return ['rows' => $rows, 'total' => $total];
    }

    /** @return array{rows:list<array<string,mixed>>,total:int} the partner's own requests */
    public static function paginateOwn(int $userId, int $page = 1, int $perPage = 20): array
    {
        $partner = PartnerService::require($userId, 'payouts.view_own');
        $total = (int) Database::one('SELECT COUNT(*) AS c FROM payout_requests WHERE partner_id = ?', [$partner['id']])['c'];
        $offset = (int) min(max(0, ($page - 1) * $perPage), 2_000_000_000);
        $rows = Database::all("SELECT * FROM payout_requests WHERE partner_id = ? ORDER BY id DESC LIMIT {$perPage} OFFSET {$offset}", [$partner['id']]);

        return ['rows' => array_map([self::class, 'partnerView'], $rows), 'total' => $total];
    }

    /** @return array<string,int> */
    public static function dashboard(int $userId): array
    {
        if (!Gate::canAny(['payouts.view_all', 'payouts.approve', 'payouts.record'], $userId)) {
            return [];
        }
        $c = static fn (string $sql): int => (int) Database::one($sql)['c'];

        return [
            'awaiting_review' => $c("SELECT COUNT(*) AS c FROM payout_requests WHERE status IN ('requested','under_review')"),
            'awaiting_payment' => $c("SELECT COUNT(*) AS c FROM payout_requests WHERE status IN ('approved','processing')"),
            'paid_count' => $c("SELECT COUNT(*) AS c FROM payout_requests WHERE status = 'paid'"),
            'paid_minor' => $c("SELECT COALESCE(SUM(paid_amount_minor), 0) AS c FROM payout_requests WHERE status = 'paid'"),
        ];
    }
}
