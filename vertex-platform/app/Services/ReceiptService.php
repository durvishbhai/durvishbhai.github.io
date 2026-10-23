<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Config;
use Vertex\Core\Database;
use Vertex\Core\HttpException;
use Vertex\Core\ValidationException;

/**
 * Payment receipts (KIS-PR-YYYY-NNNN).
 *
 * A receipt is made by the system the moment a payment is confirmed (a verified client payment, a payment from Razorpay, a payment counted on a renewal), or
 * by hand by the team for a payment made outside the system. It is never edited or deleted; it can only be voided with a reason. The client's details and the
 * amounts are frozen on the receipt, and `seal` is a keyed hash of the important facts: the public verification page recomputes it, so a receipt whose row was
 * altered in the database does not verify. Verification needs the receipt's code (printed on it and inside its QR); the page never shows contact details.
 */
final class ReceiptService
{
    public const METHODS = ['upi' => 'UPI', 'bank_transfer' => 'Bank transfer', 'card' => 'Card', 'cash' => 'Cash', 'cheque' => 'Cheque', 'gateway' => 'Online (Razorpay)', 'other' => 'Other'];

    // ------------------------------------------------------------------------------------------------ issuing

    /** The receipt of a verified client payment, made on first need and never twice. @return array<string,mixed>|null null while the payment is not verified */
    public static function issueForPayment(int $paymentId, ?int $actorId = null): ?array
    {
        $p = Database::one('SELECT cp.*, i.reference AS invoice_reference, i.title AS invoice_title, pr.code AS project_code, pr.title AS project_title FROM client_payments cp LEFT JOIN invoices i ON i.id = cp.invoice_id LEFT JOIN projects pr ON pr.id = cp.project_id WHERE cp.id = ?', [$paymentId]);
        if ($p === null || !in_array($p['status'], ['verified', 'reversed'], true)) {
            return null;
        }
        $key = hash('sha256', 'rcpt:client_payment:' . $paymentId);
        $have = Database::one('SELECT * FROM receipts WHERE external_key = ?', [$key]);
        if ($have !== null) {
            return $have;
        }
        $gross = (int) $p['gross_minor'];
        $tax = min((int) $p['tax_minor'], $gross);
        if ($p['invoice_reference'] !== null) {
            $what = 'Payment towards invoice ' . $p['invoice_reference'] . ($p['invoice_title'] ? ' - ' . $p['invoice_title'] : '');
        } elseif ($p['project_code'] !== null) {
            $what = 'Payment for project ' . $p['project_code'] . ' - ' . $p['project_title'] . ' (' . ['advance' => 'advance', 'balance' => 'balance', 'other' => 'payment'][$p['payment_role']] . ')';
        } else {
            $what = 'Payment ' . $p['reference'];
        }
        $gateway = ($p['source'] ?? '') === 'gateway';
        $paidAt = $gateway && $p['verified_at'] !== null ? (string) $p['verified_at'] : self::utcFromLocalDate((string) $p['received_on']);

        return self::create([
            'source' => 'client_payment', 'source_id' => $paymentId, 'external_key' => $key, 'client_id' => (int) $p['client_id'], 'method' => (string) $p['method'], 'txn_id' => (string) $p['external_ref'],
            'paid_at' => $paidAt, 'time_known' => $gateway ? 1 : 0, 'lines' => [['description' => $what, 'amount_minor' => $gross - $tax]], 'subtotal_minor' => $gross - $tax, 'discount_minor' => 0, 'tax_minor' => $tax,
            'paid_minor' => $gross, 'against' => $p['invoice_reference'] ?? $p['project_code'], 'note' => null,
        ], $actorId);
    }

