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
 *  - Nothing here edits an invoice, a project price or any earlier payment row, and nothing is ever deleted.
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
        $project = ProjectPolicy::find((string) ($in['project'] ?? ''));
        if ($project === null) {
            $errors[] = 'Choose an existing project.';
        }
        $role = $try(static fn () => (string) FieldRules::choice($in['role'] ?? '', ['advance' => 'Advance', 'balance' => 'Balance / final', 'other' => 'Other'], 'payment type'));
        $gross = $try(static fn () => (int) FieldRules::moneyMinor($in['gross'] ?? '', 'Amount received', true));
        $tax = $try(static fn () => (int) FieldRules::moneyMinor($in['tax'] ?? '0', 'Tax included in the amount', false));
        $method = $try(static fn () => (string) FieldRules::choice($in['method'] ?? '', (array) Config::get('referrals.payment_methods'), 'payment method'));
        $extRef = $try(static fn () => (string) FieldRules::line($in['external_ref'] ?? '', 'Bank, UPI or gateway transaction reference', 3, 120));
        $invoice = $try(static fn () => FieldRules::line($in['invoice_ref'] ?? '', 'Invoice reference', 2, 120, false));
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

        return Database::transaction(function () use ($actorId, $project, $role, $gross, $tax, $method, $extRef, $key, $invoice, $notes, $received, $idempotent): array {
            $existing = Database::one('SELECT id, public_id, reference, project_id, gross_minor FROM client_payments WHERE external_key = ? FOR UPDATE', [$key]);
            if ($existing !== null) {
                if ($idempotent && (int) $existing['project_id'] === (int) $project['id'] && (int) $existing['gross_minor'] === $gross) {
                    return ['public_id' => (string) $existing['public_id'], 'reference' => (string) $existing['reference'], 'created' => false];
                }
                throw new ValidationException(["That transaction reference is already recorded ({$existing['reference']}). The same payment cannot be recorded twice."]);
            }
            $ref = CrmReference::next('client_payment');
            $pub = uuid_hex();
            Database::run(
                "INSERT INTO client_payments (public_id, reference, project_id, client_id, invoice_ref, payment_role, gross_minor, tax_minor, method, external_ref, external_key, received_on, status, notes, recorded_by, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending_verification', ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())",
                [$pub, $ref, $project['id'], $project['client_id'], $invoice, $role, $gross, $tax, $method, $extRef, $key, $received, $notes, $actorId],
            );
            $id = Database::insertId();
            self::event($id, 'recorded', $gross, null, $actorId);
            Audit::record('client_payment.recorded', 'client_payment', $id, [], ['reference' => $ref, 'role' => $role, 'gross_minor' => $gross, 'tax_minor' => $tax, 'method' => $method], ['project_id' => (int) $project['id']], $actorId);
            Notifier::send(Notifier::withPermission('payments.reconcile'), 'client_payment.needs_verification', 'A client payment needs verification', $ref, '/client-payments/' . $pub, null, 'client_payment', $id, "cpay:{$id}:recorded", $actorId);

            return ['public_id' => $pub, 'reference' => $ref, 'created' => true];
        });
    }

    /** @param array<string,mixed> $payment */
    public static function verify(int $actorId, array $payment): void
    {
        Gate::authorize('payments.reconcile', $actorId);
        Database::transaction(function () use ($actorId, $payment): void {
            $row = Database::one('SELECT * FROM client_payments WHERE id = ? FOR UPDATE', [$payment['id']]);
            if ($row === null || $row['status'] !== 'pending_verification') {
                throw new ValidationException(['This payment is not waiting for verification.']);
            }
            if (CommissionRules::current()['rules']['separation_of_duties'] && (int) $row['recorded_by'] === $actorId) {
                throw new ValidationException(['The person who recorded a payment cannot verify it. Ask a second authorised person.']);
            }
            Database::run("UPDATE client_payments SET status = 'verified', verified_by = ?, verified_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE id = ?", [$actorId, $row['id']]);
            self::event((int) $row['id'], 'verified', (int) $row['gross_minor'], null, $actorId);
            Audit::record('client_payment.verified', 'client_payment', (int) $row['id'], ['status' => 'pending_verification'], ['status' => 'verified'], ['project_id' => (int) $row['project_id']], $actorId);
            CommissionService::evaluateProject((int) $row['project_id'], $actorId);
        });
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
            if ($row === null || $row['status'] === 'pending_verification') {
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
            Audit::record('client_payment.' . $type, 'client_payment', (int) $row['id'], ['refunded_minor' => (int) $row['refunded_minor']], ['refunded_minor' => $new], ['project_id' => (int) $row['project_id'], 'reason_recorded' => true], $actorId);
            CommissionService::evaluateProject((int) $row['project_id'], $actorId);
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
            'SELECT cp.*, p.public_id AS project_public_id, p.code AS project_code, p.title AS project_title, p.approved_amount_minor, c.contact_name AS client_name,
                    ru.name AS recorded_by_name, vu.name AS verified_by_name
               FROM client_payments cp JOIN projects p ON p.id = cp.project_id JOIN clients c ON c.id = cp.client_id LEFT JOIN users ru ON ru.id = cp.recorded_by LEFT JOIN users vu ON vu.id = cp.verified_by
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
        if (in_array($f['status'] ?? '', ['pending_verification', 'verified', 'reversed'], true)) {
            $w[] = 'cp.status = ?';
            $p[] = $f['status'];
        }
        [$w, $p] = PartnerApplicationService::dateRange($w, $p, 'cp.created_at', $f);
        $cond = implode(' AND ', $w);
        $base = 'FROM client_payments cp JOIN projects p ON p.id = cp.project_id';
        $total = (int) Database::one("SELECT COUNT(*) AS c {$base} WHERE {$cond}", $p)['c'];
        $offset = (int) min(max(0, ($page - 1) * $perPage), 2_000_000_000);
        $rows = Database::all("SELECT cp.public_id, cp.reference, cp.payment_role, cp.gross_minor, cp.tax_minor, cp.refunded_minor, cp.status, cp.method, cp.received_on, cp.created_at, p.code AS project_code, p.title AS project_title
                               {$base} WHERE {$cond} ORDER BY (cp.status = 'pending_verification') DESC, cp.id DESC LIMIT {$perPage} OFFSET {$offset}", $p);

        return ['rows' => $rows, 'total' => $total];
    }
}
