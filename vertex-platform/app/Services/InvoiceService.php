<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Database;
use Vertex\Core\HttpException;
use Vertex\Core\ValidationException;

/**
 * Invoices without tax lines (the business is not GST-registered; the printed tax statement comes from the business profile and is frozen into the invoice
 * when it is issued).
 *
 *   Draft -> Issued -> Partially paid -> Paid          Void (with a reason; only while nothing was paid and nothing is awaiting verification)
 *
 * - The invoice number is assigned when the invoice is issued, is sequential and is never reused; a draft has no number.
 * - An issued invoice is immutable. To change it, void it (reason, password) and issue a new one.
 * - Payments are rows of `client_payments` linked by invoice_id (no second payments table). The status follows the VERIFIED, refund-adjusted payments:
 *   Paid when they cover the total, Partially paid when some money is verified. Pending or rejected claims never change the status.
 */
final class InvoiceService
{
    /** @return array<string,mixed>|null */
    public static function find(string $publicId): ?array
    {
        return preg_match('/^[a-f0-9]{32}$/', $publicId) ? Database::one(
            "SELECT i.*, COALESCE(NULLIF(c.company_name, ''), c.contact_name) AS client_name, c.public_id AS client_public_id, c.user_id AS client_user_id, p.public_id AS project_public_id, p.code AS project_code,
                    q.public_id AS quotation_public_id, q.reference AS quotation_reference
               FROM invoices i JOIN clients c ON c.id = i.client_id LEFT JOIN projects p ON p.id = i.project_id LEFT JOIN quotations q ON q.id = i.quotation_id WHERE i.public_id = ?",
            [$publicId],
        ) : null;
    }

    /** @return array<string,mixed> */
    public static function requireStaff(int $userId, string $publicId): array
    {
        $i = Gate::can('invoices.view_all', $userId) ? self::find($publicId) : null;

        return $i ?? throw new HttpException(404);
    }

    /** The signed-in client's own, already-issued invoice (never a draft). @return array<string,mixed> */
    public static function requireOwn(int $userId, string $publicId): array
    {
        $i = Gate::can('invoices.view_own', $userId) ? self::find($publicId) : null;
        if ($i === null || $i['client_user_id'] === null || (int) $i['client_user_id'] !== $userId || $i['status'] === 'draft') {
            throw new HttpException(404);
        }

        return $i;
    }

    /** @return list<array<string,mixed>> */
    public static function items(array $i): array
    {
        return Database::all('SELECT position, description, quantity_x100, unit_minor, total_minor FROM invoice_items WHERE invoice_id = ? ORDER BY position', [$i['id']]);
    }

    /** What the client has to pay: the total less any credit notes. */
    public static function payable(array $i): int
    {
        return (int) $i['total_minor'] - (int) ($i['credited_minor'] ?? 0);
    }

    /** Verified money in, refunds taken off. */
    public static function paidMinor(int $invoiceId): int
    {
        return (int) Database::one("SELECT COALESCE(SUM(GREATEST(0, gross_minor - refunded_minor)), 0) AS s FROM client_payments WHERE invoice_id = ? AND status IN ('verified', 'reversed')", [$invoiceId])['s'];
    }

    /** Submitted but not yet verified or rejected. */
    public static function pendingMinor(int $invoiceId): int
    {
        return (int) Database::one("SELECT COALESCE(SUM(gross_minor), 0) AS s FROM client_payments WHERE invoice_id = ? AND status = 'pending_verification'", [$invoiceId])['s'];
    }

    /** @return array{paid:int,pending:int,outstanding:int,overdue:bool} */
    public static function balance(array $i): array
    {
        $paid = self::paidMinor((int) $i['id']);

        return ['paid' => $paid, 'pending' => self::pendingMinor((int) $i['id']), 'outstanding' => max(0, self::payable($i) - $paid),
            'overdue' => in_array($i['status'], ['issued', 'partially_paid'], true) && $i['due_date'] !== null && $i['due_date'] < AppTime::today()];
    }

    /** @return list<array<string,mixed>> payments of the invoice for staff (everything) */
    public static function payments(array $i): array
    {
        return Database::all('SELECT cp.public_id, cp.reference, cp.gross_minor, cp.refunded_minor, cp.method, cp.source, cp.external_ref, cp.received_on, cp.status, cp.created_at FROM client_payments cp WHERE cp.invoice_id = ? ORDER BY cp.id', [$i['id']]);
    }