    /** The receipt of a payment counted on a domain/hosting renewal. @return array<string,mixed>|null */
    public static function issueForRenewalPayment(int $renewalPaymentId, ?int $actorId = null): ?array
    {
        $p = Database::one('SELECT rp.*, cy.invoice_ref, s.identifier, s.reference AS service_reference, s.service_type, s.client_id FROM renewal_payments rp JOIN renewal_cycles cy ON cy.id = rp.cycle_id JOIN renewal_services s ON s.id = cy.service_id WHERE rp.id = ?', [$renewalPaymentId]);
        if ($p === null) {
            return null;
        }
        $key = hash('sha256', 'rcpt:renewal_payment:' . $renewalPaymentId);
        $have = Database::one('SELECT * FROM receipts WHERE external_key = ?', [$key]);
        if ($have !== null) {
            return $have;
        }
        $type = (string) Config::get("renewals.types.{$p['service_type']}", 'Service');
        $gateway = $p['method'] === 'gateway';

        return self::create([
            'source' => 'renewal_payment', 'source_id' => $renewalPaymentId, 'external_key' => $key, 'client_id' => (int) $p['client_id'], 'method' => (string) $p['method'], 'txn_id' => (string) $p['reference'],
            'paid_at' => $gateway ? (string) $p['created_at'] : self::utcFromLocalDate((string) $p['received_on']), 'time_known' => $gateway ? 1 : 0,
            'lines' => [['description' => $type . ' renewal - ' . $p['identifier'] . ($p['invoice_ref'] ? ' (invoice ' . $p['invoice_ref'] . ')' : ''), 'amount_minor' => (int) $p['amount_minor']]],
            'subtotal_minor' => (int) $p['amount_minor'], 'discount_minor' => 0, 'tax_minor' => 0, 'paid_minor' => (int) $p['amount_minor'], 'against' => $p['invoice_ref'] ?? $p['service_reference'], 'note' => null,
        ], $actorId);
    }

    /**
     * A receipt made by hand by the team for a payment that was made outside the system (cash, a bank transfer, a card machine...). It records that money was
     * received; it does not change any invoice balance (record the payment on the invoice for that).
     *
     * @param array<string,mixed> $in client (a client's public id, optional), client_name/email/phone/address (when no client is chosen), lines[] (description, amount), discount, tax,
     *     method, txn_id, paid_on, paid_time, against, note
     * @return array<string,mixed>
     */
    public static function issueManual(int $actorId, array $in): array
    {
        Gate::authorize('receipts.issue', $actorId);
        $errors = [];
        $get = static function (callable $fn) use (&$errors): mixed {
            try {
                return $fn();
            } catch (ValidationException $e) {
                array_push($errors, ...$e->errors);

                return null;
            }
        };
        $client = null;
        $chosen = trim((string) ($in['client'] ?? ''));
        if ($chosen !== '') {
            $client = Database::one('SELECT * FROM clients WHERE public_id = ?', [$chosen]);
            if ($client === null) {
                $errors[] = 'Choose a client from the list, or leave it empty and type the name.';
            }
        }
        $snapshot = $client !== null ? self::clientSnapshot((int) $client['id']) : [
            'client_ref' => '-', 'name' => $get(fn () => FieldRules::line($in['client_name'] ?? '', 'Client name', 2, 160)), 'email' => (string) $get(fn () => FieldRules::line($in['client_email'] ?? '', 'Client email', 3, 190, false)),
            'phone' => (string) $get(fn () => FieldRules::line($in['client_phone'] ?? '', 'Client phone', 5, 32, false)), 'address' => (string) $get(fn () => FieldRules::line($in['client_address'] ?? '', 'Client address', 3, 200, false)),
        ];
        $lines = [];
        foreach ((array) ($in['lines'] ?? []) as $l) {
            $d = trim((string) ($l['description'] ?? ''));
            $a = trim((string) ($l['amount'] ?? ''));
            if ($d === '' && $a === '') {
                continue;
            }
            $desc = $get(fn () => FieldRules::line($d, 'Description', 2, 200));
            $amt = $get(fn () => FieldRules::moneyMinor($a, 'Amount', true));
            if ($desc !== null && $amt !== null) {
                $lines[] = ['description' => $desc, 'amount_minor' => (int) $amt];
            }
        }
        if ($lines === [] && $errors === []) {
            $errors[] = 'Add at least one line: what the payment was for and its amount.';
        }
        $discount = (int) ($get(fn () => FieldRules::moneyMinor($in['discount'] ?? '0', 'Discount', false)) ?? 0);
        $tax = (int) ($get(fn () => FieldRules::moneyMinor($in['tax'] ?? '0', 'Tax', false)) ?? 0);
        $method = (string) $get(fn () => FieldRules::choice($in['method'] ?? '', self::METHODS, 'payment method'));
        $txn = (string) $get(fn () => FieldRules::line($in['txn_id'] ?? '', 'Transaction ID', 3, 120));
        $on = (string) $get(fn () => FieldRules::date($in['paid_on'] ?? '', 'Payment date', true));
        $time = trim((string) ($in['paid_time'] ?? ''));
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
            $errors[] = 'Enter the payment time as HH:MM (24-hour).';
        }
        $against = $get(fn () => FieldRules::line($in['against'] ?? '', 'Against (invoice or project)', 2, 160, false));
        $note = $get(fn () => FieldRules::line($in['note'] ?? '', 'Note', 2, 300, false));
        $subtotal = array_sum(array_column($lines, 'amount_minor'));
        if ($discount > $subtotal) {
            $errors[] = 'The discount cannot be more than the subtotal.';
        }
        $paid = $subtotal - $discount + $tax;
        if ($errors === [] && $paid < 100) {
            $errors[] = 'The amount paid must be at least ₹1.00.';
        }
        if ($on !== '' && $on > AppTime::today()) {
            $errors[] = 'The payment date cannot be in the future.';
        }
        if ($errors !== []) {
            throw new ValidationException(array_values(array_unique($errors)));
        }
        $key = hash('sha256', 'rcpt:manual:' . $method . ':' . mb_strtolower($txn));
        if (Database::one('SELECT number FROM receipts WHERE external_key = ?', [$key]) !== null) {
            $n = (string) Database::one('SELECT number FROM receipts WHERE external_key = ?', [$key])['number'];
            throw new ValidationException(["A receipt for this transaction already exists ({$n}). One payment gets one receipt."]);
        }
        $paidAt = (new \DateTimeImmutable($on . ' ' . $time . ':00', AppTime::zone()))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');

