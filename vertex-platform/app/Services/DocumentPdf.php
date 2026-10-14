<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Database;

/** Builds the downloadable PDFs (module 36): quotation, invoice, payment receipt, credit note. Same facts as the print views; business details come from the snapshot frozen at issue. */
final class DocumentPdf
{
    private const L = Pdf::MARGIN;
    private const R = Pdf::W - Pdf::MARGIN;

    /** @param array<string,mixed> $biz */
    private static function head(Pdf $pdf, string $title, string $ref, array $biz, string $badge = ''): void
    {
        $y = Pdf::H - Pdf::MARGIN;
        $pdf->text(self::L, $y - 14, $title, 20, true);
        $pdf->text(self::L, $y - 32, $ref, 11, false, 'left', 0.35);
        if ($badge !== '') {
            $pdf->text(self::L, $y - 48, $badge, 12, true, 'left', 0.55);
        }
        $name = (string) ($biz['trade_name'] ?? '');
        $pdf->text(self::R, $y - 14, $name, 12, true, 'right');
        $line = 28.0;
        foreach (array_filter([(string) ($biz['legal_name'] ?? ''), ...preg_split('/\R/', (string) ($biz['address'] ?? '')) ?: [], (string) ($biz['email'] ?? ''), (string) ($biz['phone'] ?? ''), (string) ($biz['website'] ?? '')]) as $l) {
            $pdf->text(self::R, $y - $line, trim($l), 9, false, 'right', 0.3);
            $line += 12;
        }
        $pdf->moveDown(max(70.0, $line + 6) - 0.0);
        $pdf->rule(self::L, self::R, $pdf->y() - 2);
        $pdf->moveDown(10);
    }

    /** @param list<array{0:string,1:string}> $pairs */
    private static function meta(Pdf $pdf, array $pairs): void
    {
        $x = self::L;
        $colW = (self::R - self::L) / max(1, count($pairs));
        $y = $pdf->y();
        foreach ($pairs as [$k, $v]) {
            $pdf->text($x, $y - 12, $k, 8, false, 'left', 0.45);
            foreach (Pdf::wrap($v, $colW - 10, 10, true) as $n => $line) {
                $pdf->text($x, $y - 25 - $n * 12, $line, 10, true);
            }
            $x += $colW;
        }
        $pdf->moveDown(48);
    }

    /** @param list<array<string,mixed>> $items */
    private static function lines(Pdf $pdf, array $items): void
    {
        $pdf->ensure(40);
        $y = $pdf->y();
        $pdf->fill(self::L, $y - 16, self::R - self::L, 16, 0.93);
        $pdf->text(self::L + 4, $y - 12, 'Description', 9, true);
        $pdf->text(self::R - 200, $y - 12, 'Qty', 9, true, 'right');
        $pdf->text(self::R - 110, $y - 12, 'Unit price', 9, true, 'right');
        $pdf->text(self::R - 4, $y - 12, 'Amount', 9, true, 'right');
        $pdf->moveDown(18);
        foreach ($items as $it) {
            $desc = Pdf::wrap((string) $it['description'], self::R - self::L - 250, 10);
            $pdf->ensure(14 * count($desc) + 6);
            $y = $pdf->y();
            foreach ($desc as $n => $l) {
                $pdf->text(self::L + 4, $y - 12 - $n * 12, $l, 10);
            }
            $pdf->text(self::R - 200, $y - 12, rtrim(rtrim(number_format(((int) $it['quantity_x100']) / 100, 2, '.', ''), '0'), '.'), 10, false, 'right');
            $pdf->text(self::R - 110, $y - 12, FieldRules::formatMinor((int) $it['unit_minor']), 10, false, 'right');
            $pdf->text(self::R - 4, $y - 12, FieldRules::formatMinor((int) $it['total_minor']), 10, false, 'right');
            $h = 14 * count($desc) + 2;
            $pdf->rule(self::L, self::R, $y - $h, 0.3, 0.85);
            $pdf->moveDown($h + 2);
        }
    }