    /** What the client may see: verified payments only, no internal references. @return list<array<string,mixed>> */
    public static function clientPayments(array $i): array
    {
        return Database::all("SELECT cp.public_id, cp.reference, cp.gross_minor, cp.refunded_minor, cp.method, cp.received_on, cp.status FROM client_payments cp WHERE cp.invoice_id = ? AND cp.status IN ('verified','reversed') ORDER BY cp.id", [$i['id']]);
    }

    // ------------------------------------------------------------------------------------------------ drafts

    /** @param array<string,mixed> $in */
    private static function validated(array $in, array $client): array
    {
        $errors = [];
        $get = static function (callable $fn) use (&$errors): mixed {
            try {
                return $fn();
            } catch (ValidationException $e) {
                array_push($errors, ...$e->errors);

                return null;
            }
        };
        $title = (string) $get(static fn () => FieldRules::line($in['title'] ?? '', 'Title', 3, 200));
        $due = $get(static fn () => FieldRules::date($in['due_date'] ?? '', 'Due date', false));
        $cn = $get(static fn () => FieldRules::text($in['client_notes'] ?? '', 'Notes for the client', 2, 3000, false));
        $inn = $get(static fn () => FieldRules::text($in['internal_notes'] ?? '', 'Internal notes', 2, 3000, false));
        $items = (array) $get(static fn () => BillingItems::parse($in['items'] ?? []));
        $discount = (int) $get(static fn () => FieldRules::moneyMinor($in['discount'] ?? '0', 'Discount', false));
        if ($items !== [] && $discount > (int) $items['subtotal_minor']) {
            $errors[] = 'The discount cannot be more than the subtotal.';
        }
        $couponCode = CouponService::normaliseCode((string) ($in['coupon_code'] ?? ''));
        $couponId = null;
        $couponDiscount = 0;
        $project = null;
        $pid = trim((string) ($in['project'] ?? ''));
        if ($pid !== '') {
            $project = ProjectPolicy::find($pid);
            if ($project === null || (int) $project['client_id'] !== (int) $client['id']) {
                $errors[] = 'Choose one of this client\'s projects.';
            }
        }
        if ($couponCode !== '' && $errors === []) {
            try {
                $res = CouponService::evaluate($couponCode, (int) $client['id'], (int) $items['subtotal_minor'] - $discount, $project['service_category'] ?? null, isset($in['_invoice_id']) ? (int) $in['_invoice_id'] : null);
                $couponId = (int) $res['coupon']['id'];
                $couponDiscount = $res['discount_minor'];
            } catch (ValidationException $e) {
                array_push($errors, ...$e->errors);
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return ['title' => $title, 'due' => is_string($due) ? $due : null, 'client_notes' => is_string($cn) ? $cn : null, 'internal_notes' => is_string($inn) ? $inn : null, 'items' => $items, 'discount' => $discount, 'project' => $project,
            'coupon_id' => $couponId, 'coupon_code' => $couponId === null ? null : $couponCode, 'coupon_discount' => $couponDiscount];
    }

    /** @param array<string,mixed> $in @return array{public_id:string} */
    public static function create(int $actorId, string $clientPublicId, array $in, ?array $quotation = null): array
    {
        Gate::authorize('invoices.create', $actorId);
        $client = ClientPolicy::find($clientPublicId);
        if ($client === null || $client['archived_at'] !== null) {
            throw new ValidationException(['Choose an existing client.']);
        }
        $v = self::validated($in, $client);
        $pub = uuid_hex();

        return Database::transaction(function () use ($actorId, $client, $v, $pub, $quotation): array {
            $subtotal = (int) $v['items']['subtotal_minor'];
            Database::run("INSERT INTO invoices (public_id, client_id, project_id, quotation_id, status, title, due_date, subtotal_minor, discount_minor, coupon_id, coupon_code, coupon_discount_minor, total_minor, client_notes, internal_notes, created_by, created_at, updated_at)
                           VALUES (?, ?, ?, ?, 'draft', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())",
                [$pub, $client['id'], $v['project']['id'] ?? null, $quotation['id'] ?? null, $v['title'], $v['due'], $subtotal, $v['discount'], $v['coupon_id'], $v['coupon_code'], $v['coupon_discount'], $subtotal - $v['discount'] - $v['coupon_discount'], $v['client_notes'], $v['internal_notes'], $actorId]);
            $id = Database::insertId();
            BillingItems::replace('invoice_items', 'invoice_id', $id, $v['items']['items']);
            Audit::record('invoice.drafted', 'invoice', $id, [], ['total_minor' => $subtotal - $v['discount'] - $v['coupon_discount'], 'client_id' => (int) $client['id'], 'quotation_id' => $quotation['id'] ?? null], [], $actorId);

            return ['public_id' => $pub];
        });
    }

    /** A draft invoice with the lines of an accepted quotation. */
    public static function createFromQuotation(int $actorId, array $quotation): array
    {
        Gate::authorize('invoices.create', $actorId);
        if ($quotation['status'] !== 'accepted') {
            throw new ValidationException(['Only an accepted quotation can be turned into an invoice.']);
        }
        if (Database::one("SELECT 1 AS x FROM invoices WHERE quotation_id = ? AND status <> 'void'", [$quotation['id']]) !== null) {
            throw new ValidationException(['An invoice already exists for this quotation.']);
        }
        $rows = array_map(static fn (array $r): array => ['description' => $r['description'], 'quantity' => BillingItems::qty((int) $r['quantity_x100']), 'unit' => number_format((int) $r['unit_minor'] / 100, 2, '.', '')], QuotationService::items($quotation));

        return self::create($actorId, (string) $quotation['client_public_id'], ['title' => $quotation['title'], 'project' => $quotation['project_public_id'] ?? '', 'client_notes' => (string) ($quotation['client_notes'] ?? ''), 'items' => $rows], $quotation);
    }

    /** @param array<string,mixed> $in */
    public static function update(int $actorId, array $i, array $in): void
    {
        Gate::authorize('invoices.edit', $actorId);
        $client = Database::one('SELECT * FROM clients WHERE id = ?', [$i['client_id']]);
        $v = self::validated($in + ['_invoice_id' => $i['id']], $client);
        Database::transaction(function () use ($actorId, $i, $v): void {
            $cur = Database::one('SELECT status, total_minor FROM invoices WHERE id = ? FOR UPDATE', [$i['id']]);
            if ($cur === null || $cur['status'] !== 'draft') {
                throw new ValidationException(['Only a draft invoice can be edited. An issued invoice is never changed: void it and issue a new one.']);
            }
            $subtotal = (int) $v['items']['subtotal_minor'];
            Database::run('UPDATE invoices SET title = ?, project_id = ?, due_date = ?, subtotal_minor = ?, discount_minor = ?, coupon_id = ?, coupon_code = ?, coupon_discount_minor = ?, total_minor = ?, client_notes = ?, internal_notes = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?',
                [$v['title'], $v['project']['id'] ?? null, $v['due'], $subtotal, $v['discount'], $v['coupon_id'], $v['coupon_code'], $v['coupon_discount'], $subtotal - $v['discount'] - $v['coupon_discount'], $v['client_notes'], $v['internal_notes'], $i['id']]);
            BillingItems::replace('invoice_items', 'invoice_id', (int) $i['id'], $v['items']['items']);
            Audit::record('invoice.draft_updated', 'invoice', (int) $i['id'], ['total_minor' => (int) $cur['total_minor']], ['total_minor' => $subtotal - $v['discount'] - $v['coupon_discount']], [], $actorId);
        });
    }

    // ------------------------------------------------------------------------------------------------ issue / void

    public static function issue(int $actorId, array $i): void
    {
        Gate::authorize('invoices.issue', $actorId);
        if (!BusinessProfile::isComplete()) {
            throw new ValidationException(['Set your business details first (Administration → Business details): they are printed on every invoice.']);
        }
        Database::transaction(function () use ($actorId, $i): void {
            $cur = Database::one('SELECT * FROM invoices WHERE id = ? FOR UPDATE', [$i['id']]);
            if ($cur === null || $cur['status'] !== 'draft') {
                throw new ValidationException(['Only a draft can be issued.']);
            }
            if ((int) $cur['total_minor'] < 1) {
                throw new ValidationException(['An invoice needs at least one line and a total above zero.']);
            }
            CouponService::redeem($cur, $actorId);
            $ref = CrmReference::next('invoice');
            $today = AppTime::today();
            $due = $cur['due_date'] ?? AppTime::todayPlus(14);
            if ($due < $today) {
                throw new ValidationException(['The due date is in the past. Choose today or later.']);
            }
            $profile = BusinessProfile::get();
            $snapshot = json_encode(array_intersect_key($profile, array_flip(['trade_name', 'legal_name', 'address', 'email', 'phone', 'website', 'tax_statement', 'payment_instructions', 'invoice_footer'])), JSON_THROW_ON_ERROR);
            Database::run("UPDATE invoices SET status = 'issued', reference = ?, issue_date = ?, due_date = ?, business_snapshot = ?, issued_by = ?, issued_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE id = ?", [$ref, $today, $due, $snapshot, $actorId, $i['id']]);
            Audit::record('invoice.issued', 'invoice', (int) $i['id'], ['status' => 'draft'], ['status' => 'issued', 'reference' => $ref, 'total_minor' => (int) $cur['total_minor']], [], $actorId);
            if ($i['client_user_id']) {
                Notifier::send([(int) $i['client_user_id']], 'invoice.issued', 'Invoice ' . $ref . ' for ₹' . FieldRules::formatMinor((int) $cur['total_minor']), $cur['title'] . '. Due ' . $due . '.', '/my/invoices/' . $i['public_id'], $i['project_id'] === null ? null : (int) $i['project_id'], 'invoice', (int) $i['id'], 'inv:' . $i['id'] . ':issued');
            }
        });
    }

    /** Voids a draft or an issued invoice that has no money in or awaiting verification. The number is never reused. */
    public static function void(int $actorId, array $i, string $reason): void
    {
        Gate::authorize('invoices.void', $actorId);
        $reason = (string) FieldRules::line($reason, 'Reason', 10, 255);
        Database::transaction(function () use ($actorId, $i, $reason): void {
            $cur = Database::one('SELECT * FROM invoices WHERE id = ? FOR UPDATE', [$i['id']]);
            if ($cur === null || $cur['status'] === 'void') {
                throw new ValidationException(['This invoice is already void.']);
            }
            if (Database::one("SELECT 1 AS x FROM client_payments WHERE invoice_id = ? AND status IN ('pending_verification','verified','reversed')", [$i['id']]) !== null) {
                throw new ValidationException(['Payments are recorded against this invoice (or are awaiting verification). Reject or refund them first; an invoice with money on it cannot be voided.']);
            }
            Database::run("UPDATE invoices SET status = 'void', voided_by = ?, voided_at = UTC_TIMESTAMP(), void_reason = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?", [$actorId, $reason, $i['id']]);
            Audit::record('invoice.voided', 'invoice', (int) $i['id'], ['status' => $cur['status']], ['status' => 'void'], ['reference' => $cur['reference'], 'reason_recorded' => true], $actorId);
            CouponService::release((int) $i['id'], 'invoice voided');
        });
    }

    /** Recomputes Issued / Partially paid / Paid from the verified payments. Called after every verification, refund and reversal. */
    public static function sync(int $invoiceId): void
    {
        $i = Database::one('SELECT * FROM invoices WHERE id = ? FOR UPDATE', [$invoiceId]);
        if ($i === null || in_array($i['status'], ['draft', 'void'], true)) {
            return;
        }
        $paid = self::paidMinor($invoiceId);
        $to = $paid >= self::payable($i) ? 'paid' : ($paid > 0 ? 'partially_paid' : 'issued');
        if ($to !== $i['status']) {
            Database::run('UPDATE invoices SET status = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?', [$to, $invoiceId]);
            Audit::record('invoice.status_changed', 'invoice', $invoiceId, ['status' => $i['status']], ['status' => $to], ['paid_minor' => $paid], null);
            if ($to === 'paid' && $i['client_id']) {
                $uid = Database::one('SELECT user_id FROM clients WHERE id = ?', [$i['client_id']])['user_id'] ?? null;
                if ($uid) {
                    Notifier::send([(int) $uid], 'invoice.paid', 'Invoice ' . $i['reference'] . ' is paid', 'Thank you. Your receipt is on the invoice page.', '/my/invoices/' . $i['public_id'], null, 'invoice', $invoiceId, 'inv:' . $invoiceId . ':paid');
                }
            }
        }
    }

    // ------------------------------------------------------------------------------------------------ lists

    /** @param array<string,mixed> $f @return array{rows:list<array<string,mixed>>,total:int} */
    public static function paginate(int $userId, array $f, int $page = 1, int $perPage = 20): array
    {
        if (!Gate::can('invoices.view_all', $userId)) {
            throw new HttpException(403);
        }
        $w = ['1=1'];
        $p = [];
        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
            $w[] = '(i.reference LIKE ? OR i.title LIKE ? OR c.contact_name LIKE ? OR c.company_name LIKE ?)';
            array_push($p, $like, $like, $like, $like);
        }
        if (in_array($f['status'] ?? '', ['draft', 'issued', 'partially_paid', 'paid', 'void'], true)) {
            $w[] = 'i.status = ?';
            $p[] = $f['status'];
        }
        if (!empty($f['overdue'])) {
            $w[] = "i.status IN ('issued','partially_paid') AND i.due_date < ?";
            $p[] = AppTime::today();
        }
        if (preg_match('/^[a-f0-9]{32}$/', (string) ($f['client'] ?? ''))) {
            $w[] = 'c.public_id = ?';
            $p[] = $f['client'];
        }
        foreach (['from' => '>=', 'to' => '<='] as $k => $op) {
            $d = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) ($f[$k] ?? ''));
            if ($d !== false && $d->format('Y-m-d') === $f[$k]) {
                $w[] = "i.issue_date {$op} ?";
                $p[] = $f[$k];
            }
        }
        $cond = implode(' AND ', $w);
        $perPage = max(1, min(100, $perPage));
        $total = (int) Database::one("SELECT COUNT(*) AS c FROM invoices i JOIN clients c ON c.id = i.client_id WHERE {$cond}", $p)['c'];
        $offset = (int) min(max(0, ($page - 1) * $perPage), 2_000_000_000);
        $rows = Database::all("SELECT i.public_id, i.reference, i.title, i.status, i.total_minor, i.issue_date, i.due_date, COALESCE(NULLIF(c.company_name, ''), c.contact_name) AS client_name,
                                      (SELECT COALESCE(SUM(GREATEST(0, cp.gross_minor - cp.refunded_minor)), 0) FROM client_payments cp WHERE cp.invoice_id = i.id AND cp.status IN ('verified','reversed')) AS paid_minor
                                 FROM invoices i JOIN clients c ON c.id = i.client_id WHERE {$cond} ORDER BY i.id DESC LIMIT {$perPage} OFFSET {$offset}", $p);

        return ['rows' => $rows, 'total' => $total];
    }

