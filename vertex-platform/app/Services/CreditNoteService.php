<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Database;
use Vertex\Core\HttpException;
use Vertex\Core\ValidationException;

/**
 * Credit notes (module 36 "controlled corrections"). An issued invoice is never edited; when part of it must not be charged, a credit note reduces what is
 * still owed. Rules: only on an issued/partially-paid invoice, never more than the unpaid balance (money already received comes back as a refund), numbered
 * CRN-YYYY-NNNNN, immutable, and voidable (with a reason) while the invoice is not void. The invoice's payable total is total_minor - credited_minor.
 */
final class CreditNoteService
{
    /** @return array<string,mixed>|null */
    public static function find(string $publicId): ?array
    {
        return preg_match('/^[a-f0-9]{32}$/', $publicId) ? Database::one(
            "SELECT n.*, i.public_id AS invoice_public_id, i.reference AS invoice_reference, i.title AS invoice_title, COALESCE(NULLIF(c.company_name, ''), c.contact_name) AS client_name, c.public_id AS client_public_id, c.user_id AS client_user_id
               FROM credit_notes n JOIN invoices i ON i.id = n.invoice_id JOIN clients c ON c.id = n.client_id WHERE n.public_id = ?",
            [$publicId],
        ) : null;
    }

    /** @return array<string,mixed> */
    public static function requireStaff(int $userId, string $publicId): array
    {
        $n = Gate::can('invoices.view_all', $userId) ? self::find($publicId) : null;

        return $n ?? throw new HttpException(404);
    }

    /** The signed-in client's own credit note. @return array<string,mixed> */
    public static function requireOwn(int $userId, string $publicId): array
    {
        $n = Gate::can('invoices.view_own', $userId) ? self::find($publicId) : null;
        if ($n === null || $n['client_user_id'] === null || (int) $n['client_user_id'] !== $userId) {
            throw new HttpException(404);
        }

        return $n;
    }

    /** @return list<array<string,mixed>> */
    public static function forInvoice(array $invoice, bool $includeVoid = true): array
    {
        return Database::all('SELECT public_id, reference, amount_minor, reason, status, issued_at, void_reason FROM credit_notes WHERE invoice_id = ?' . ($includeVoid ? '' : " AND status = 'issued'") . ' ORDER BY id', [$invoice['id']]);
    }

    /** What may still be credited: unpaid balance not already awaiting verification. */
    public static function creditable(array $invoice): int
    {
        $payable = (int) $invoice['total_minor'] - (int) $invoice['credited_minor'];

        $held = InvoiceService::paidMinor((int) $invoice['id']) + InvoiceService::pendingMinor((int) $invoice['id']);

        // Never down to zero: crediting the whole invoice is a cancellation, which is what Void is for.
        return max(0, $payable - max($held, 1));
    }

    public static function issue(int $actorId, array $invoice, string $amount, string $reason): array
    {
        Gate::authorize('creditnotes.issue', $actorId);
        $minor = FieldRules::moneyMinor($amount, 'Credit amount', true);
        $reason = (string) FieldRules::line($reason, 'Reason', 10, 255);
        if (!BusinessProfile::isComplete()) {
            throw new ValidationException(['Set your business details first (Administration → Business details).']);
        }

        return Database::transaction(function () use ($actorId, $invoice, $minor, $reason): array {
            $cur = Database::one('SELECT * FROM invoices WHERE id = ? FOR UPDATE', [$invoice['id']]);
            if ($cur === null || !in_array($cur['status'], ['issued', 'partially_paid'], true)) {
                throw new ValidationException(['A credit note can only be raised against an issued invoice that is not yet fully paid.']);
            }
            $room = self::creditable($cur);
            if ($minor < 1 || $minor > $room) {
                throw new ValidationException(['The credit must be between 0.01 and ₹' . FieldRules::formatMinor($room) . ' (the unpaid balance; to cancel the whole invoice use Void). Money already received is returned through a refund.']);
            }
            $ref = CrmReference::next('credit_note');
            $pub = uuid_hex();
            $profile = BusinessProfile::get();
            $snapshot = json_encode(array_intersect_key($profile, array_flip(['trade_name', 'legal_name', 'address', 'email', 'phone', 'website', 'tax_statement', 'invoice_footer'])), JSON_THROW_ON_ERROR);
            Database::run("INSERT INTO credit_notes (public_id, reference, invoice_id, client_id, amount_minor, reason, status, business_snapshot, issued_by, issued_at) VALUES (?, ?, ?, ?, ?, ?, 'issued', ?, ?, UTC_TIMESTAMP())", [$pub, $ref, $cur['id'], $cur['client_id'], $minor, $reason, $snapshot, $actorId]);
            $id = Database::insertId();
            Database::run('UPDATE invoices SET credited_minor = credited_minor + ?, updated_at = UTC_TIMESTAMP() WHERE id = ?', [$minor, $cur['id']]);
            Audit::record('credit_note.issued', 'credit_note', $id, [], ['reference' => $ref, 'amount_minor' => $minor, 'invoice_id' => (int) $cur['id']], ['reason_recorded' => true], $actorId);
            InvoiceService::sync((int) $cur['id']);
            $uid = Database::one('SELECT user_id FROM clients WHERE id = ?', [$cur['client_id']])['user_id'] ?? null;
            if ($uid) {
                Notifier::send([(int) $uid], 'credit_note.issued', 'Credit note ' . $ref . ' for ₹' . FieldRules::formatMinor($minor), 'It reduces invoice ' . $cur['reference'] . '.', '/my/invoices/' . $cur['public_id'], null, 'credit_note', $id, 'crn:' . $id);
            }

            return ['public_id' => $pub, 'reference' => $ref];
        });
    }

    public static function void(int $actorId, array $note, string $reason): void
    {
        Gate::authorize('creditnotes.issue', $actorId);
        $reason = (string) FieldRules::line($reason, 'Reason', 10, 255);
        Database::transaction(function () use ($actorId, $note, $reason): void {
            $n = Database::one('SELECT * FROM credit_notes WHERE id = ? FOR UPDATE', [$note['id']]);
            if ($n === null || $n['status'] !== 'issued') {
                throw new ValidationException(['This credit note is already void.']);
            }
            $inv = Database::one('SELECT * FROM invoices WHERE id = ? FOR UPDATE', [$n['invoice_id']]);
            if ($inv === null || $inv['status'] === 'void') {
                throw new ValidationException(['The invoice is void, so its credit notes stay as they are.']);
            }
            Database::run("UPDATE credit_notes SET status = 'void', voided_by = ?, voided_at = UTC_TIMESTAMP(), void_reason = ? WHERE id = ?", [$actorId, $reason, $n['id']]);
            Database::run('UPDATE invoices SET credited_minor = GREATEST(0, credited_minor - ?), updated_at = UTC_TIMESTAMP() WHERE id = ?', [$n['amount_minor'], $inv['id']]);
            Audit::record('credit_note.voided', 'credit_note', (int) $n['id'], ['status' => 'issued'], ['status' => 'void'], ['reference' => $n['reference'], 'reason_recorded' => true], $actorId);
            InvoiceService::sync((int) $inv['id']);
        });
    }
}