    /** @param list<array{0:string,1:string,2?:bool}> $rows label, amount, bold */
    private static function totals(Pdf $pdf, array $rows): void
    {
        $pdf->ensure(18 * count($rows) + 10);
        $pdf->moveDown(4);
        foreach ($rows as $r) {
            $bold = $r[2] ?? false;
            $y = $pdf->y();
            $pdf->text(self::R - 110, $y - 12, $r[0], $bold ? 11 : 10, $bold, 'right');
            $pdf->text(self::R - 4, $y - 12, $r[1], $bold ? 11 : 10, $bold, 'right');
            $pdf->moveDown(16);
        }
    }

    /** @param array<string,mixed> $biz */
    private static function foot(Pdf $pdf, array $biz, bool $howToPay = false): void
    {
        $pdf->moveDown(6);
        if ($howToPay && !empty($biz['payment_instructions'])) {
            $pdf->paragraph('How to pay', 10, true);
            $pdf->paragraph((string) $biz['payment_instructions'], 9);
            $pdf->moveDown(4);
        }
        $pdf->paragraph((string) ($biz['tax_statement'] ?? 'Tax is not applicable.'), 9, false, null, self::L, 1.4, 0.3);
        if (!empty($biz['invoice_footer'])) {
            $pdf->paragraph((string) $biz['invoice_footer'], 8, false, null, self::L, 1.4, 0.45);
        }
    }

    /** @return array<string,mixed> */
    private static function biz(?string $snapshot): array
    {
        return $snapshot ? (array) json_decode($snapshot, true) : BusinessProfile::get();
    }

    /** @param array<string,mixed> $i an invoice row from InvoiceService::find */
    public static function invoice(array $i): string
    {
        $biz = self::biz($i['business_snapshot'] ?? null);
        $client = Database::one('SELECT contact_name, company_name, email FROM clients WHERE id = ?', [$i['client_id']]) ?? [];
        $bal = InvoiceService::balance($i);
        $pdf = new Pdf('Invoice ' . ($i['reference'] ?? ''));
        self::head($pdf, $i['status'] === 'draft' ? 'DRAFT INVOICE' : 'INVOICE', (string) ($i['reference'] ?? ''), $biz, $i['status'] === 'void' ? 'VOID' : '');
        self::meta($pdf, [['BILLED TO', (string) ($client['company_name'] ?: ($client['contact_name'] ?? ''))], ['ISSUE DATE', $i['issue_date'] ? local_date($i['issue_date']) : '-'], ['DUE DATE', $i['due_date'] ? local_date($i['due_date']) : '-'], ['STATUS', ucwords(str_replace('_', ' ', (string) $i['status']))]]);
        $pdf->paragraph((string) $i['title'], 12, true);
        $pdf->moveDown(4);
        self::lines($pdf, InvoiceService::items($i));
        $rows = [['Subtotal', FieldRules::formatMinor((int) $i['subtotal_minor'])]];
        if ((int) $i['discount_minor'] > 0) {
            $rows[] = ['Discount', '-' . FieldRules::formatMinor((int) $i['discount_minor'])];
        }
        if ((int) ($i['coupon_discount_minor'] ?? 0) > 0) {
            $rows[] = ['Coupon ' . $i['coupon_code'], '-' . FieldRules::formatMinor((int) $i['coupon_discount_minor'])];
        }
        $rows[] = ['Total', FieldRules::formatMinor((int) $i['total_minor']), true];
        if ((int) ($i['credited_minor'] ?? 0) > 0) {
            $rows[] = ['Credit notes', '-' . FieldRules::formatMinor((int) $i['credited_minor'])];
        }
        if ($i['status'] !== 'draft') {
            $rows[] = ['Paid', FieldRules::formatMinor($bal['paid'])];
            $rows[] = ['Balance due', FieldRules::formatMinor($bal['outstanding']), true];
        }
        self::totals($pdf, $rows);
        if (!empty($i['client_notes'])) {
            $pdf->moveDown(4);
            $pdf->paragraph((string) $i['client_notes'], 9);
        }
        self::foot($pdf, $biz, true);

        return $pdf->output();
    }

