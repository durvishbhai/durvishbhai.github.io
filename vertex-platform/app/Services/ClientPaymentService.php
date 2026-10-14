<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Config;
use Vertex\Core\Database;
use Vertex\Core\HttpException;
use Vertex\Core\ValidationException;

/**
 * Verified client payments: the integration point between the (not yet built) invoice/payment/gateway modules and the commission engine.
 *
 *  - A payment is RECORDED by one person (`payments.record`) and VERIFIED by another (`payments.reconcile`; the same person cannot do both while
 *    separation of duties is on). Only VERIFIED payments can ever make a commission eligible.
 *  - `external_key` = SHA-256 of "method:reference" is UNIQUE, so the same bank/UPI/gateway transaction can never be recorded twice, and a retried
 *    gateway webhook handler calling record(..., idempotent: true) gets the existing row back instead of a second payment.
 *  - Refunds, chargebacks and reversals are EVENTS on the payment (the original amounts are never edited); each one re-evaluates the commission.
 *  - A payment may belong to an INVOICE (invoice_id) and/or a project. A client can submit a payment they made (UPI / bank transfer) as a CLAIM: it counts for
 *    nothing until staff compare it with the bank and verify it, entering the amount that actually arrived. A claim can also be rejected, never deleted.
 *  - Nothing here edits an invoice's content, a project price or any earlier payment row, and nothing is ever deleted. Verification, refund and reversal
 *    re-derive the invoice status (Issued / Partially paid / Paid) and re-evaluate the commission.
 */
