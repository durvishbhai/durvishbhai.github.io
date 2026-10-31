<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\ValidationException;

/**
 * The official Krazy Integrated Solutions "Website Development & Service Agreement" (the template the business uses on paper): eleven fixed clauses, with the
 * client, project values, payment dates and renewal costs filled in from the system. Whatever is not filled in stays as a blank line to complete by hand.
 * The clause wording is the business's own and is NOT editable here; changing it means changing this class on purpose.
 */
final class OfficialAgreement
{
    public const MAX_PROJECTS = 5;

    /**
     * What the form starts with: the saved values, otherwise the client and project the agreement belongs to.
     *
     * @param array<string,mixed> $a a row from AgreementService::find
     * @return array<string,mixed>
     */
    public static function details(array $a): array
    {
        $saved = $a['official_json'] ? (array) json_decode((string) $a['official_json'], true) : [];
        $client = \Vertex\Core\Database::one('SELECT contact_name, company_name, phone, email FROM clients WHERE id = ?', [$a['client_id']]) ?? [];
        $project = $a['project_id'] ? \Vertex\Core\Database::one('SELECT title, approved_amount_minor FROM projects WHERE id = ?', [$a['project_id']]) : null;
        $defaults = [
            'date' => AppTime::today(), 'client_name' => (string) ($client['contact_name'] ?? ''), 'business' => (string) ($client['company_name'] ?? ''), 'phone' => (string) ($client['phone'] ?? ''), 'email' => (string) ($client['email'] ?? ''),
            'projects' => $project === null ? [] : [['name' => (string) $project['title'], 'value' => $project['approved_amount_minor'] === null ? null : (int) $project['approved_amount_minor']]],
            'advance' => null, 'advance_date' => '', 'remaining' => null, 'due_date' => '',
            'renewal_year' => '', 'renewal_due' => '', 'domain_date' => '', 'hosting_date' => '', 'domain_cost' => null, 'hosting_cost' => null, 'place' => '',
        ];

        return $saved + $defaults;
    }

    /**
     * @param array<string,mixed> $in the posted form
     * @return array<string,mixed> the cleaned values (amounts in paise)
     */
    public static function clean(array $in): array
    {
        $errors = [];
        $try = static function (callable $fn) use (&$errors): mixed {
            try {
                return $fn();
            } catch (ValidationException $e) {
                array_push($errors, ...$e->errors);

                return null;
            }
        };
        $out = [
            'date' => $try(fn () => FieldRules::date($in['date'] ?? '', 'Agreement date')) ?? '',
            'client_name' => (string) $try(fn () => FieldRules::line($in['client_name'] ?? '', 'Client name', 2, 160, false)),
            'business' => (string) $try(fn () => FieldRules::line($in['business'] ?? '', 'Business name', 2, 160, false)),
            'phone' => (string) $try(fn () => FieldRules::line($in['phone'] ?? '', 'Contact number', 5, 40, false)),
            'email' => (string) $try(fn () => FieldRules::line($in['email'] ?? '', 'Email', 5, 190, false)),
            'advance' => $try(fn () => FieldRules::moneyMinor($in['advance'] ?? '', 'Advance amount')),
            'advance_date' => $try(fn () => FieldRules::date($in['advance_date'] ?? '', 'Advance payment date')) ?? '',
            'remaining' => $try(fn () => FieldRules::moneyMinor($in['remaining'] ?? '', 'Remaining amount')),
            'due_date' => $try(fn () => FieldRules::date($in['due_date'] ?? '', 'Full payment due date')) ?? '',
            'renewal_year' => (string) $try(fn () => FieldRules::line($in['renewal_year'] ?? '', 'Renewal year', 2, 20, false)),
            'renewal_due' => $try(fn () => FieldRules::date($in['renewal_due'] ?? '', 'Renewal payment due date')) ?? '',
            'domain_date' => $try(fn () => FieldRules::date($in['domain_date'] ?? '', 'Domain renewal date')) ?? '',
            'hosting_date' => $try(fn () => FieldRules::date($in['hosting_date'] ?? '', 'Hosting renewal date')) ?? '',
            'domain_cost' => $try(fn () => FieldRules::moneyMinor($in['domain_cost'] ?? '', 'Domain annual cost')),
            'hosting_cost' => $try(fn () => FieldRules::moneyMinor($in['hosting_cost'] ?? '', 'Hosting annual cost')),
            'place' => (string) $try(fn () => FieldRules::line($in['place'] ?? '', 'Place of signing', 2, 120, false)),
            'projects' => [],
        ];
        $names = (array) ($in['project_name'] ?? []);
        $values = (array) ($in['project_value'] ?? []);
        for ($i = 0; $i < self::MAX_PROJECTS; $i++) {
            $n = (string) $try(fn () => FieldRules::line($names[$i] ?? '', 'Project name', 2, 160, false));
            $v = $try(fn () => FieldRules::moneyMinor($values[$i] ?? '', 'Project value'));
            if ($n !== '' || $v !== null) {
                $out['projects'][] = ['name' => $n, 'value' => $v];
            }
        }
        if ($errors !== []) {
            throw new ValidationException(array_values(array_unique($errors)));
        }

        return $out;
    }

