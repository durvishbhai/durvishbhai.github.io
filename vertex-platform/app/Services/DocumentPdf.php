<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Config;
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

    /** @param array<string,mixed> $c a clients row (contact_name, company_name) @return array{0:string,1:string} the name to print and a smaller second line */
    private static function issuedTo(array $c): array
    {
        $company = trim((string) ($c['company_name'] ?? ''));
        $contact = trim((string) ($c['contact_name'] ?? ''));

        return $company !== '' ? [$company, $contact] : [$contact, ''];
    }

    /** @param array<string,mixed> $i an invoice row from InvoiceService::find */
    public static function invoice(array $i): string
    {
        $biz = self::biz($i['business_snapshot'] ?? null);
        $client = Database::one('SELECT contact_name, company_name, email FROM clients WHERE id = ?', [$i['client_id']]) ?? [];
        $bal = InvoiceService::balance($i);
        [$to, $sub] = self::issuedTo($client);
        $discount = (int) $i['discount_minor'] + (int) ($i['coupon_discount_minor'] ?? 0);
        $after = [];
        if ((int) ($i['credited_minor'] ?? 0) > 0) {
            $after[] = ['Credit notes', '-Rs. ' . FieldRules::formatMinor((int) $i['credited_minor'])];
        }
        if ($i['status'] !== 'draft') {
            $after[] = ['Paid', 'Rs. ' . FieldRules::formatMinor($bal['paid'])];
            $after[] = ['Balance due', 'Rs. ' . FieldRules::formatMinor($bal['outstanding']), true];
        }
        $extra = $i['due_date'] ? [['DUE DATE:', local_date($i['due_date'], 'j M Y')]] : [];

        return self::template([
            'title' => 'INVOICE', 'numberLabel' => 'INVOICE NO:', 'number' => (string) ($i['reference'] ?? 'DRAFT'), 'date' => $i['issue_date'] ? local_date($i['issue_date'], 'j M Y') : '-', 'extra' => $extra,
            'issuedTo' => $to, 'issuedToSub' => $sub, 'subject' => (string) $i['title'], 'badge' => $i['status'] === 'void' ? 'VOID' : ($i['status'] === 'draft' ? 'DRAFT' : ''),
            'items' => InvoiceService::items($i), 'subtotal' => (int) $i['subtotal_minor'], 'discount' => $discount, 'discountLabel' => (int) ($i['coupon_discount_minor'] ?? 0) > 0 && (int) $i['discount_minor'] === 0 ? 'Coupon ' . $i['coupon_code'] : 'Discount',
            'total' => (int) $i['total_minor'], 'after' => $after, 'terms' => BusinessProfile::terms('invoice', $biz), 'notes' => trim((string) ($i['client_notes'] ?? '')), 'howToPay' => trim((string) ($biz['payment_instructions'] ?? '')), 'footerNote' => '', 'docName' => 'invoice',
        ]);
    }

    /** @param array<string,mixed> $q a quotation row from QuotationService::find */
    public static function quotation(array $q): string
    {
        $biz = BusinessProfile::get();
        $client = Database::one('SELECT contact_name, company_name FROM clients WHERE id = ?', [$q['client_id']]) ?? [];
        [$to, $sub] = self::issuedTo($client);
        $items = QuotationService::items($q);
        $subtotal = array_sum(array_map(static fn (array $it): int => (int) $it['total_minor'], $items));
        $total = (int) $q['total_minor'];
        $stamp = (string) (($q['sent_at'] ?? '') !== '' ? $q['sent_at'] : $q['created_at']);

        return self::template([
            'title' => 'QUOTATION', 'numberLabel' => 'QUOTATION NO:', 'number' => (string) $q['reference'], 'date' => local_date($stamp, 'j M Y'), 'extra' => $q['valid_until'] ? [['VALID UNTIL:', local_date($q['valid_until'], 'j M Y')]] : [],
            'issuedTo' => $to, 'issuedToSub' => $sub, 'subject' => (string) $q['title'], 'badge' => in_array($q['status'], ['draft', 'rejected', 'expired'], true) ? strtoupper((string) $q['status']) : '',
            'items' => $items, 'subtotal' => $subtotal, 'discount' => max(0, $subtotal - $total), 'discountLabel' => 'Discount', 'total' => $total, 'after' => [],
            'terms' => BusinessProfile::terms('quotation', $biz), 'notes' => trim((string) ($q['client_notes'] ?? '')), 'howToPay' => '', 'footerNote' => '', 'docName' => 'quotation',
        ]);
    }

    /** The square KIS logo of the quotation / invoice template (black tile with the KIS mark). @return array{w:int,h:int,data:string}|null */
    public static function docLogo(): ?array
    {
        $public = defined('VERTEX_PUBLIC') ? VERTEX_PUBLIC : VERTEX_ROOT . '/public';
        $png = is_file($public . '/assets/img/krazy-doc-logo.png') ? (string) file_get_contents($public . '/assets/img/krazy-doc-logo.png') : '';
        if ($png === '' && is_file($public . '/assets/img.php') && preg_match("/'krazy-doc-logo\\.png' => '([A-Za-z0-9+\\/=]+)'/", (string) file_get_contents($public . '/assets/img.php'), $m)) {
            $png = (string) base64_decode($m[1], true);
        }

        return $png === '' ? null : Pdf::imageFromPng($png, [0, 0, 0]);
    }

    /**
     * Quotation / invoice in the KIS template: letterhead swoosh and blue tab, logo block, big title, ISSUED TO and number/date, a numbered item table with
     * PRICE, VALIDITY/QTY and SUBTOTAL, Subtotal / Discount / GRAND TOTAL, the Terms & conditions, and the blue footer bar. Same facts as the on-screen documents.
     *
     * @param array{title:string,numberLabel:string,number:string,date:string,extra:list<array{0:string,1:string}>,issuedTo:string,issuedToSub:string,subject:string,badge:string,
     *   items:list<array<string,mixed>>,subtotal:int,discount:int,discountLabel:string,total:int,after:list<array{0:string,1:string,2?:bool}>,terms:list<string>,
     *   notes:string,howToPay:string,footerNote:string,docName:string} $d
     */
    private static function template(array $d): string
    {
        $H = Pdf::H;
        $W = Pdf::W;
        $blue = [0.251, 0.498, 0.749];
        $tab = [0.353, 0.502, 0.816];
        $ink = [0.04, 0.05, 0.10];
        $grey = 0.38;
        $decor = static function (Pdf $p) use ($H, $W, $tab, $d): void {
            // the letterhead artwork was drawn on a 200 dpi grid: 1 px = 0.36 pt
            $y = static fn (float $py): float => $H - $py * 0.36;
            $x = static fn (float $px): float => $px * 0.36;
            $p->shape([['M', $x(48), $y(128)], ['C', $x(52), $y(70), $x(80), $y(25), $x(125), $y(0)], ['L', $x(548), $y(0)], ['C', $x(470), $y(30), $x(330), $y(55), $x(215), $y(75)], ['C', $x(140), $y(90), $x(80), $y(105), $x(48), $y(128)], ['Z']], 0.0,
                ['from' => [0.157, 0.122, 0.49], 'to' => [0.353, 0.502, 0.816], 'x0' => $x(48), 'x1' => $x(548)]);
            $p->shape([['M', $x(50), $y(148)], ['C', $x(95), $y(105), $x(160), $y(88), $x(235), $y(78)], ['C', $x(160), $y(98), $x(100), $y(140), $x(56), $y(212)], ['Z']], 0.0,
                ['from' => [0.157, 0.122, 0.49], 'to' => [0.30, 0.40, 0.72], 'x0' => $x(50), 'x1' => $x(235)]);
            $p->tab(327, $H - 20, $W - 327, 20, 14, $tab);
            $p->fill(0, 0, $W, 26, $tab);
            $p->text($W / 2, 9.5, 'This is a computer-generated ' . $d['docName'] . ' and does not require a physical signature.', 8.5, false, 'center', [0.08, 0.10, 0.22]);
        };
        $pdf = new Pdf($d['title'] . ' ' . $d['number'], $decor, false);
        $T = static fn (float $top): float => $H - $top; // top-based millimetre-free helper: points from the top edge

        // logo block and name
        if (($img = self::docLogo()) !== null) {
            $pdf->image($img, 34, $T(151), 86, 86);
        } else {
            $pdf->fill(34, $T(151), 86, 86, [0, 0, 0]);
        }
        foreach (['Krazy', 'Integrated', 'Solutions'] as $n => $word) {
            $pdf->text(125, $T(93 + $n * 24), $word, 24, true, 'left', $ink);
        }
        // title (right) and optional badge
        $pdf->text(587, $T(125), $d['title'], 44, true, 'right', $blue);
        if ($d['badge'] !== '') {
            $pdf->text(587, $T(148), $d['badge'], 13, true, 'right', [0.80, 0.15, 0.20]);
        }
        // issued to / number / date
        $pdf->text(38, $T(181), 'ISSUED TO:', 14, true, 'left', $blue);
        $pdf->text(340, $T(181), $d['numberLabel'], 14, true, 'left', $blue);
        $pdf->text(340 + Pdf::width($d['numberLabel'], 14, true) + 6, $T(181), $d['number'], 10.5, true, 'left', $ink);
        $pdf->text(420, $T(207), 'DATE:', 11, false, 'left', $ink);
        $pdf->text(572, $T(207), $d['date'], 10.5, false, 'right', $ink);
        $ey = 224.0;
        foreach ($d['extra'] as [$k, $v]) {
            $pdf->text(420, $T($ey), $k, 11, false, 'left', $ink);
            $pdf->text(572, $T($ey), $v, 10.5, false, 'right', $ink);
            $ey += 16;
        }
        $iy = 199.0;
        foreach (Pdf::wrap($d['issuedTo'], 270, 11, true) as $line) {
            $pdf->text(38, $T($iy), $line, 11, true, 'left', $ink);
            $iy += 13;
        }
        if ($d['issuedToSub'] !== '') {
            $pdf->text(38, $T($iy), $d['issuedToSub'], 9, false, 'left', $grey);
            $iy += 12;
        }
        foreach (array_slice(Pdf::wrap($d['subject'], 280, 9), 0, 2) as $line) {
            $pdf->text(38, $T($iy), $line, 9, false, 'left', $grey);
            $iy += 11;
        }

        // table header
        $head = static function (Pdf $p, float $top) use ($T, $blue): void {
            $p->text(50, $T($top + 16), 'NO.', 12, true, 'left', $blue);
            $p->text(92, $T($top + 16), 'ITEM DESCRIPTION', 12, true, 'left', $blue);
            $p->text(350, $T($top + 16), 'PRICE', 12, true, 'right', $blue);
            $p->text(361, $T($top + 16), 'VALIDITY/QTY', 12, true, 'left', $blue);
            $p->text(547, $T($top + 16), 'SUBTOTAL', 12, true, 'right', $blue);
            $p->rule(34, 561, $T($top + 29), 2.0, 0.0);
        };
        $top = 256.0;
        $head($pdf, $top);
        $top += 29;
        $qty = static fn (array $it): string => rtrim(rtrim(number_format(((int) $it['quantity_x100']) / 100, 2, '.', ''), '0'), '.');
        $items = $d['items'];
        while (count($items) < 4) {
            $items[] = null; // the template always shows four numbered lines
        }
        foreach ($items as $n => $it) {
            $lines = $it === null ? [''] : Pdf::wrap((string) $it['description'], 168, 9.5);
            $h = max(37.0, count($lines) * 12.0 + 16.0);
            if ($top + $h > 760 && $it !== null) { // keep room for the totals and the terms; continue on a new page
                $pdf->newPage();
                $top = 110.0;
                $head($pdf, $top - 29);
            }
            $base = $top + ($h - count($lines) * 12.0) / 2 + 9.5;
            $pdf->text(53, $T($base), str_pad((string) ($n + 1), 2, '0', STR_PAD_LEFT) . '.', 11, false, 'left', $ink);
            if ($it !== null) {
                foreach ($lines as $k => $line) {
                    $pdf->text(92, $T($base + $k * 12), $line, 9.5, false, 'left', $ink);
                }
                $pdf->text(350, $T($base), 'Rs. ' . FieldRules::formatMinor((int) $it['unit_minor']), 9.5, false, 'right', $ink);
                $pdf->text(411, $T($base), $qty($it), 9.5, false, 'center', $ink);
                $pdf->text(547, $T($base), 'Rs. ' . FieldRules::formatMinor((int) $it['total_minor']), 9.5, false, 'right', $ink);
            }
            $pdf->rule(35, 561, $T($top + $h), 0.6, 0.15);
            $top += $h;
        }

        // totals
        $need = 85 + 20 * count($d['after']);
        if ($top + $need > 780) {
            $pdf->newPage();
            $top = 110.0;
        }
        $row = static function (Pdf $p, float $t, string $label, string $value) use ($T, $ink): void {
            $p->text(398, $T($t), $label, 11, false, 'center', $ink);
            $p->text(547, $T($t), $value, 10.5, false, 'right', $ink);
        };
        $row($pdf, $top + 19, 'Subtotal', 'Rs. ' . FieldRules::formatMinor($d['subtotal']));
        $row($pdf, $top + 40, $d['discountLabel'], ($d['discount'] > 0 ? '-' : '') . 'Rs. ' . FieldRules::formatMinor($d['discount']));
        $pdf->rule(24, 552, $T($top + 52), 2.0, 0.0);
        $pdf->text(325, $T($top + 77), 'GRAND TOTAL', 14, true, 'left', $blue);
        $pdf->text(547, $T($top + 77), 'Rs. ' . FieldRules::formatMinor($d['total']), 14, true, 'right', $blue);
        $y = $top + 77;
        foreach ($d['after'] as $rowAfter) {
            [$label, $value] = $rowAfter;
            $bold = $rowAfter[2] ?? false;
            $y += 20;
            $pdf->text(325, $T($y), $label, 11, (bool) $bold, 'left', (bool) $bold ? $blue : $ink);
            $pdf->text(547, $T($y), $value, 11, (bool) $bold, 'right', (bool) $bold ? $blue : $ink);
        }

        // terms (a line that would run into the footer bar moves to a new page)
        $room = static function (float $y, float $need) use ($pdf): float {
            if ($y + $need > 800) {
                $pdf->newPage();

                return 110.0;
            }

            return $y;
        };
        $y = $room($y + 42, 34);
        $termsTop = $y;
        $pdf->text(19, $T($y), 'Terms & conditions', 14, true, 'left', $blue);
        $y += 16;
        foreach ($d['terms'] as $term) {
            foreach (Pdf::wrap($term, 372, 8.5) as $line) {
                $y = $room($y, 12);
                $pdf->text(19, $T($y), $line, 8.5, false, 'left', $ink);
                $y += 12.2;
            }
            $y += 3.4;
        }
        // payment instructions and notes sit in the free column beside the terms (on the first page of the terms)
        $ry = $termsTop;
        foreach ([['How to pay', $d['howToPay']], ['Notes', $d['notes']]] as [$label, $text]) {
            if ($text === '') {
                continue;
            }
            $pdf->text(405, $T($ry), $label, 11, true, 'left', $blue);
            $ry += 14;
            foreach (preg_split('/\R/', $text) ?: [] as $para) {
                foreach (Pdf::wrap($para, 168, 8.5) as $line) {
                    if ($ry > 806) {
                        break 3;
                    }
                    $pdf->text(405, $T($ry), $line, 8.5, false, 'left', $ink);
                    $ry += 11.5;
                }
            }
            $ry += 10;
        }

        return $pdf->output();
    }

    /** The brand logo for the PDF, flattened onto the dark header colour (null when it cannot be read or GD is missing). @return array{w:int,h:int,data:string}|null */
    public static function logo(): ?array
    {
        $public = defined('VERTEX_PUBLIC') ? VERTEX_PUBLIC : VERTEX_ROOT . '/public';
        $png = is_file($public . '/assets/img/krazy-logo.png') ? (string) file_get_contents($public . '/assets/img/krazy-logo.png') : '';
        if ($png === '' && is_file($public . '/assets/img.php') && preg_match("/'krazy-logo\\.png' => '([A-Za-z0-9+\\/=]+)'/", (string) file_get_contents($public . '/assets/img.php'), $m)) {
            $png = (string) base64_decode($m[1], true);
        }

        return $png === '' ? null : Pdf::imageFromPng($png, [5, 13, 31]);
    }

    /**
     * The payment receipt in the KIS template: dark brand header, client and payment boxes, one line per item, totals, amount in words, acknowledgement,
     * the verification QR with its code, and the contact footer. Same facts as the on-screen receipt.
     *
     * @param array<string,mixed> $v from ReceiptService::present()
     */
    public static function receiptDocument(array $v): string
    {
        $biz = BusinessProfile::get();
        $navy = [0.02, 0.05, 0.12];
        $purple = [0.45, 0.20, 0.93];
        $blue = [0.18, 0.48, 1.0];
        $panel = [0.945, 0.96, 0.985];
        $line = [0.86, 0.88, 0.92];
        $gray = 0.45;
        $pdf = new Pdf('Payment receipt ' . $v['number']);
        $H = Pdf::H;
        $L = 45.0;
        $R = Pdf::W - 45.0;
        $name = strtoupper((string) (($biz['trade_name'] ?? '') !== '' ? $biz['trade_name'] : 'Krazy Integrated Solutions'));

        // header
        $pdf->fill($L, $H - 136, $R - $L, 92, $navy);
        if (($img = self::logo()) !== null) {
            $pdf->image($img, $L + 10, $H - 130, 86, 86 * $img['h'] / $img['w']);
        }
        $pdf->text($L + 130, $H - 82, $name, 15, true, 'left', [1.0, 1.0, 1.0]);
        $pdf->text($L + 130, $H - 101, 'Your Ideas. Our Integrated Solutions.', 12, true, 'left', [0.55, 0.88, 0.97]);

        // title and number
        $pdf->text($L, $H - 178, 'PAYMENT ', 25, true, 'left', $navy);
        $pdf->text($L + Pdf::width('PAYMENT ', 25, true) + 2, $H - 178, 'RECEIPT', 25, true, 'left', $purple);
        $pdf->text($R - 180, $H - 160, 'RECEIPT NUMBER', 8.5, false, 'left', $gray);
        $pdf->text($R - 180, $H - 174, (string) $v['number'], 10.5, true, 'left', $navy);
        $pdf->text($R - 180, $H - 188, 'Date: ' . $v['issuedDate'] . '    Time: ' . $v['issuedTime'], 8.5, false, 'left', $gray);
        $pdf->text($L, $H - 200, $v['effective'] === 'valid' ? 'System-generated acknowledgement of payment.' : ($v['manual'] ? 'Acknowledgement of payment issued by the KIS team.' : 'System-generated acknowledgement of payment.'), 8.5, false, 'left', $gray);
        if ($v['effective'] !== 'valid') {
            $badge = ['void' => 'VOID', 'reversed' => 'PAYMENT REVERSED', 'refunded' => 'PART REFUNDED'][$v['effective']] ?? strtoupper((string) $v['effective']);
            $pdf->text($R, $H - 200, $badge, 11, true, 'right', [0.80, 0.15, 0.20]);
        }

        // two boxes
        $boxTop = $H - 220;
        $boxW = ($R - $L - 14) / 2;
        $rows = static function (float $x, string $title, array $items) use ($pdf, $boxTop, $boxW, $panel, $line, $navy, $gray): void {
            $pdf->fill($x, $boxTop - 22, $boxW, 22, $panel);
            $pdf->text($x + 8, $boxTop - 15, $title, 8.5, true, 'left', $navy);
            $y = $boxTop - 22;
            foreach ($items as [$k, $val]) {
                $y -= 21;
                $pdf->rule($x, $x + $boxW, $y + 21, 0.5, 0.88);
                $pdf->text($x + 8, $y + 7, $k . ':', 8.5, false, 'left', $gray);
                $label = Pdf::width($k . ':', 8.5) + 14;
                $val = Pdf::wrap((string) $val, $boxW - $label - 8, 8.5, true)[0] ?? '';
                $pdf->text($x + $label, $y + 7, $val, 8.5, true, 'left', $navy);
            }
            $pdf->box($x, $y, $boxW, $boxTop - $y, $line);
        };
        $c = (array) $v['client'];
        $rows($L, 'CLIENT DETAILS', [['Client ID', $c['client_ref'] ?? '-'], ['Client Name', $c['name'] ?? ''], ['Email', ($c['email'] ?? '') !== '' ? $c['email'] : '-'], ['Phone', ($c['phone'] ?? '') !== '' ? $c['phone'] : '-'], ['Address', ($c['address'] ?? '') !== '' ? $c['address'] : '-']]);
        $rows($L + $boxW + 14, 'PAYMENT DETAILS', [['Method', $v['methodLabel']], ['Transaction ID', $v['txn']], ['Payment Date', $v['paidDate']], ['Payment Time', $v['paidTime']], ['Status', ['valid' => 'SUCCESSFUL', 'void' => 'VOID', 'reversed' => 'REVERSED', 'refunded' => 'SUCCESSFUL (PART REFUNDED)'][$v['effective']] ?? 'SUCCESSFUL']]);

        // items table
        $y = $boxTop - 22 - 5 * 21 - 20;
        $pdf->fill($L, $y - 22, $R - $L, 22, $navy);
        $pdf->text($L + 10, $y - 15, '#', 8.5, true, 'left', [1.0, 1.0, 1.0]);
        $pdf->text($L + 44, $y - 15, 'DESCRIPTION', 8.5, true, 'left', [1.0, 1.0, 1.0]);
        $pdf->text($R - 10, $y - 15, 'AMOUNT (Rs.)', 8.5, true, 'right', [1.0, 1.0, 1.0]);
        $y -= 22;
        foreach ((array) $v['lines'] as $n => $ln) {
            $wrapped = Pdf::wrap((string) $ln['description'], $R - $L - 44 - 120, 9, true);
            $h = max(22.0, count($wrapped) * 11 + 11);
            $pdf->box($L, $y - $h, $R - $L, $h, $line);
            $pdf->text($L + 10, $y - 15, (string) ($n + 1), 9, false, 'left', $navy);
            foreach ($wrapped as $k => $wl) {
                $pdf->text($L + 44, $y - 15 - $k * 12, $wl, 9, true, 'left', $navy);
            }
            $pdf->text($R - 10, $y - 15, 'Rs. ' . FieldRules::formatMinor((int) $ln['amount_minor']), 9, false, 'right', $navy);
            $y -= $h;
        }

        // totals
        $y -= 10;
        $tx = $R - 190;
        foreach ([['Subtotal', (int) $v['subtotal']], ['Discount', (int) $v['discount']], ['Tax', (int) $v['tax']], ['Payment Received', (int) $v['paidMinor']]] as [$k, $amt]) {
            $pdf->box($tx, $y - 21, 190, 21, $line);
            $pdf->text($tx + 10, $y - 14, $k, 8.5, false, 'left', $navy);
            $pdf->text($R - 10, $y - 14, 'Rs. ' . FieldRules::formatMinor($amt), 8.5, false, 'right', $navy);
            $y -= 21;
        }
        $pdf->fill($tx, $y - 24, 190, 24, $blue);
        $pdf->text($tx + 10, $y - 16, 'TOTAL PAID', 9, true, 'left', [1.0, 1.0, 1.0]);
        $pdf->text($R - 10, $y - 16, 'Rs. ' . FieldRules::formatMinor((int) $v['paidMinor']), 9, true, 'right', [1.0, 1.0, 1.0]);
        $y -= 24;
        if ((int) $v['refunded'] > 0) {
            $pdf->text($R - 10, $y - 14, 'Refunded since: Rs. ' . FieldRules::formatMinor((int) $v['refunded']), 8.5, true, 'right', [0.80, 0.15, 0.20]);
            $y -= 16;
        }

        // words, against, note
        $y -= 18;
        $pdf->text($L + 4, $y, 'AMOUNT IN WORDS', 8.5, true, 'left', $navy);
        $pdf->text($L + 4, $y - 14, (string) $v['words'], 9, false, 'left', $navy);
        $y -= 24;
        foreach (array_filter([$v['against'] ? 'Against: ' . $v['against'] : '', $v['note'] ? 'Note: ' . $v['note'] : '']) as $extra) {
            foreach (Pdf::wrap((string) $extra, $R - $L - 8, 8.5) as $wl) {
                $pdf->text($L + 4, $y, $wl, 8.5, false, 'left', $gray);
                $y -= 12;
            }
        }

        // acknowledgement
        $y -= 4;
        $pdf->fill($L, $y - 54, $R - $L, 54, [0.93, 0.97, 1.0]);
        $pdf->fill($L, $y - 54, 3, 54, [0.1, 0.78, 0.9]);
        $pdf->text($L + 14, $y - 16, 'Payment acknowledgement', 9, true, 'left', $navy);
        $pdf->text($L + 14, $y - 30, 'This payment has been received and recorded in our system.', 8.5, false, 'left', $navy);
        $pdf->text($L + 14, $y - 43, $v['manual'] ? 'This receipt was issued by the KIS team. It does not require a signature.' : 'This system-generated receipt does not require a signature.', 8.5, false, 'left', $gray);
        $y -= 54;

        // thank you + verification
        $y -= 30;
        $pdf->text($L, $y, 'Thank You!', 19, true, 'left', $blue);
        $pdf->text($L, $y - 20, 'for choosing ' . ($name === 'KRAZY INTEGRATED SOLUTIONS' ? 'KRAZY Integrated Solutions' : $name) . '.', 9, true, 'left', $navy);
        $pdf->text($L + 215, $y - 4, 'VERIFY THIS RECEIPT', 8.5, true, 'left', $navy);
        $urlLines = Pdf::wrap((string) $v['verifyUrl'], 190, 7.5);
        foreach ($urlLines as $k => $ul) {
            $pdf->text($L + 215, $y - 17 - $k * 10, $ul, 7.5, false, 'left', [0.1, 0.35, 0.85]);
        }
        $pdf->text($L + 215, $y - 20 - count($urlLines) * 10, 'Code: ' . implode('-', str_split((string) $v['code'], 4)), 8.5, true, 'left', $navy);
        $pdf->qr(QrCode::matrix((string) $v['verifyUrl']), $R - 66, $y - 46, 60, $navy);
        $pdf->text($R, $y - 58, $v['manual'] ? 'Issued by the KIS team' : 'System-Generated Receipt', 8.5, true, 'right', $navy);
        $pdf->text($R, $y - 69, 'No signature required.', 7.5, false, 'right', $gray);

        // footer bar
        $pdf->fill($L, 34, $R - $L, 28, $navy);
        $cells = array_values(array_filter([(string) ($biz['email'] ?? ''), (string) ($biz['phone'] ?? ''), (string) (($biz['website'] ?? '') !== '' ? $biz['website'] : (parse_url((string) Config::get('app.url'), PHP_URL_HOST) ?: ''))]));
        foreach ($cells as $k => $cell) {
            $pdf->text($L + 12 + $k * (($R - $L - 12) / max(1, count($cells))), 45, $cell, 8, false, 'left', [0.7, 0.75, 0.85]);
        }

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

    /** The agreement text plus its signature record. @param array<string,mixed> $a a row from AgreementService::find */
    public static function agreement(array $a): string
    {
        $biz = BusinessProfile::get();
        $pdf = new Pdf('Agreement ' . $a['reference']);
        self::head($pdf, 'AGREEMENT', (string) $a['reference'], $biz, $a['status'] === 'executed' ? 'EXECUTED' : strtoupper(str_replace('_', ' ', (string) $a['status'])));
        $pdf->paragraph((string) $a['title'], 13, true);
        $pdf->moveDown(6);
        foreach (preg_split('/\R{2,}/', (string) $a['body']) ?: [] as $para) {
            $pdf->paragraph(trim($para), 10);
            $pdf->moveDown(4);
        }
        $pdf->ensure(110);
        $pdf->moveDown(10);
        $pdf->rule(self::L, self::R, $pdf->y());
        $pdf->moveDown(4);
        $pdf->paragraph('Signatures', 11, true);
        foreach (AgreementService::signatures($a) as $s) {
            $pdf->paragraph(ucfirst((string) $s['party']) . ': ' . $s['signer_name'] . ' - signed ' . local_time($s['signed_at']) . ' (' . $s['method'] . ($s['ip'] ? ', from ' . $s['ip'] : '') . ')', 9);
        }
        $pdf->moveDown(4);
        $pdf->paragraph('Document fingerprint (SHA-256 of the exact text above): ' . (string) $a['body_sha256'], 8, false, null, self::L, 1.4, 0.4);

        return $pdf->output();
    }
}