    /** @return array<string,int> */
    public static function dashboard(int $userId): array
    {
        if (!Gate::can('invoices.view_all', $userId)) {
            throw new HttpException(403);
        }
        $today = AppTime::today();

        return [
            'outstanding_minor' => (int) Database::one("SELECT COALESCE(SUM(i.total_minor - i.credited_minor), 0) - COALESCE((SELECT SUM(GREATEST(0, cp.gross_minor - cp.refunded_minor)) FROM client_payments cp JOIN invoices ii ON ii.id = cp.invoice_id WHERE ii.status IN ('issued','partially_paid') AND cp.status IN ('verified','reversed')), 0) AS s FROM invoices i WHERE i.status IN ('issued','partially_paid')")['s'],
            'overdue' => (int) Database::one("SELECT COUNT(*) AS c FROM invoices WHERE status IN ('issued','partially_paid') AND due_date < ?", [$today])['c'],
            'drafts' => (int) Database::one("SELECT COUNT(*) AS c FROM invoices WHERE status = 'draft'")['c'],
            'to_verify' => (int) Database::one("SELECT COUNT(*) AS c FROM client_payments WHERE invoice_id IS NOT NULL AND status = 'pending_verification'")['c'],
        ];
    }

    /** @return list<array<string,mixed>> the client's issued invoices */
    public static function forClient(int $userId): array
    {
        if (!Gate::can('invoices.view_own', $userId)) {
            return [];
        }

        return Database::all("SELECT i.public_id, i.reference, i.title, i.status, i.total_minor, i.issue_date, i.due_date,
                                     (SELECT COALESCE(SUM(GREATEST(0, cp.gross_minor - cp.refunded_minor)), 0) FROM client_payments cp WHERE cp.invoice_id = i.id AND cp.status IN ('verified','reversed')) AS paid_minor
                                FROM invoices i JOIN clients c ON c.id = i.client_id WHERE c.user_id = ? AND i.status <> 'draft' ORDER BY i.id DESC", [$userId]);
    }
}