final class ClientPaymentService
{
    /** @param array<string,mixed> $in @return array{public_id:string,reference:string,created:bool} */
    public static function record(int $actorId, array $in, bool $idempotent = false): array
    {
        Gate::authorize('payments.record', $actorId);
        $errors = [];
        $try = static function (callable $fn) use (&$errors): mixed {
            try {
                return $fn();
            } catch (ValidationException $e) {
                array_push($errors, ...$e->errors);

                return null;
            }
        };
        $invoice = null;
        $invoiceId = trim((string) ($in['invoice'] ?? ''));
        if ($invoiceId !== '') {
            $invoice = InvoiceService::find($invoiceId);
            if ($invoice === null || !in_array($invoice['status'], ['issued', 'partially_paid'], true)) {
                $errors[] = 'Choose an issued invoice that is not yet fully paid.';
                $invoice = null;
            }
        }
        $project = $invoice !== null ? ($invoice['project_id'] === null ? null : Database::one('SELECT * FROM projects WHERE id = ?', [$invoice['project_id']])) : ProjectPolicy::find((string) ($in['project'] ?? ''));
        if ($project === null && $invoice === null) {
            $errors[] = 'Choose an existing project or an invoice.';
        }
        $role = $project === null ? 'other' : $try(static fn () => (string) FieldRules::choice($in['role'] ?? '', ['advance' => 'Advance', 'balance' => 'Balance / final', 'other' => 'Other'], 'payment type'));
        $gross = $try(static fn () => (int) FieldRules::moneyMinor($in['gross'] ?? '', 'Amount received', true));
        $tax = $try(static fn () => (int) FieldRules::moneyMinor($in['tax'] ?? '0', 'Tax included in the amount', false));
        $method = $try(static fn () => (string) FieldRules::choice($in['method'] ?? '', (array) Config::get('referrals.payment_methods'), 'payment method'));
        $extRef = $try(static fn () => (string) FieldRules::line($in['external_ref'] ?? '', 'Bank, UPI or gateway transaction reference', 3, 120));
        $invoiceRef = $invoice !== null ? (string) $invoice['reference'] : $try(static fn () => FieldRules::line($in['invoice_ref'] ?? '', 'Invoice reference', 2, 120, false));
        $notes = $try(static fn () => FieldRules::line($in['notes'] ?? '', 'Notes', 2, 500, false));
        $received = $try(static fn () => FieldRules::date($in['received_on'] ?? '', 'Date received', true));
        if ($gross !== null && $gross < 1) {
            $errors[] = 'The amount received must be greater than zero.';
        }
        if ($gross !== null && $tax !== null && $tax > $gross) {
            $errors[] = 'The tax portion cannot exceed the amount received.';
        }
        if ($received !== null && $received > AppTime::today()) {
            $errors[] = 'The date received cannot be in the future.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $key = hash('sha256', $method . ':' . mb_strtolower($extRef));

        return Database::transaction(function () use ($actorId, $project, $invoice, $role, $gross, $tax, $method, $extRef, $key, $invoiceRef, $notes, $received, $idempotent): array {
            if ($invoice !== null) {
                $inv = Database::one('SELECT * FROM invoices WHERE id = ? FOR UPDATE', [$invoice['id']]);
                $room = InvoiceService::payable($inv) - InvoiceService::paidMinor((int) $inv['id']) - InvoiceService::pendingMinor((int) $inv['id']);
                if ($gross > $room) {
                    throw new ValidationException(['That is more than the ₹' . FieldRules::formatMinor(max(0, $room)) . ' still due on invoice ' . $inv['reference'] . '.']);
                }
            }
            $existing = Database::one('SELECT id, public_id, reference, project_id, gross_minor FROM client_payments WHERE external_key = ? FOR UPDATE', [$key]);
            if ($existing !== null) {
                if ($idempotent && (int) ($existing['project_id'] ?? 0) === (int) ($project['id'] ?? 0) && (int) $existing['gross_minor'] === $gross) {
                    return ['public_id' => (string) $existing['public_id'], 'reference' => (string) $existing['reference'], 'created' => false];
                }
                throw new ValidationException(["That transaction reference is already recorded ({$existing['reference']}). The same payment cannot be recorded twice."]);
            }
            $ref = CrmReference::next('client_payment');
            $pub = uuid_hex();
            Database::run(
                "INSERT INTO client_payments (public_id, reference, project_id, client_id, invoice_id, invoice_ref, payment_role, gross_minor, tax_minor, method, source, external_ref, external_key, received_on, status, notes, recorded_by, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'staff', ?, ?, ?, 'pending_verification', ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())",
                [$pub, $ref, $project['id'] ?? null, $invoice['client_id'] ?? $project['client_id'], $invoice['id'] ?? null, $invoiceRef, $role, $gross, $tax, $method, $extRef, $key, $received, $notes, $actorId],
            );
            $id = Database::insertId();
            self::event($id, 'recorded', $gross, null, $actorId);
            Audit::record('client_payment.recorded', 'client_payment', $id, [], ['reference' => $ref, 'role' => $role, 'gross_minor' => $gross, 'tax_minor' => $tax, 'method' => $method], ['project_id' => $project['id'] ?? null, 'invoice_id' => $invoice['id'] ?? null], $actorId);
            Notifier::send(Notifier::withPermission('payments.reconcile'), 'client_payment.needs_verification', 'A client payment needs verification', $ref, '/client-payments/' . $pub, null, 'client_payment', $id, "cpay:{$id}:recorded", $actorId);

            return ['public_id' => $pub, 'reference' => $ref, 'created' => true];
        });
    }

    /**
     * Verifies a payment. A payment submitted by the client (a CLAIM) is verified against the bank: the verifier enters what actually arrived and, for a
     * project invoice, which kind of payment it is; if the amount differs from the claim the correction is recorded as an event.
     *
     * @param array<string,mixed> $payment
     * @param array{received?:string,role?:string}|null $confirm
     */
    public static function verify(int $actorId, array $payment, ?array $confirm = null): void
    {
        Gate::authorize('payments.reconcile', $actorId);
        $received = null;
        $role = null;
        if (($payment['source'] ?? 'staff') === 'client_claim') {
            $received = (int) FieldRules::moneyMinor($confirm['received'] ?? '', 'Amount that actually reached the bank', true);
            if ($received < 1) {
                throw new ValidationException(['Enter the amount that actually reached the bank. If nothing arrived, reject the submission instead.']);
            }
            $role = $payment['project_id'] === null ? 'other' : (string) FieldRules::choice($confirm['role'] ?? '', ['advance' => 'Advance', 'balance' => 'Balance / final', 'other' => 'Other'], 'payment type');
        }
        Database::transaction(function () use ($actorId, $payment, $received, $role): void {
            $row = Database::one('SELECT * FROM client_payments WHERE id = ? FOR UPDATE', [$payment['id']]);
            if ($row === null || $row['status'] !== 'pending_verification') {
                throw new ValidationException(['This payment is not waiting for verification.']);
            }
            if (CommissionRules::current()['rules']['separation_of_duties'] && (int) $row['recorded_by'] === $actorId) {
                throw new ValidationException(['The person who recorded a payment cannot verify it. Ask a second authorised person.']);
            }
            $gross = (int) $row['gross_minor'];
            if ($received !== null) {
                if ($row['invoice_id'] !== null) {
                    $inv = Database::one('SELECT * FROM invoices WHERE id = ? FOR UPDATE', [$row['invoice_id']]);
                    $room = InvoiceService::payable($inv) - InvoiceService::paidMinor((int) $inv['id']);
                    if ($inv['status'] === 'void' || $received > $room) {
                        throw new ValidationException(['The amount is more than the ₹' . FieldRules::formatMinor(max(0, $room)) . ' still due on the invoice, or the invoice is void. Verify the correct amount or reject the submission.']);
                    }
                }
                if ($received !== $gross) {
                    self::event((int) $row['id'], 'amount_corrected', $received, 'The client claimed ₹' . FieldRules::formatMinor($gross) . '; the bank shows ₹' . FieldRules::formatMinor($received) . '.', $actorId);
                    Database::run('UPDATE client_payments SET gross_minor = ?, tax_minor = LEAST(tax_minor, ?) WHERE id = ?', [$received, $received, $row['id']]);
                    $gross = $received;
                }
                Database::run('UPDATE client_payments SET payment_role = ? WHERE id = ?', [$role, $row['id']]);
            }
            Database::run("UPDATE client_payments SET status = 'verified', verified_by = ?, verified_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE id = ?", [$actorId, $row['id']]);
            self::event((int) $row['id'], 'verified', $gross, null, $actorId);
            Audit::record('client_payment.verified', 'client_payment', (int) $row['id'], ['status' => 'pending_verification', 'gross_minor' => (int) $row['gross_minor']], ['status' => 'verified', 'gross_minor' => $gross], ['project_id' => $row['project_id'], 'invoice_id' => $row['invoice_id'], 'source' => $row['source']], $actorId);
            self::afterChange($row, $actorId);
            if ($row['source'] === 'client_claim' && $row['recorded_by'] !== null) {
                Notifier::send([(int) $row['recorded_by']], 'payment.confirmed', 'Payment confirmed: ₹' . FieldRules::formatMinor($gross), 'We received your payment ' . $row['reference'] . '. Thank you.', $row['invoice_id'] === null ? null : '/my/invoices/' . Database::one('SELECT public_id FROM invoices WHERE id = ?', [$row['invoice_id']])['public_id'], null, 'client_payment', (int) $row['id'], "cpay:{$row['id']}:confirmed");
            }
        });
    }

    /** Rejects a payment that is waiting for verification (nothing arrived, wrong reference, duplicate). It stays on record with the reason. @param array<string,mixed> $payment */
    public static function reject(int $actorId, array $payment, string $reason): void
    {
        Gate::authorize('payments.reconcile', $actorId);
        $reason = (string) FieldRules::line($reason, 'Reason', 5, 255);
        Database::transaction(function () use ($actorId, $payment, $reason): void {
            $row = Database::one('SELECT * FROM client_payments WHERE id = ? FOR UPDATE', [$payment['id']]);
            if ($row === null || $row['status'] !== 'pending_verification') {
                throw new ValidationException(['Only a payment that is waiting for verification can be rejected.']);
            }
            Database::run("UPDATE client_payments SET status = 'rejected', updated_at = UTC_TIMESTAMP() WHERE id = ?", [$row['id']]);
            self::event((int) $row['id'], 'rejected', (int) $row['gross_minor'], $reason, $actorId);
            Audit::record('client_payment.rejected', 'client_payment', (int) $row['id'], ['status' => 'pending_verification'], ['status' => 'rejected'], ['reason_recorded' => true, 'invoice_id' => $row['invoice_id']], $actorId);
            if ($row['source'] === 'client_claim' && $row['recorded_by'] !== null) {
                $inv = $row['invoice_id'] === null ? null : Database::one('SELECT public_id FROM invoices WHERE id = ?', [$row['invoice_id']]);
                Notifier::send([(int) $row['recorded_by']], 'payment.not_confirmed', 'We could not confirm your payment submission', $row['reference'] . ': ' . $reason, $inv === null ? null : '/my/invoices/' . $inv['public_id'], null, 'client_payment', (int) $row['id'], "cpay:{$row['id']}:rejected");
            }
        });
    }

    /**
     * A client tells us they paid an invoice (by UPI / Google Pay or bank transfer). This records a CLAIM that counts for nothing until staff verify it against
     * the bank statement. The same transaction reference cannot be submitted twice, and a claim can never exceed what is still due.
     *
     * @param array<string,mixed> $invoice a row the caller got from InvoiceService::requireOwn
     * @param array<string,mixed> $in
     * @return array{public_id:string,reference:string}
     */
    public static function claim(int $userId, array $invoice, array $in): array
    {
        Gate::authorize('payments.pay', $userId);
        if ($invoice['client_user_id'] === null || (int) $invoice['client_user_id'] !== $userId) {
            throw new HttpException(404);
        }
        if (!RateLimiter::hit('payment_claim_user', (string) $userId)) {
            throw new HttpException(429);
        }
        $amount = (int) FieldRules::moneyMinor($in['amount'] ?? '', 'Amount you paid', true);
        $method = (string) FieldRules::choice($in['method'] ?? '', ['upi' => 'UPI / Google Pay', 'bank_transfer' => 'Bank transfer'], 'payment method');
        $ref = (string) FieldRules::line($in['reference'] ?? '', 'Transaction / UTR number', 6, 40);
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._\/\- ]{4,39}$/', $ref)) {
            throw new ValidationException(['The transaction number may contain only letters, digits and . _ / - characters.']);
        }
        $on = (string) FieldRules::date($in['paid_on'] ?? '', 'Date you paid', true);
        if ($amount < 1) {
            throw new ValidationException(['The amount must be more than zero.']);
        }
        if ($on > AppTime::today()) {
            throw new ValidationException(['The payment date cannot be in the future.']);
        }
        $key = hash('sha256', $method . ':' . mb_strtolower($ref));

