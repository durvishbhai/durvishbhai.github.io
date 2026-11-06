<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Config;

/**
 * The "Project Requirements" document in the KIS look: client information, project summary, the features and modules table, the user roles table and a note on
 * what the document is. It records what the client asked for; it is not a quotation, price or agreement (those come from the quotation and the signed KIS agreement).
 */
final class RequirementsPdf
{
    /**
     * @param array<string,mixed> $in the posted form (same names as EnquiryExtras::requirements)
     * @param array{name:string,email:string,phone:string,company:string} $client
     */
    public static function fromForm(array $in, array $client, string $reference = ''): string
    {
        $line = static fn (mixed $v, int $max = 200): string => mb_substr(trim(preg_replace('/[\x00-\x1F<>]+/u', ' ', is_string($v) ? $v : '') ?? ''), 0, $max);
        $st = EnquiryExtras::structure($in);
        $services = (array) EnquiryExtras::serviceChoices() + (array) Config::get('workflow.services');
        $names = [];
        foreach ((array) ($in['services'] ?? []) as $s) {
            if (is_string($s) && isset($services[$s])) {
                $names[] = (string) $services[$s];
            }
        }
        $outcome = $line($in['expected_outcome'] ?? '');
        $note = $line($in['outcome_note'] ?? '', 1500);
        if ($outcome !== '' && $note !== '') {
            $outcome .= ' (' . $note . ')';
        }
        $budgets = (array) Config::get('workflow.budget_ranges');
        $cat = (string) ($in['project_category'] ?? '');
        $tl = (string) ($in['timeline'] ?? '');

        return self::render([
            'project' => $line($in['project_name'] ?? '', 160) ?: 'Project requirements', 'reference' => $reference,
            'client' => $client, 'type' => EnquiryExtras::CATEGORIES[$cat] ?? '', 'services' => implode(', ', $names), 'outcome' => $outcome,
            'budget' => (string) ($budgets[(string) ($in['budget_range'] ?? '')] ?? ''), 'timeline' => EnquiryExtras::TIMELINES[$tl] ?? '', 'tech' => $line($in['preferred_tech'] ?? ''),
            'priority' => $st['priority'], 'problem' => $line($in['problem_statement'] ?? '', 3000), 'features' => $st['features'], 'roles' => $st['roles'], 'user_count' => $st['user_count'],
            'extra_features' => $line($in['features'] ?? '', 3000), 'notes' => $line($in['notes'] ?? '', 2000),
        ]);
    }

    /** From a stored enquiry (staff download). @param array<string,mixed> $e the enquiry row @param array<string,mixed> $details EnquiryExtras::details() */
    public static function fromEnquiry(array $e, array $details): string
    {
        $s = (array) ($details['_structured'] ?? []);

        return self::render([
            'project' => (string) ($details['Project name'] ?? $e['reference']), 'reference' => (string) $e['reference'],
            'client' => ['name' => (string) $e['contact_name'], 'email' => (string) $e['email'], 'phone' => (string) ($e['phone'] ?? ''), 'company' => (string) ($e['company_name'] ?? '')],
            'type' => (string) ($details['Project category'] ?? ''), 'services' => (string) ($details['Required services'] ?? ''), 'outcome' => (string) ($details['Expected outcome'] ?? ''),
            'budget' => (string) ($e['budget_range'] ?? ''), 'timeline' => (string) ($details['Desired timeline'] ?? ''), 'tech' => (string) ($details['Preferred technology'] ?? ''),
            'priority' => (string) ($s['priority'] ?? ''), 'problem' => (string) ($details['Problem statement'] ?? ''), 'features' => (array) ($s['features'] ?? []), 'roles' => (array) ($s['roles'] ?? []),
            'user_count' => (string) ($s['user_count'] ?? ''), 'extra_features' => (string) ($s['extra_features'] ?? ''), 'notes' => (string) ($details['Additional notes'] ?? ''),
        ]);
    }