        return self::create([
            'source' => 'manual', 'source_id' => null, 'external_key' => $key, 'client_id' => $client !== null ? (int) $client['id'] : null, 'snapshot' => $snapshot, 'method' => $method, 'txn_id' => $txn, 'paid_at' => $paidAt,
            'time_known' => 1, 'lines' => $lines, 'subtotal_minor' => $subtotal, 'discount_minor' => $discount, 'tax_minor' => $tax, 'paid_minor' => $paid, 'against' => $against, 'note' => $note,
        ], $actorId);
    }

    /** @param array<string,mixed> $d @return array<string,mixed> */
    private static function create(array $d, ?int $actorId): array
    {
        return Database::transaction(function () use ($d, $actorId): array {
            $again = Database::one('SELECT * FROM receipts WHERE external_key = ? FOR UPDATE', [$d['external_key']]);
            if ($again !== null) {
                return $again;
            }
            $number = CrmReference::next('receipt');
            $code = self::newCode();
            $pub = uuid_hex();
            $snapshot = $d['snapshot'] ?? self::clientSnapshot((int) $d['client_id']);
            $now = AppTime::now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            $row = [
                'number' => $number, 'verify_code' => $code, 'paid_at' => $d['paid_at'], 'txn_id' => mb_substr((string) $d['txn_id'], 0, 120), 'method' => (string) $d['method'], 'paid_minor' => (int) $d['paid_minor'],
                'client_name' => (string) ($snapshot['name'] ?? ''),
            ];
            Database::run(
                'INSERT INTO receipts (public_id, number, verify_code, source, source_id, external_key, client_id, client_snapshot, method, txn_id, paid_at, time_known, lines_json, subtotal_minor, discount_minor, tax_minor, paid_minor, against, note, seal, issued_by, issued_at, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())',
                [$pub, $number, $code, $d['source'], $d['source_id'], $d['external_key'], $d['client_id'], json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $d['method'], mb_substr((string) $d['txn_id'], 0, 120), $d['paid_at'], $d['time_known'],
                    json_encode($d['lines'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $d['subtotal_minor'], $d['discount_minor'], $d['tax_minor'], $d['paid_minor'], $d['against'], $d['note'], self::seal($row), $actorId, $now],
            );
            $id = Database::insertId();
            Audit::record('receipt.issued', 'receipt', $id, [], ['number' => $number, 'paid_minor' => (int) $d['paid_minor'], 'source' => $d['source']], ['manual' => $actorId !== null && $d['source'] === 'manual'], $actorId);
            $r = Database::one('SELECT * FROM receipts WHERE id = ?', [$id]) ?? throw new \RuntimeException('receipt');
            $owner = $d['client_id'] === null ? null : Database::one('SELECT user_id FROM clients WHERE id = ?', [$d['client_id']]);
            if ($owner !== null && $owner['user_id'] !== null) {
                Notifier::send([(int) $owner['user_id']], 'receipt.issued', 'Payment receipt ' . $number, 'Your receipt for ₹' . FieldRules::formatMinor((int) $d['paid_minor']) . ' is ready.', '/my/receipt/' . $r['public_id'], null, 'receipt', $id, 'rcpt:' . $id . ':issued');
            }

            return $r;
        });
    }

    /** @param array<string,mixed> $receipt */
    public static function void(int $actorId, array $receipt, string $reason): void
    {
        Gate::authorize('receipts.void', $actorId);
        $reason = (string) FieldRules::line($reason, 'Reason', 10, 255);
        Database::transaction(function () use ($actorId, $receipt, $reason): void {
            $r = Database::one('SELECT * FROM receipts WHERE id = ? FOR UPDATE', [$receipt['id']]) ?? throw new HttpException(404);
            if ($r['status'] === 'void') {
                throw new ValidationException(['This receipt is already void.']);
            }
            Database::run("UPDATE receipts SET status = 'void', void_reason = ?, voided_by = ?, voided_at = UTC_TIMESTAMP() WHERE id = ?", [$reason, $actorId, $r['id']]);
            Audit::record('receipt.voided', 'receipt', (int) $r['id'], ['status' => 'valid'], ['status' => 'void'], ['number' => $r['number'], 'reason_recorded' => true], $actorId);
        });
    }

    // ------------------------------------------------------------------------------------------------ reading

    /** @return array<string,mixed>|null */
    public static function find(string $publicId): ?array
    {
        return preg_match('/^[a-f0-9]{32}$/', $publicId) ? Database::one('SELECT * FROM receipts WHERE public_id = ?', [$publicId]) : null;
    }

    /** @return array<string,mixed>|null */
    public static function forPayment(int $paymentId): ?array
    {
        return Database::one('SELECT * FROM receipts WHERE external_key = ?', [hash('sha256', 'rcpt:client_payment:' . $paymentId)]);
    }

    /** @return array<string,mixed> */
    public static function requireStaff(int $userId, string $publicId): array
    {
        return (Gate::can('receipts.view_all', $userId) ? self::find($publicId) : null) ?? throw new HttpException(404);
    }

    /** @return array<string,mixed> the receipt, only when it is the signed-in client's own */
    public static function requireOwn(int $userId, string $publicId): array
    {
        $r = Gate::can('receipts.view_own', $userId) ? self::find($publicId) : null;
        $c = $r === null || $r['client_id'] === null ? null : Database::one('SELECT user_id FROM clients WHERE id = ?', [$r['client_id']]);
        if ($r === null || $c === null || (int) $c['user_id'] !== $userId) {
            throw new HttpException(404);
        }

        return $r;
    }

    /** @return list<array<string,mixed>> */
    public static function forClient(int $userId): array
    {
        return Database::all('SELECT r.* FROM receipts r JOIN clients c ON c.id = r.client_id WHERE c.user_id = ? ORDER BY r.id DESC LIMIT 200', [$userId]);
    }

    /** @return list<array<string,mixed>> */
    public static function recent(int $limit = 200): array
    {
        return Database::all('SELECT * FROM receipts ORDER BY id DESC LIMIT ' . max(1, min(500, $limit)));
    }

    /**
     * The receipt as the template shows it. effective: valid | void | reversed (the payment behind it was reversed) | refunded (part of it was returned).
     *
     * @param array<string,mixed> $r
     * @return array<string,mixed>
     */
    public static function present(array $r): array
    {
        $zone = AppTime::zone();
        $paid = (new \DateTimeImmutable((string) $r['paid_at'], new \DateTimeZone('UTC')))->setTimezone($zone);
        $issued = (new \DateTimeImmutable((string) $r['issued_at'], new \DateTimeZone('UTC')))->setTimezone($zone);
        $effective = (string) $r['status'];
        $refunded = 0;
        if ($effective === 'valid' && $r['source'] === 'client_payment' && $r['source_id'] !== null) {
            $p = Database::one('SELECT status, refunded_minor FROM client_payments WHERE id = ?', [$r['source_id']]);
            if ($p !== null && $p['status'] === 'reversed') {
                $effective = 'reversed';
            } elseif ($p !== null && (int) $p['refunded_minor'] > 0) {
                $effective = 'refunded';
                $refunded = (int) $p['refunded_minor'];
            }
        }

        return [
            'number' => (string) $r['number'], 'code' => (string) $r['verify_code'], 'client' => (array) json_decode((string) $r['client_snapshot'], true), 'lines' => (array) json_decode((string) $r['lines_json'], true),
            'method' => (string) $r['method'], 'methodLabel' => self::METHODS[$r['method']] ?? ucwords(str_replace('_', ' ', (string) $r['method'])), 'txn' => (string) $r['txn_id'],
            'paidDate' => $paid->format('d M Y'), 'paidTime' => (int) $r['time_known'] === 1 ? $paid->format('H:i') : 'Not recorded', 'issuedDate' => $issued->format('d M Y'), 'issuedTime' => $issued->format('H:i'),
            'subtotal' => (int) $r['subtotal_minor'], 'discount' => (int) $r['discount_minor'], 'tax' => (int) $r['tax_minor'], 'paidMinor' => (int) $r['paid_minor'], 'against' => $r['against'], 'note' => $r['note'],
            'words' => self::words((int) $r['paid_minor']), 'effective' => $effective, 'refunded' => $refunded, 'status' => (string) $r['status'], 'voidReason' => $r['void_reason'], 'manual' => $r['source'] === 'manual',
            'verifyUrl' => self::verifyUrl((string) $r['verify_code']), 'sealOk' => self::sealMatches($r), 'public_id' => (string) $r['public_id'], 'source' => (string) $r['source'],
        ];
    }

    // ------------------------------------------------------------------------------------------------ public verification

    public static function verifyUrl(string $code): string
    {
        return rtrim((string) Config::get('app.url'), '/') . '/verify/' . $code;
    }

    /** @return array<string,mixed>|null the receipt for a verification code (any case, dashes and spaces ignored) */
    public static function byCode(string $code): ?array
    {
        $c = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $code));

        return preg_match('/^[A-Z2-9]{12}$/', $c) ? Database::one('SELECT * FROM receipts WHERE verify_code = ?', [$c]) : null;
    }

    /** The receipt number together with its code (the manual form). @return array<string,mixed>|null */
    public static function byNumberAndCode(string $number, string $code): ?array
    {
        $r = self::byCode($code);

        return $r !== null && strcasecmp(trim($number), (string) $r['number']) === 0 ? $r : null;
    }

    /** True when the stored facts still hash to the seal made at issue. @param array<string,mixed> $r */
    public static function sealMatches(array $r): bool
    {
        $client = (array) json_decode((string) $r['client_snapshot'], true);

        return hash_equals((string) $r['seal'], self::seal([
            'number' => (string) $r['number'], 'verify_code' => (string) $r['verify_code'], 'paid_at' => (string) $r['paid_at'], 'txn_id' => (string) $r['txn_id'], 'method' => (string) $r['method'],
            'paid_minor' => (int) $r['paid_minor'], 'client_name' => (string) ($client['name'] ?? ''),
        ]));
    }

    /** @param array<string,mixed> $f */
    private static function seal(array $f): string
    {
        $raw = (string) Config::get('app.key', '');
        $key = str_starts_with($raw, 'base64:') ? (string) base64_decode(substr($raw, 7), true) : $raw;
        $key = hash_hkdf('sha256', $key !== '' ? $key : 'vertex-receipt-seal-unkeyed', 32, 'receipt-seal');

        return hash_hmac('sha256', implode('|', [$f['number'], $f['verify_code'], $f['paid_at'], mb_strtolower((string) $f['txn_id']), $f['method'], (int) $f['paid_minor'], mb_strtolower((string) $f['client_name'])]), $key);
    }

    /** Shows "Pune Shop" as "P*** S***" for the public page. */
    public static function maskName(string $name): string
    {
        $out = [];
        foreach (preg_split('/\s+/', trim($name)) ?: [] as $w) {
            $out[] = mb_substr($w, 0, 1) . str_repeat('*', max(2, min(5, mb_strlen($w) - 1)));
        }

        return implode(' ', $out);
    }

    // ------------------------------------------------------------------------------------------------ helpers

    /** @return array<string,string> */
    private static function clientSnapshot(int $clientId): array
    {
        $c = Database::one('SELECT * FROM clients WHERE id = ?', [$clientId]) ?? throw new HttpException(404);
        return ['client_ref' => 'CL-' . strtoupper(substr((string) $c['public_id'], 0, 8)), 'name' => (string) (($c['company_name'] ?? '') !== '' ? $c['company_name'] : $c['contact_name']), 'email' => (string) ($c['email'] ?? ''), 'phone' => (string) ($c['phone'] ?? ''), 'address' => trim((string) ($c['address'] ?? ''))];
    }

    private static function utcFromLocalDate(string $ymd): string
    {
        return (new \DateTimeImmutable($ymd . ' 00:00:00', AppTime::zone()))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /** 12 characters, no 0/O/1/I/L lookalikes: easy to read out or type from paper. */
    private static function newCode(): string
    {
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
        do {
            $c = '';
            for ($i = 0; $i < 12; $i++) {
                $c .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
        } while (Database::one('SELECT 1 AS x FROM receipts WHERE verify_code = ?', [$c]) !== null);

        return $c;
    }

    /** "Rupees Five Thousand Only", "Rupees One Lakh Twenty Thousand and Fifty Paise Only" (Indian numbering). */
    public static function words(int $minor): string
    {
        $rupees = intdiv($minor, 100);
        $paise = $minor % 100;
        $out = 'Rupees ' . ($rupees === 0 ? 'Zero' : self::indian($rupees));
        if ($paise > 0) {
            $out .= ' and ' . self::below100($paise) . ' Paise';
        }

        return $out . ' Only';
    }

    private static function below100(int $n): string
    {
        static $ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
        static $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];

        return $n < 20 ? $ones[$n] : trim($tens[intdiv($n, 10)] . ' ' . $ones[$n % 10]);
    }

    private static function indian(int $n): string
    {
        $parts = [];
        foreach ([10000000 => 'Crore', 100000 => 'Lakh', 1000 => 'Thousand'] as $unit => $label) {
            if ($n >= $unit) {
                $q = intdiv($n, $unit);
                $parts[] = ($unit === 10000000 && $q >= 100 ? self::indian($q) : self::below100($q)) . ' ' . $label;
                $n %= $unit;
            }
        }
        if ($n >= 100) {
            $parts[] = self::below100(intdiv($n, 100)) . ' Hundred';
            $n %= 100;
        }
        if ($n > 0) {
            $parts[] = self::below100($n);
        }

        return implode(' ', $parts);
    }
}