        return Database::transaction(function () use ($userId, $invoice, $amount, $method, $ref, $on, $key): array {
            $inv = Database::one('SELECT * FROM invoices WHERE id = ? FOR UPDATE', [$invoice['id']]);
            if ($inv === null || !in_array($inv['status'], ['issued', 'partially_paid'], true)) {
                throw new ValidationException(['This invoice cannot take a payment (it is paid, void or not issued).']);
            }
            if (Database::one('SELECT 1 AS x FROM client_payments WHERE external_key = ? FOR UPDATE', [$key]) !== null) {
                throw new ValidationException(['That transaction number has already been submitted. If you think this is a mistake, contact us.']);
            }
            $room = InvoiceService::payable($inv) - InvoiceService::paidMinor((int) $inv['id']) - InvoiceService::pendingMinor((int) $inv['id']);
            if ($amount > $room) {
                throw new ValidationException(['That is more than the ₹' . FieldRules::formatMinor(max(0, $room)) . ' still due (payments already submitted and waiting for confirmation are counted).']);
            }
            $pub = uuid_hex();
            $reference = CrmReference::next('client_payment');
            Database::run("INSERT INTO client_payments (public_id, reference, project_id, client_id, invoice_id, invoice_ref, payment_role, gross_minor, tax_minor, method, source, external_ref, external_key, received_on, status, notes, recorded_by, created_at, updated_at)
                           VALUES (?, ?, ?, ?, ?, ?, 'other', ?, 0, ?, 'client_claim', ?, ?, ?, 'pending_verification', 'Submitted by the client', ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())",
                [$pub, $reference, $inv['project_id'], $inv['client_id'], $inv['id'], $inv['reference'], $amount, $method, $ref, $key, $on, $userId]);
            $id = Database::insertId();
            self::event($id, 'claimed', $amount, null, $userId);
            Audit::record('client_payment.claimed', 'client_payment', $id, [], ['reference' => $reference, 'amount_minor' => $amount, 'method' => $method], ['invoice_id' => (int) $inv['id']], $userId);
            Notifier::send(Notifier::withPermission('payments.reconcile'), 'client_payment.needs_verification', 'A client says they paid invoice ' . $inv['reference'], '₹' . FieldRules::formatMinor($amount) . ' · check the bank, then verify or reject.', '/client-payments/' . $pub, null, 'client_payment', $id, "cpay:{$id}:claimed");

            return ['public_id' => $pub, 'reference' => $reference];
        });
    }

    /** After a payment changed state: re-derive the invoice status and re-evaluate the commission. @param array<string,mixed> $row */
    private static function afterChange(array $row, int $actorId): void
    {
        if ($row['invoice_id'] !== null) {
            InvoiceService::sync((int) $row['invoice_id']);
        }
        if ($row['project_id'] !== null) {
            CommissionService::evaluateProject((int) $row['project_id'], $actorId);
        }
    }

    /**
     * A refund, chargeback or reversal of (part of) a verified payment.
     *
     * @param array<string,mixed> $payment
     * @param string $type refund|chargeback|reversed
     */
    public static function refund(int $actorId, array $payment, int $amountMinor, string $reason, string $type = 'refund'): void
    {
        Gate::authorize('payments.reconcile', $actorId);
        $reason = (string) FieldRules::line($reason, 'Reason', 5, 255);
        if (!in_array($type, ['refund', 'chargeback', 'reversed'], true)) {
            throw new ValidationException(['Choose refund, chargeback or reversal.']);
        }
        Database::transaction(function () use ($actorId, $payment, $amountMinor, $reason, $type): void {
            $row = Database::one('SELECT * FROM client_payments WHERE id = ? FOR UPDATE', [$payment['id']]);
            if ($row === null || in_array($row['status'], ['pending_verification', 'rejected'], true)) {
                throw new ValidationException(['Only a verified payment can be refunded or reversed.']);
            }
            $room = (int) $row['gross_minor'] - (int) $row['refunded_minor'];
            $amount = $type === 'reversed' ? $room : $amountMinor;
            if ($amount < 1 || $amount > $room) {
                throw new ValidationException(['The amount must be between ₹0.01 and the part of this payment that has not been refunded (₹' . FieldRules::formatMinor($room) . ').']);
            }
            $new = (int) $row['refunded_minor'] + $amount;
            Database::run('UPDATE client_payments SET refunded_minor = ?, status = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?', [$new, $new >= (int) $row['gross_minor'] ? 'reversed' : $row['status'], $row['id']]);
            self::event((int) $row['id'], $type, $amount, $reason, $actorId);
            Audit::record('client_payment.' . $type, 'client_payment', (int) $row['id'], ['refunded_minor' => (int) $row['refunded_minor']], ['refunded_minor' => $new], ['project_id' => $row['project_id'], 'invoice_id' => $row['invoice_id'], 'reason_recorded' => true], $actorId);
            self::afterChange($row, $actorId);
        });
    }

    private static function event(int $paymentId, string $event, int $amount, ?string $reason, ?int $actorId): void
    {
        Database::run('INSERT INTO client_payment_events (payment_id, event, amount_minor, reason, actor_id, created_at) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())', [$paymentId, $event, $amount, $reason, $actorId]);
    }

    // ------------------------------------------------------------------------------------------------ read side (staff only)

    /** @return array<string,mixed>|null */
    public static function find(string $publicId): ?array
    {
        return preg_match('/^[a-f0-9]{32}$/', $publicId) ? Database::one(
            'SELECT cp.*, p.public_id AS project_public_id, p.code AS project_code, p.title AS project_title, p.approved_amount_minor, COALESCE(NULLIF(c.company_name, \'\'), c.contact_name) AS client_name,
                    inv.public_id AS invoice_public_id, inv.reference AS invoice_reference, inv.total_minor AS invoice_total_minor, ru.name AS recorded_by_name, vu.name AS verified_by_name
               FROM client_payments cp LEFT JOIN projects p ON p.id = cp.project_id JOIN clients c ON c.id = cp.client_id LEFT JOIN invoices inv ON inv.id = cp.invoice_id LEFT JOIN users ru ON ru.id = cp.recorded_by LEFT JOIN users vu ON vu.id = cp.verified_by
              WHERE cp.public_id = ?',
            [$publicId],
        ) : null;
    }

    /** @return array<string,mixed> */
    public static function requireView(int $userId, string $publicId): array
    {
        $r = Gate::canAny(['payments.view_all', 'payments.record', 'payments.reconcile'], $userId) ? self::find($publicId) : null;
        if ($r === null) {
            throw new HttpException(404);
        }

        return $r;
    }

    /** @param array<string,mixed> $payment @return list<array<string,mixed>> */
    public static function events(array $payment): array
    {
        return Database::all('SELECT e.event, e.amount_minor, e.reason, e.created_at, u.name AS actor FROM client_payment_events e LEFT JOIN users u ON u.id = e.actor_id WHERE e.payment_id = ? ORDER BY e.id', [$payment['id']]);
    }

    /**
     * @param array{q?:string,status?:string,from?:string,to?:string} $f
     * @return array{rows:list<array<string,mixed>>,total:int}
     */
    public static function paginate(int $userId, array $f, int $page = 1, int $perPage = 20): array
    {
        if (!Gate::canAny(['payments.view_all', 'payments.record', 'payments.reconcile'], $userId)) {
            return ['rows' => [], 'total' => 0];
        }
        $w = ['1=1'];
        $p = [];
        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
            $w[] = '(cp.reference LIKE ? OR cp.external_ref LIKE ? OR cp.invoice_ref LIKE ? OR p.code LIKE ? OR p.title LIKE ?)';
            array_push($p, $like, $like, $like, $like, $like);
        }
        if (in_array($f['status'] ?? '', ['pending_verification', 'verified', 'reversed', 'rejected'], true)) {
            $w[] = 'cp.status = ?';
            $p[] = $f['status'];
        }
        [$w, $p] = PartnerApplicationService::dateRange($w, $p, 'cp.created_at', $f);
        $cond = implode(' AND ', $w);
        $base = 'FROM client_payments cp LEFT JOIN projects p ON p.id = cp.project_id LEFT JOIN invoices inv ON inv.id = cp.invoice_id';
        $total = (int) Database::one("SELECT COUNT(*) AS c {$base} WHERE {$cond}", $p)['c'];
        $offset = (int) min(max(0, ($page - 1) * $perPage), 2_000_000_000);
        $rows = Database::all("SELECT cp.public_id, cp.reference, cp.payment_role, cp.gross_minor, cp.tax_minor, cp.refunded_minor, cp.status, cp.method, cp.received_on, cp.created_at, cp.source, inv.reference AS invoice_reference, p.code AS project_code, p.title AS project_title
                               {$base} WHERE {$cond} ORDER BY (cp.status = 'pending_verification') DESC, cp.id DESC LIMIT {$perPage} OFFSET {$offset}", $p);

        return ['rows' => $rows, 'total' => $total];
    }
}