    /** @param array<string,mixed> $q a quotation row from QuotationService::find */
    public static function quotation(array $q): string
    {
        $biz = BusinessProfile::get();
        $client = Database::one('SELECT contact_name, company_name FROM clients WHERE id = ?', [$q['client_id']]) ?? [];
        $pdf = new Pdf('Quotation ' . $q['reference']);
        self::head($pdf, 'QUOTATION', (string) $q['reference'], $biz);
        self::meta($pdf, [['PREPARED FOR', (string) ($client['company_name'] ?: ($client['contact_name'] ?? ''))], ['VALID UNTIL', $q['valid_until'] ? local_date($q['valid_until']) : '-'], ['STATUS', ucfirst((string) $q['status'])]]);
        $pdf->paragraph((string) $q['title'], 12, true);
        $pdf->moveDown(4);
        self::lines($pdf, QuotationService::items($q));
        self::totals($pdf, [['Total', FieldRules::formatMinor((int) $q['total_minor']), true]]);
        if (!empty($q['client_notes'])) {
            $pdf->paragraph((string) $q['client_notes'], 9);
        }
        self::foot($pdf, $biz);

        return $pdf->output();
    }

    /** @param array<string,mixed> $p a client_payments row, $i its invoice (or null) */
    public static function receipt(array $p, ?array $i): string
    {
        $biz = self::biz($i['business_snapshot'] ?? null);
        $client = Database::one('SELECT contact_name, company_name FROM clients WHERE id = ?', [$p['client_id']]) ?? [];
        $pdf = new Pdf('Receipt ' . $p['reference']);
        self::head($pdf, 'PAYMENT RECEIPT', (string) $p['reference'], $biz, $p['status'] === 'reversed' ? 'REVERSED' : '');
        self::meta($pdf, [['RECEIVED FROM', (string) ($client['company_name'] ?: ($client['contact_name'] ?? ''))], ['RECEIVED ON', local_date($p['received_on'])], ['METHOD', ucwords(str_replace('_', ' ', (string) $p['method']))], ['AGAINST', $i !== null ? (string) $i['reference'] : (string) ($p['invoice_ref'] ?? '-')]]);
        $net = max(0, (int) $p['gross_minor'] - (int) $p['refunded_minor']);
        $rows = [['Amount received', FieldRules::formatMinor((int) $p['gross_minor']), true]];
        if ((int) $p['refunded_minor'] > 0) {
            $rows[] = ['Refunded', '-' . FieldRules::formatMinor((int) $p['refunded_minor'])];
            $rows[] = ['Net received', FieldRules::formatMinor($net), true];
        }
        self::totals($pdf, $rows);
        if ($i !== null) {
            $bal = InvoiceService::balance($i);
            $pdf->paragraph('Invoice ' . $i['reference'] . ' total Rs. ' . FieldRules::formatMinor((int) $i['total_minor'] - (int) ($i['credited_minor'] ?? 0)) . '; paid so far Rs. ' . FieldRules::formatMinor($bal['paid']) . '; balance Rs. ' . FieldRules::formatMinor($bal['outstanding']) . '.', 9);
        }
        $pdf->paragraph('Reference: ' . (string) $p['external_ref'], 9, false, null, self::L, 1.4, 0.4);
        self::foot($pdf, $biz);

        return $pdf->output();
    }

    /** @param array<string,mixed> $n a row from CreditNoteService::find */
    public static function creditNote(array $n): string
    {
        $biz = self::biz($n['business_snapshot'] ?? null);
        $pdf = new Pdf('Credit note ' . $n['reference']);
        self::head($pdf, 'CREDIT NOTE', (string) $n['reference'], $biz, $n['status'] === 'void' ? 'VOID' : '');
        self::meta($pdf, [['CREDITED TO', (string) $n['client_name']], ['ISSUED', local_date($n['issued_at'])], ['AGAINST INVOICE', (string) $n['invoice_reference']]]);
        $pdf->paragraph('Reason: ' . $n['reason'], 10);
        self::totals($pdf, [['Credit amount', FieldRules::formatMinor((int) $n['amount_minor']), true]]);
        $pdf->paragraph('This credit note reduces the amount still owed on the invoice above. It is not a refund.', 9, false, null, self::L, 1.4, 0.4);
        self::foot($pdf, $biz);

        return $pdf->output();
    }
}