    public static function save(int $actorId, array $a, array $in): void
    {
        Gate::authorize('agreements.manage', $actorId);
        $d = self::clean($in);
        \Vertex\Core\Database::run('UPDATE agreements SET official_json = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?', [json_encode($d, JSON_UNESCAPED_UNICODE), $a['id']]);
        Audit::record('agreement.official_saved', 'agreement', (int) $a['id'], [], ['projects' => count($d['projects'])], [], $actorId);
    }

    // ------------------------------------------------------------------------------------------------ the document

    /** @param array<string,mixed> $a a row from AgreementService::find */
    public static function pdf(array $a): string
    {
        $d = self::details($a);
        $pdf = new Pdf('KIS Website Development and Service Agreement ' . $a['reference']);
        $L = PdfKit::L;
        $R = PdfKit::R;
        $W = $R - $L;
        $navy = PdfKit::NAVY;
        $blank = static fn (string $v, string $fallback = '____________'): string => trim($v) !== '' ? $v : $fallback;
        $date = static fn (string $v): string => $v !== '' ? local_date($v) : '____ / ____ / ______';
        $money = static fn (?int $m): string => $m === null ? 'Rs. ____________' : 'Rs. ' . FieldRules::formatMinor($m);
        $words = static fn (?int $m): string => $m === null || $m <= 0 ? '__________________________________________________' : ReceiptService::words($m);
        $para = static function (string $text, float $size = 9.5) use ($pdf): void {
            $pdf->paragraph($text, $size, false, null, PdfKit::L, 1.45);
            $pdf->moveDown(2);
        };
        $head = static function (string $t) use ($pdf, $L, $navy): void {
            $pdf->ensure(50);
            $pdf->moveDown(8);
            $pdf->text($L, $pdf->y() - 10, $t, 10.5, true, 'left', $navy);
            $pdf->moveDown(18);
        };
        $line = static function (string $label, string $value) use ($pdf, $L): void {
            $pdf->ensure(18);
            $pdf->text($L, $pdf->y() - 10, $label, 9.5, true);
            $pdf->text($L + Pdf::width($label, 9.5, true) + 6, $pdf->y() - 10, $value, 9.5);
            $pdf->moveDown(15);
        };

        PdfKit::header($pdf);
        $pdf->text(($L + $R) / 2, $pdf->y() - 10, 'WEBSITE DEVELOPMENT & SERVICE AGREEMENT', 14, true, 'center', $navy);
        $pdf->moveDown(28);
        $pdf->text($L, $pdf->y() - 4, 'Agreement Date: ' . $date((string) $d['date']), 9.5, true);
        $pdf->text($R, $pdf->y() - 4, 'Agreement Reference No.: ' . (string) $a['reference'], 9.5, true, 'right');
        $pdf->moveDown(18);
        $para('This Agreement is entered into between Krazy Integrated Solutions (KIS), represented by its Legal Owner, Leena Jawale, and Young Founder, Durvish Jawale (the "Service Provider"), and the client identified below (the "Client").');

        $head('1. CLIENT DETAILS');
        $line('Client / Authorized Person Name:', $blank((string) $d['client_name'], '______________________________________'));
        $line('Business / Organization Name:', $blank((string) $d['business'], '______________________________________'));
        $line('Contact Number:', $blank((string) $d['phone'], '________________________') . '     Email: ' . $blank((string) $d['email'], '______________________'));

        $head('2. PROJECT DETAILS & VALUE');
        $para('Use the table for one or multiple websites, products or projects covered by this agreement.');
        $pdf->moveDown(4);
        $projects = array_values((array) $d['projects']);
        $rows = [];
        $total = 0;
        $any = false;
        for ($i = 0; $i < self::MAX_PROJECTS; $i++) {
            $p = $projects[$i] ?? ['name' => '', 'value' => null];
            if ($p['value'] !== null) {
                $total += (int) $p['value'];
                $any = true;
            }
            $rows[] = [(string) ($i + 1), (string) $p['name'], $p['value'] === null ? '' : 'Rs. ' . FieldRules::formatMinor((int) $p['value'])];
        }
        $rows[] = ['', 'Total Project Value', $any ? 'Rs. ' . FieldRules::formatMinor($total) : ''];
        PdfKit::table($pdf, [50.0, $W - 50 - 130, 130.0], ['Sr. No.', 'Website / Project / Product Name', 'Project Value'], $rows, ['center', 'left', 'right'], '____________');
        $line('Total Amount in Words:', $any ? ReceiptService::words($total) : '__________________________________________________');
        $para('The approved printed quotation provided with this agreement describes the agreed scope, deliverables and pricing.');

        $head('3. PAYMENT TERMS');
        $line('Advance Amount:', $money($d['advance']) . '     Advance Payment Date: ' . $date((string) $d['advance_date']));
        $line('Remaining Amount:', $money($d['remaining']) . '     Full Payment Due Date: ' . $date((string) $d['due_date']));
        $para('The Client agrees to pay the remaining project amount upon signing this agreement, confirming completion of the agreed development work. Any mutually agreed pending work shall be recorded in writing. Payments already received will be adjusted against the total project value.');

        $head('4. BUG FIXING & TECHNICAL SUPPORT');
        $para('KIS will provide free bug fixing for six (6) months from the date of final project handover, limited to reproducible defects in agreed functionality developed by KIS.');
        $para('After six months, bug fixing and technical support may be chargeable at mutually agreed rates. New features, design changes, additional pages, changes to approved requirements, and issues caused by third-party services or unauthorized modifications are excluded unless otherwise agreed in writing.');

        $head('5. SOURCE CODE HANDOVER');
        $para('The Client may request the project source code after all outstanding project payments have been cleared, subject to applicable third-party software licences and contractual restrictions. Separately licensed software, paid plugins, third-party APIs and services remain subject to their respective licence terms.');

        $head('6. DOMAIN & HOSTING MANAGEMENT');
        $para('Domain registration, hosting and related technical management will be handled by KIS. Domain and hosting renewal charges are separate from the one-time development fee unless expressly included in the approved quotation. The Client agrees to pay renewal charges on time; delayed payment may result in service interruption or expiry under the provider\'s policies.');

        $head('7. ANNUAL DOMAIN & HOSTING RENEWAL');
        $line('Renewal Year:', $blank((string) $d['renewal_year'], '__________') . '     Payment Due On or Before: ' . $date((string) $d['renewal_due']));
        $line('Domain Renewal Date:', $date((string) $d['domain_date']) . '     Hosting Renewal Date: ' . $date((string) $d['hosting_date']));
        $dc = $d['domain_cost'];
        $hc = $d['hosting_cost'];
        $both = $dc !== null || $hc !== null;
        PdfKit::table($pdf, [$W - 130, 130.0], ['Particulars', 'Annual Cost'], [['Domain', $dc === null ? '' : 'Rs. ' . FieldRules::formatMinor((int) $dc)], ['Hosting', $hc === null ? '' : 'Rs. ' . FieldRules::formatMinor((int) $hc)], ['Total Annual Renewal Cost', $both ? 'Rs. ' . FieldRules::formatMinor((int) $dc + (int) $hc) : '']], ['left', 'right'], '____________');
        $line('Amount in Words:', $both ? ReceiptService::words((int) $dc + (int) $hc) : '__________________________________________________');
        $para('Renewal prices may change according to the domain registrar or hosting provider. Any revised amount will be communicated before payment is due.');

        $head('8. QUOTATION & DOCUMENTATION');
        $para('The approved printed quotation will be attached immediately after this agreement in the same physical file. KIS will retain the signed agreement and attached quotation in its official records. A scanned digital copy (e-copy) of the signed agreement together with the quotation will be shared with the Client. The quotation will serve as the reference for the agreed scope, deliverables, pricing and services.');

        $head('9. ADDITIONAL WORK & CHANGES');
        $para('Features, services, deliverables or changes outside the approved quotation may be charged separately. The additional work, cost and timeline must be communicated to and approved by the Client before work begins. Changes to this agreement must be mutually agreed upon in writing.');

        $head('10. CLIENT ACKNOWLEDGEMENT & ACCEPTANCE');
        $para('By signing, both parties confirm that they have reviewed the project scope and quotation, understand the project value and payment terms, understand the six-month bug-fixing conditions and recurring domain/hosting charges, and agree to the terms stated in this agreement.');

        $head('11. SIGNATURES');
        $pdf->ensure(170);
        $y = $pdf->y();
        $half = $W / 2;
        $pdf->text($L, $y - 12, 'FOR KRAZY INTEGRATED SOLUTIONS', 9.5, true, 'left', $navy);
        $pdf->text($L + $half, $y - 12, 'CLIENT ACCEPTANCE', 9.5, true, 'left', $navy);
        $pdf->text($L, $y - 30, 'Legal Owner: Leena Jawale', 9.5);
        $pdf->text($L, $y - 52, 'Signature: ______________________', 9.5);
        $pdf->text($L, $y - 72, 'Date: ____ / ____ / ______', 9.5);
        $pdf->text($L, $y - 98, 'YOUNG FOUNDER', 9.5, true, 'left', $navy);
        $pdf->text($L, $y - 116, 'Name: Durvish Jawale', 9.5);
        $pdf->text($L, $y - 138, 'Signature: ______________________', 9.5);
        $pdf->text($L, $y - 158, 'Date: ____ / ____ / ______', 9.5);
        $pdf->text($L + $half, $y - 30, 'Client Name: ' . $blank((string) $d['client_name'], '____________________'), 9.5);
        $pdf->text($L + $half, $y - 52, 'Business: ' . $blank((string) $d['business'], '_______________________'), 9.5);
        $pdf->text($L + $half, $y - 74, 'Signature: ______________________', 9.5);
        $pdf->text($L + $half, $y - 94, 'Date: ____ / ____ / ______', 9.5);
        $pdf->text($L + $half, $y - 120, 'PLACE OF SIGNING', 9.5, true, 'left', $navy);
        $pdf->text($L + $half, $y - 138, 'Place: ' . $blank((string) $d['place'], '__________________________'), 9.5);
        $pdf->moveDown(172);
        $pdf->paragraph('Prepared by Krazy Integrated Solutions. This is a general business template; consider legal review for regular use.', 8, false, null, $L, 1.4, 0.42);

        return $pdf->output();
    }
}