    /** @param array<string,mixed> $d */
    private static function render(array $d): string
    {
        $biz = BusinessProfile::get();
        $pdf = new Pdf('Project requirements: ' . $d['project']);
        $L = PdfKit::L;
        $R = PdfKit::R;
        PdfKit::header($pdf);
        $pdf->text($L, $pdf->y() - 8, 'PROJECT REQUIREMENTS DOCUMENT', 8.5, false, 'left', PdfKit::GRAY);
        $pdf->text($R, $pdf->y() - 8, local_date(AppTime::today()), 8.5, false, 'right', PdfKit::GRAY);
        $pdf->moveDown(30);
        foreach (array_slice(Pdf::wrap((string) $d['project'], $R - $L, 20, true), 0, 3) as $l) {
            $pdf->text(($L + $R) / 2, $pdf->y() - 14, $l, 20, true, 'center', PdfKit::NAVY);
            $pdf->moveDown(26);
        }
        $pdf->text(($L + $R) / 2, $pdf->y() - 6, 'Project requirements and enquiry', 11, false, 'center', PdfKit::GRAY);
        $pdf->moveDown(18);
        if ($d['reference'] !== '') {
            $pdf->text(($L + $R) / 2, $pdf->y() - 6, 'Ref: ' . $d['reference'], 10, true, 'center', PdfKit::GRAY);
            $pdf->moveDown(16);
        }

        $c = (array) $d['client'];
        PdfKit::section($pdf, 'Client information');
        PdfKit::pairs($pdf, [['Client Name', (string) $c['name']], ['Email', (string) $c['email']], ['Phone / WhatsApp', (string) $c['phone']], ['Company', (string) $c['company']]]);

        PdfKit::section($pdf, 'Project summary');
        PdfKit::pairs($pdf, [['Project Type', (string) $d['type']], ['Expected Outcome', (string) $d['outcome']], ['Services', (string) $d['services']], ['Budget Range', (string) $d['budget']],
            ['Delivery Timeline', (string) $d['timeline']], ['Preferred Tech', (string) $d['tech']], ['Feature Priority', (string) $d['priority']], ['Expected Users', (string) $d['user_count']]]);
        if ($d['problem'] !== '') {
            $pdf->paragraph('Problem statement', 10, true, null, $L, 1.5, 0.1);
            $pdf->paragraph((string) $d['problem'], 10);
            $pdf->moveDown(4);
        }

        $features = (array) $d['features'];
        PdfKit::section($pdf, 'Features and modules', (string) count($features));
        if ($features === []) {
            $pdf->paragraph('No features were selected.', 10, false, null, $L, 1.4, 0.4);
        } else {
            PdfKit::table($pdf, [34.0, $R - $L - 34 - 90, 90.0], ['#', 'Feature / Module', 'Type'], array_map(static fn (array $f, int $i): array => [(string) ($i + 1), (string) $f['name'], (string) $f['type']], $features, array_keys($features)), ['center', 'left', 'left']);
        }
        if ($d['extra_features'] !== '') {
            $pdf->paragraph('Other feature notes', 10, true, null, $L, 1.5, 0.1);
            $pdf->paragraph((string) $d['extra_features'], 10);
        }

        $roles = (array) $d['roles'];
        if ($roles !== []) {
            PdfKit::section($pdf, 'User roles and permissions', (string) count($roles));
            PdfKit::table($pdf, [34.0, 120.0, 105.0, $R - $L - 259], ['#', 'Role', 'Access Level', 'Permissions'], array_map(static fn (array $r, int $i): array => [(string) ($i + 1), (string) $r['name'], (string) $r['access'], (string) $r['permissions']], $roles, array_keys($roles)), ['center', 'left', 'left', 'left']);
        }
        if ($d['notes'] !== '') {
            PdfKit::section($pdf, 'Additional notes');
            $pdf->paragraph((string) $d['notes'], 10);
        }

        $mail = trim((string) ($biz['email'] ?? ''));
        PdfKit::notice($pdf, 'IMPORTANT: Please share any images, logos or design files with our team together with this document' . ($mail !== '' ? ' (' . $mail . ')' : '') . '.', [0.80, 0.15, 0.20]);
        $pdf->moveDown(4);
        $pdf->paragraph('This document records what you asked for. It is not a quotation or an agreement. The agreed scope, price and terms are set in the quotation and in the signed Krazy Integrated Solutions agreement.', 8.5, false, null, $L, 1.4, 0.42);

        return $pdf->output();
    }
}
