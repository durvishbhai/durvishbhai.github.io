<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Database;
use Vertex\Core\HttpException;

/**
 * Modules 8, 9 and 29: reports with charts. Financial reports need `reports.financial`; operational ones need `reports.operational` AND are limited to the
 * records the user can already see (the same scope SQL as the module lists), so a manager's report never contains other people's projects.
 * Every report returns plain tables (also exported as CSV) and optional SVG charts that repeat the same numbers.
 */
final class ReportService
{
    /** @return array<string,array{title:string,about:string,perm:string}> */
    public static function catalog(): array
    {
        return [
            'revenue' => ['title' => 'Revenue and collections', 'about' => 'Money received (verified payments, refunds taken off) by month and by payment method, against what was invoiced.', 'perm' => 'reports.financial'],
            'aging' => ['title' => 'Outstanding invoices (ageing)', 'about' => 'What clients still owe, grouped by how late it is.', 'perm' => 'reports.financial'],
            'reconciliation' => ['title' => 'Payment reconciliation', 'about' => 'Payments by status and source, claims waiting to be checked against the bank, and invoices whose status does not match their verified payments.', 'perm' => 'reports.financial'],
            'commissions' => ['title' => 'Referral commissions', 'about' => 'Commission by status, earned per month and the largest partners.', 'perm' => 'reports.financial'],
            'coupons' => ['title' => 'Coupons and campaigns', 'about' => 'Redemptions, discounts given and invoiced value by coupon and campaign.', 'perm' => 'reports.financial'],
            'projects' => ['title' => 'Project status', 'about' => 'Projects by status and service, and how long finished projects took.', 'perm' => 'reports.operational'],
            'conversion' => ['title' => 'Leads and client conversion', 'about' => 'Enquiries by stage and source, and the share that became clients.', 'perm' => 'reports.operational'],
            'workload' => ['title' => 'Team workload', 'about' => 'Open tasks, overdue tasks and estimated effort per person.', 'perm' => 'reports.operational'],
            'overdue' => ['title' => 'Overdue tasks', 'about' => 'Every open task past its due date, oldest first.', 'perm' => 'reports.operational'],
            'renewals' => ['title' => 'Domains and hosting renewals', 'about' => 'Services by status and what expires in the coming months.', 'perm' => 'reports.operational'],
            'support' => ['title' => 'Support performance', 'about' => 'Tickets opened and resolved per month, by status, and how fast staff respond.', 'perm' => 'reports.operational'],
        ];
    }

    /** @return array<string,array{title:string,about:string,perm:string}> the reports this user may open */
    public static function available(int $userId): array
    {
        $out = [];
        foreach (self::catalog() as $k => $r) {
            if (Gate::can($r['perm'], $userId)) {
                $out[$k] = $r;
            }
        }

        return $out;
    }

    /** @return array{title:string,about:string,summary:array<string,string>,tables:list<array{title:string,columns:list<string>,rows:list<list<string>>}>,charts:list<array{title:string,svg:string}>,from:string,to:string} */
    public static function run(int $userId, string $key, ?string $from, ?string $to): array
    {
        $def = self::catalog()[$key] ?? throw new HttpException(404);
        Gate::authorize($def['perm'], $userId);
        $to = $to !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) ? $to : AppTime::today();
        $from = $from !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) ? $from : gmdate('Y-m-01', strtotime($to . ' -11 months'));
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        if ((strtotime($to) - strtotime($from)) > 3 * 366 * 86400) {
            $from = gmdate('Y-m-d', strtotime($to . ' -3 years'));
        }
        $r = match ($key) {
            'revenue' => self::revenue($from, $to),
            'aging' => self::aging(),
            'reconciliation' => self::reconciliation($from, $to),
            'commissions' => self::commissions($from, $to),
            'coupons' => self::coupons($from, $to),
            'projects' => self::projects($userId, $from, $to),
            'conversion' => self::conversion($userId, $from, $to),
            'workload' => self::workload($userId),
            'overdue' => self::overdue($userId),
            'renewals' => self::renewals($userId),
            'support' => self::support($userId, $from, $to),
        };

        return ['title' => $def['title'], 'about' => $def['about'], 'from' => $from, 'to' => $to] + $r + ['summary' => [], 'tables' => [], 'charts' => []];
    }

    /** Small charts for the dashboard: only the ones this user may see. @return list<array{title:string,svg:string,link:string}> */
    public static function dashboardCharts(int $userId): array
    {
        $out = [];
        $from = gmdate('Y-m-01', strtotime(AppTime::today() . ' -5 months'));
        foreach ([['revenue', 'Money received, last 6 months', 0], ['projects', 'Projects by status', 0], ['workload', 'Open tasks per person', 0], ['conversion', 'New enquiries, last 6 months', 0]] as [$key, $title, $i]) {
            if (!Gate::can(self::catalog()[$key]['perm'], $userId)) {
                continue;
            }
            try {
                $r = self::run($userId, $key, $from, AppTime::today());
                if (($r['charts'][$i]['svg'] ?? '') !== '') {
                    $out[] = ['title' => $title, 'svg' => $r['charts'][$i]['svg'], 'link' => '/reports/' . $key];
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return $out;
    }

    // ------------------------------------------------------------------------------------------------ helpers

    private static function inr(int $minor): string
    {
        return '₹' . FieldRules::formatMinor($minor);
    }

    /** @return list<string> 'YYYY-MM' from..to */
    private static function months(string $from, string $to): array
    {
        $out = [];
        $d = new \DateTimeImmutable(substr($from, 0, 7) . '-01');
        $end = new \DateTimeImmutable(substr($to, 0, 7) . '-01');
        while ($d <= $end && count($out) < 40) {
            $out[] = $d->format('Y-m');
            $d = $d->modify('+1 month');
        }

        return $out;
    }

    /** @param list<array<string,mixed>> $rows @return array<string,int> */
    private static function keyed(array $rows, string $k, string $v): array
    {
        $o = [];
        foreach ($rows as $r) {
            $o[(string) $r[$k]] = (int) $r[$v];
        }

        return $o;
    }

    private static function label(string $s): string
    {
        return ucwords(str_replace('_', ' ', $s));
    }

    // ------------------------------------------------------------------------------------------------ financial

    private static function revenue(string $from, string $to): array
    {
        $paid = self::keyed(Database::all("SELECT DATE_FORMAT(received_on, '%Y-%m') ym, SUM(GREATEST(0, gross_minor - refunded_minor)) amt FROM client_payments WHERE status IN ('verified','reversed') AND received_on BETWEEN ? AND ? GROUP BY ym", [$from, $to]), 'ym', 'amt');
        $invoiced = self::keyed(Database::all("SELECT DATE_FORMAT(issue_date, '%Y-%m') ym, SUM(total_minor - credited_minor) amt FROM invoices WHERE status <> 'draft' AND status <> 'void' AND issue_date BETWEEN ? AND ? GROUP BY ym", [$from, $to]), 'ym', 'amt');
        $months = self::months($from, $to);
        $rows = [];
        $vals = [];
        foreach ($months as $m) {
            $rows[] = [$m, self::inr($invoiced[$m] ?? 0), self::inr($paid[$m] ?? 0), self::inr(($paid[$m] ?? 0) - ($invoiced[$m] ?? 0))];
            $vals[] = $paid[$m] ?? 0;
        }
        $byMethod = Database::all("SELECT method, COUNT(*) n, SUM(GREATEST(0, gross_minor - refunded_minor)) amt FROM client_payments WHERE status IN ('verified','reversed') AND received_on BETWEEN ? AND ? GROUP BY method ORDER BY amt DESC", [$from, $to]);
        $outstanding = (int) Database::one("SELECT COALESCE(SUM(GREATEST(0, i.total_minor - i.credited_minor - (SELECT COALESCE(SUM(GREATEST(0, cp.gross_minor - cp.refunded_minor)), 0) FROM client_payments cp WHERE cp.invoice_id = i.id AND cp.status IN ('verified','reversed')))), 0) AS s FROM invoices i WHERE i.status IN ('issued','partially_paid')")['s'];

        return [
            'summary' => ['Received in period' => self::inr(array_sum($paid)), 'Invoiced in period' => self::inr(array_sum($invoiced)), 'Outstanding now' => self::inr($outstanding), 'Payments counted' => (string) array_sum(array_column($byMethod, 'n'))],
            'tables' => [
                ['title' => 'By month', 'columns' => ['Month', 'Invoiced', 'Received', 'Received minus invoiced'], 'rows' => $rows],
                ['title' => 'By payment method', 'columns' => ['Method', 'Payments', 'Received'], 'rows' => array_map(static fn (array $r): array => [self::label((string) $r['method']), (string) $r['n'], self::inr((int) $r['amt'])], $byMethod)],
            ],
            'charts' => [['title' => 'Money received per month', 'svg' => Chart::bars($months, $vals, 'Money received per month', 'inr')]],
        ];
    }

    private static function aging(): array
    {
        $rows = Database::all(
            "SELECT i.public_id, i.reference, i.title, i.due_date, i.total_minor - i.credited_minor AS payable, COALESCE(NULLIF(c.company_name, ''), c.contact_name) AS client,
                    (SELECT COALESCE(SUM(GREATEST(0, cp.gross_minor - cp.refunded_minor)), 0) FROM client_payments cp WHERE cp.invoice_id = i.id AND cp.status IN ('verified','reversed')) AS paid
               FROM invoices i JOIN clients c ON c.id = i.client_id WHERE i.status IN ('issued','partially_paid') ORDER BY i.due_date",
        );
        $buckets = ['Not due yet' => [0, 0], '1-30 days late' => [0, 0], '31-60 days late' => [0, 0], '61-90 days late' => [0, 0], 'Over 90 days late' => [0, 0]];
        $detail = [];
        $today = new \DateTimeImmutable(AppTime::today());
        foreach ($rows as $r) {
            $owed = max(0, (int) $r['payable'] - (int) $r['paid']);
            if ($owed === 0) {
                continue;
            }
            $late = $r['due_date'] === null ? 0 : (int) $today->diff(new \DateTimeImmutable((string) $r['due_date']))->format('%r%a') * -1;
            $b = $late <= 0 ? 'Not due yet' : ($late <= 30 ? '1-30 days late' : ($late <= 60 ? '31-60 days late' : ($late <= 90 ? '61-90 days late' : 'Over 90 days late')));
            $buckets[$b][0]++;
            $buckets[$b][1] += $owed;
            $detail[] = [(string) $r['reference'], (string) $r['client'], (string) $r['title'], $r['due_date'] ? local_date($r['due_date']) : '-', $late > 0 ? (string) $late : '-', self::inr($owed)];
        }
        $total = array_sum(array_column($buckets, 1));

        return [
            'summary' => ['Total outstanding' => self::inr($total), 'Invoices owing' => (string) array_sum(array_column($buckets, 0)), 'Overdue' => self::inr($total - $buckets['Not due yet'][1])],
            'tables' => [
                ['title' => 'By age', 'columns' => ['Age', 'Invoices', 'Owed'], 'rows' => array_map(static fn (string $k, array $v): array => [$k, (string) $v[0], self::inr($v[1])], array_keys($buckets), $buckets)],
                ['title' => 'Invoice by invoice', 'columns' => ['Invoice', 'Client', 'Title', 'Due', 'Days late', 'Owed'], 'rows' => $detail],
            ],
            'charts' => [['title' => 'Outstanding by age', 'svg' => Chart::hbars(array_keys($buckets), array_map(static fn (array $v): int => $v[1], array_values($buckets)), 'Outstanding by age', 'inr')]],
        ];
    }

    private static function reconciliation(string $from, string $to): array
    {
        $byStatus = Database::all('SELECT status, source, COUNT(*) n, SUM(gross_minor) amt FROM client_payments WHERE received_on BETWEEN ? AND ? GROUP BY status, source ORDER BY status, source', [$from, $to]);
        $pending = Database::all("SELECT cp.reference, COALESCE(NULLIF(c.company_name, ''), c.contact_name) AS client, cp.gross_minor, cp.method, cp.received_on, DATEDIFF(?, DATE(cp.created_at)) AS age FROM client_payments cp JOIN clients c ON c.id = cp.client_id WHERE cp.status = 'pending_verification' ORDER BY cp.created_at", [AppTime::today()]);
        $mismatch = Database::all(
            "SELECT i.reference, i.status, i.total_minor - i.credited_minor AS payable,
                    (SELECT COALESCE(SUM(GREATEST(0, cp.gross_minor - cp.refunded_minor)), 0) FROM client_payments cp WHERE cp.invoice_id = i.id AND cp.status IN ('verified','reversed')) AS paid
               FROM invoices i WHERE i.status IN ('issued','partially_paid','paid')
             HAVING (i.status = 'paid' AND paid < payable) OR (i.status = 'issued' AND paid > 0) OR (i.status = 'partially_paid' AND (paid = 0 OR paid >= payable))",
        );
        $statusTotals = [];
        foreach ($byStatus as $r) {
            $statusTotals[$r['status']] = ($statusTotals[$r['status']] ?? 0) + (int) $r['n'];
        }

        return [
            'summary' => ['Awaiting verification' => (string) count($pending), 'Oldest waiting (days)' => $pending === [] ? '0' : (string) max(array_column($pending, 'age')), 'Status mismatches' => (string) count($mismatch)],
            'tables' => [
                ['title' => 'Payments by status and source', 'columns' => ['Status', 'Source', 'Payments', 'Amount'], 'rows' => array_map(static fn (array $r): array => [self::label((string) $r['status']), self::label((string) $r['source']), (string) $r['n'], self::inr((int) $r['amt'])], $byStatus)],
                ['title' => 'Waiting for verification against the bank', 'columns' => ['Receipt', 'Client', 'Amount', 'Method', 'Received on', 'Days waiting'], 'rows' => array_map(static fn (array $r): array => [(string) $r['reference'], (string) $r['client'], self::inr((int) $r['gross_minor']), self::label((string) $r['method']), local_date($r['received_on']), (string) $r['age']], $pending)],
                ['title' => 'Invoices whose status does not match their verified payments (should be empty)', 'columns' => ['Invoice', 'Status', 'Payable', 'Verified paid'], 'rows' => array_map(static fn (array $r): array => [(string) $r['reference'], self::label((string) $r['status']), self::inr((int) $r['payable']), self::inr((int) $r['paid'])], $mismatch)],
            ],
            'charts' => $statusTotals === [] ? [] : [['title' => 'Payments by status', 'svg' => Chart::donut(array_map([self::class, 'label'], array_keys($statusTotals)), array_values($statusTotals), 'Payments by status')]],
        ];
    }

    private static function commissions(string $from, string $to): array
    {
        $byStatus = Database::all('SELECT status, COUNT(*) n, SUM(amount_minor) amt FROM commission_entries GROUP BY status ORDER BY amt DESC');
        $perMonth = self::keyed(Database::all("SELECT DATE_FORMAT(calculated_at, '%Y-%m') ym, SUM(amount_minor) amt FROM commission_entries WHERE status NOT IN ('reversed','adjusted') AND calculated_at BETWEEN ? AND ? GROUP BY ym", [$from . ' 00:00:00', $to . ' 23:59:59']), 'ym', 'amt');
        $partners = Database::all("SELECT p.reference, u.name, SUM(e.amount_minor) amt, SUM(e.status = 'paid') paid_n FROM commission_entries e JOIN referral_partners p ON p.id = e.partner_id JOIN users u ON u.id = p.user_id WHERE e.status NOT IN ('reversed','adjusted') GROUP BY p.id ORDER BY amt DESC LIMIT 10");
        $months = self::months($from, $to);

        return [
            'summary' => ['Commission entries' => (string) array_sum(array_column($byStatus, 'n')), 'Total in entries' => self::inr((int) array_sum(array_column($byStatus, 'amt')))],
            'tables' => [
                ['title' => 'By status', 'columns' => ['Status', 'Entries', 'Amount'], 'rows' => array_map(static fn (array $r): array => [self::label((string) $r['status']), (string) $r['n'], self::inr((int) $r['amt'])], $byStatus)],
                ['title' => 'Largest partners', 'columns' => ['Partner', 'Name', 'Commission'], 'rows' => array_map(static fn (array $r): array => [(string) $r['reference'], (string) $r['name'], self::inr((int) $r['amt'])], $partners)],
            ],
            'charts' => [['title' => 'Commission calculated per month', 'svg' => Chart::bars($months, array_map(static fn (string $m): int => $perMonth[$m] ?? 0, $months), 'Commission calculated per month', 'inr')]],
        ];
    }

    private static function coupons(string $from, string $to): array
    {
        $rows = Database::all(
            "SELECT c.code, g.name AS campaign, COUNT(r.id) n, COALESCE(SUM(r.discount_minor), 0) disc, COALESCE(SUM(i.total_minor - i.credited_minor), 0) invoiced
               FROM coupons c LEFT JOIN campaigns g ON g.id = c.campaign_id
               LEFT JOIN coupon_redemptions r ON r.coupon_id = c.id AND r.released_at IS NULL AND DATE(r.redeemed_at) BETWEEN ? AND ?
               LEFT JOIN invoices i ON i.id = r.invoice_id AND i.status <> 'void' GROUP BY c.id ORDER BY disc DESC, c.code",
            [$from, $to],
        );
        $camp = Database::all(
            "SELECT g.name, COUNT(r.id) n, COALESCE(SUM(r.discount_minor), 0) disc FROM campaigns g LEFT JOIN coupons c ON c.campaign_id = g.id
               LEFT JOIN coupon_redemptions r ON r.coupon_id = c.id AND r.released_at IS NULL AND DATE(r.redeemed_at) BETWEEN ? AND ? GROUP BY g.id ORDER BY disc DESC",
            [$from, $to],
        );
        $used = array_filter($rows, static fn (array $r): bool => (int) $r['n'] > 0);

        return [
            'summary' => ['Redemptions' => (string) array_sum(array_column($rows, 'n')), 'Discount given' => self::inr((int) array_sum(array_column($rows, 'disc'))), 'Invoiced with coupons' => self::inr((int) array_sum(array_column($rows, 'invoiced')))],
            'tables' => [
                ['title' => 'By coupon', 'columns' => ['Code', 'Campaign', 'Redemptions', 'Discount given', 'Invoiced value'], 'rows' => array_map(static fn (array $r): array => [(string) $r['code'], (string) ($r['campaign'] ?? '-'), (string) $r['n'], self::inr((int) $r['disc']), self::inr((int) $r['invoiced'])], $rows)],
                ['title' => 'By campaign', 'columns' => ['Campaign', 'Redemptions', 'Discount given'], 'rows' => array_map(static fn (array $r): array => [(string) $r['name'], (string) $r['n'], self::inr((int) $r['disc'])], $camp)],
            ],
            'charts' => $used === [] ? [] : [['title' => 'Discount given per coupon', 'svg' => Chart::hbars(array_map(static fn (array $r): string => (string) $r['code'], array_values($used)), array_map(static fn (array $r): int => (int) $r['disc'], array_values($used)), 'Discount given per coupon', 'inr')]],
        ];
    }

    // ------------------------------------------------------------------------------------------------ operational (scoped)

    /** @return array{0:string,1:list<mixed>} */
    private static function projectScope(int $userId): array
    {
        return ProjectPolicy::scopeSql($userId) ?? ['1=0', []];
    }

    private static function projects(int $userId, string $from, string $to): array
    {
        [$sc, $sp] = self::projectScope($userId);
        $from_ = "FROM projects p JOIN clients c ON c.id = p.client_id WHERE {$sc}";
        $byStatus = Database::all("SELECT p.status, COUNT(*) n {$from_} GROUP BY p.status ORDER BY n DESC", $sp);
        $bySvc = Database::all("SELECT COALESCE(p.service_category, 'other') svc, COUNT(*) n {$from_} GROUP BY svc ORDER BY n DESC", $sp);
        $done = Database::one("SELECT COUNT(*) n, AVG(DATEDIFF(p.completed_at, p.created_at)) days {$from_} AND p.completed_at BETWEEN ? AND ?", [...$sp, $from . ' 00:00:00', $to . ' 23:59:59']);
        $late = (int) Database::one("SELECT COUNT(*) n {$from_} AND p.target_date < ? AND p.status NOT IN ('completed','cancelled')", [...$sp, AppTime::today()])['n'];
        $services = (array) \Vertex\Core\Config::get('workflow.services');

        return [
            'summary' => ['Projects in view' => (string) array_sum(array_column($byStatus, 'n')), 'Finished in period' => (string) (int) $done['n'], 'Average days to finish' => $done['days'] === null ? '-' : (string) round((float) $done['days'], 1), 'Past target date' => (string) $late],
            'tables' => [
                ['title' => 'By status', 'columns' => ['Status', 'Projects'], 'rows' => array_map(static fn (array $r): array => [self::label((string) $r['status']), (string) $r['n']], $byStatus)],
                ['title' => 'By service', 'columns' => ['Service', 'Projects'], 'rows' => array_map(static fn (array $r): array => [(string) ($services[$r['svc']] ?? self::label((string) $r['svc'])), (string) $r['n']], $bySvc)],
            ],
            'charts' => $byStatus === [] ? [] : [['title' => 'Projects by status', 'svg' => Chart::donut(array_map(static fn (array $r): string => self::label((string) $r['status']), $byStatus), array_map(static fn (array $r): int => (int) $r['n'], $byStatus), 'Projects by status')]],
        ];
    }

    private static function conversion(int $userId, string $from, string $to): array
    {
        $scope = EnquiryPolicy::scopeSql($userId) ?? ['1=0', []];
        [$sc, $sp] = $scope;
        $base = "FROM enquiries e JOIN crm_stages s ON s.id = e.stage_id WHERE {$sc} AND e.created_at BETWEEN ? AND ?";
        $par = [...$sp, $from . ' 00:00:00', $to . ' 23:59:59'];
        $byStage = Database::all("SELECT s.name, s.kind, COUNT(*) n {$base} GROUP BY s.id ORDER BY s.position", $par);
        $bySource = Database::all("SELECT e.source, COUNT(*) n, SUM(s.kind = 'won') won, SUM(e.client_id IS NOT NULL) clients {$base} GROUP BY e.source ORDER BY n DESC", $par);
        $perMonth = self::keyed(Database::all("SELECT DATE_FORMAT(e.created_at, '%Y-%m') ym, COUNT(*) n {$base} GROUP BY ym", $par), 'ym', 'n');
        $total = array_sum(array_column($byStage, 'n'));
        $won = (int) array_sum(array_map(static fn (array $r): int => $r['kind'] === 'won' ? (int) $r['n'] : 0, $byStage));
        $clients = (int) array_sum(array_column($bySource, 'clients'));
        $months = self::months($from, $to);

        return [
            'summary' => ['New enquiries' => (string) $total, 'Won' => (string) $won, 'Became clients' => (string) $clients, 'Win rate' => $total > 0 ? round($won * 100 / $total, 1) . '%' : '-'],
            'tables' => [
                ['title' => 'By stage', 'columns' => ['Stage', 'Enquiries'], 'rows' => array_map(static fn (array $r): array => [(string) $r['name'], (string) $r['n']], $byStage)],
                ['title' => 'By source', 'columns' => ['Source', 'Enquiries', 'Won', 'Became clients'], 'rows' => array_map(static fn (array $r): array => [self::label((string) $r['source']), (string) $r['n'], (string) $r['won'], (string) $r['clients']], $bySource)],
            ],
            'charts' => [['title' => 'New enquiries per month', 'svg' => Chart::bars($months, array_map(static fn (string $m): int => $perMonth[$m] ?? 0, $months), 'New enquiries per month')]],
        ];
    }

    private static function workload(int $userId): array
    {
        [$sc, $sp] = self::projectScope($userId);
        $rows = Database::all(
            "SELECT COALESCE(u.name, 'Unassigned') who, SUM(t.status NOT IN ('completed','cancelled')) open_n, SUM(t.status NOT IN ('completed','cancelled') AND t.due_date < ?) late_n,
                    COALESCE(SUM(CASE WHEN t.status NOT IN ('completed','cancelled') THEN t.estimate_minutes END), 0) est
               FROM tasks t JOIN projects p ON p.id = t.project_id JOIN clients c ON c.id = p.client_id LEFT JOIN users u ON u.id = t.assignee_id
              WHERE t.archived_at IS NULL AND {$sc} GROUP BY u.id ORDER BY open_n DESC",
            [AppTime::today(), ...$sp],
        );
        $rows = array_values(array_filter($rows, static fn (array $r): bool => (int) $r['open_n'] > 0));

        return [
            'summary' => ['People with open work' => (string) count($rows), 'Open tasks' => (string) array_sum(array_column($rows, 'open_n')), 'Overdue' => (string) array_sum(array_column($rows, 'late_n'))],
            'tables' => [['title' => 'Per person', 'columns' => ['Person', 'Open tasks', 'Overdue', 'Estimated hours'], 'rows' => array_map(static fn (array $r): array => [(string) $r['who'], (string) $r['open_n'], (string) $r['late_n'], (string) round((int) $r['est'] / 60, 1)], $rows)]],
            'charts' => $rows === [] ? [] : [['title' => 'Open tasks per person', 'svg' => Chart::hbars(array_map(static fn (array $r): string => (string) $r['who'], $rows), array_map(static fn (array $r): int => (int) $r['open_n'], $rows), 'Open tasks per person')]],
        ];
    }

    private static function overdue(int $userId): array
    {
        [$sc, $sp] = self::projectScope($userId);
        $rows = Database::all(
            "SELECT t.title, t.due_date, t.priority, t.status, p.code, COALESCE(u.name, 'Unassigned') who, DATEDIFF(?, t.due_date) late
               FROM tasks t JOIN projects p ON p.id = t.project_id JOIN clients c ON c.id = p.client_id LEFT JOIN users u ON u.id = t.assignee_id
              WHERE t.archived_at IS NULL AND t.status NOT IN ('completed','cancelled') AND t.due_date < ? AND {$sc} ORDER BY t.due_date LIMIT 500",
            [AppTime::today(), AppTime::today(), ...$sp],
        );

        return [
            'summary' => ['Overdue tasks' => (string) count($rows)],
            'tables' => [['title' => 'Overdue tasks', 'columns' => ['Project', 'Task', 'Assignee', 'Priority', 'Status', 'Due', 'Days late'], 'rows' => array_map(static fn (array $r): array => [(string) $r['code'], (string) $r['title'], (string) $r['who'], self::label((string) $r['priority']), self::label((string) $r['status']), local_date($r['due_date']), (string) $r['late']], $rows)]],
        ];
    }

    private static function renewals(int $userId): array
    {
        if (!Gate::canAny(['renewals.view', 'renewals.manage'], $userId)) {
            throw new HttpException(403);
        }
        $byStatus = Database::all("SELECT status, COUNT(*) n FROM renewal_services WHERE status <> 'cancelled' GROUP BY status ORDER BY n DESC");
        $up = self::keyed(Database::all("SELECT DATE_FORMAT(expires_on, '%Y-%m') ym, COUNT(*) n FROM renewal_services WHERE status NOT IN ('cancelled','expired') AND expires_on >= ? GROUP BY ym", [AppTime::today()]), 'ym', 'n');
        $months = self::months(AppTime::today(), gmdate('Y-m-d', strtotime(AppTime::today() . ' +11 months')));
        $soon = Database::all("SELECT s.identifier, s.service_type, s.expires_on, s.status, COALESCE(NULLIF(c.company_name, ''), c.contact_name) client FROM renewal_services s JOIN clients c ON c.id = s.client_id WHERE s.status NOT IN ('cancelled','expired') AND s.expires_on BETWEEN ? AND ? ORDER BY s.expires_on LIMIT 200", [AppTime::today(), gmdate('Y-m-d', strtotime(AppTime::today() . ' +90 days'))]);

        return [
            'summary' => ['Services tracked' => (string) array_sum(array_column($byStatus, 'n')), 'Expiring in 90 days' => (string) count($soon)],
            'tables' => [
                ['title' => 'By status', 'columns' => ['Status', 'Services'], 'rows' => array_map(static fn (array $r): array => [RenewalService::label((string) $r['status']), (string) $r['n']], $byStatus)],
                ['title' => 'Expiring in the next 90 days', 'columns' => ['Service', 'Type', 'Client', 'Expires', 'Status'], 'rows' => array_map(static fn (array $r): array => [(string) $r['identifier'], self::label((string) $r['service_type']), (string) $r['client'], local_date($r['expires_on']), RenewalService::label((string) $r['status'])], $soon)],
            ],
            'charts' => [['title' => 'Services expiring per month', 'svg' => Chart::bars($months, array_map(static fn (string $m): int => $up[$m] ?? 0, $months), 'Services expiring per month')]],
        ];
    }

    private static function support(int $userId, string $from, string $to): array
    {
        $scope = TicketAccess::scopeSql($userId);
        if ($scope === null) {
            throw new HttpException(403);
        }
        [$sc, $sp] = $scope;
        $base = "FROM tickets t JOIN clients c ON c.id = t.client_id WHERE {$sc}";
        $opened = self::keyed(Database::all("SELECT DATE_FORMAT(t.submitted_at, '%Y-%m') ym, COUNT(*) n {$base} AND t.submitted_at BETWEEN ? AND ? GROUP BY ym", [...$sp, $from . ' 00:00:00', $to . ' 23:59:59']), 'ym', 'n');
        $resolved = self::keyed(Database::all("SELECT DATE_FORMAT(t.resolved_at, '%Y-%m') ym, COUNT(*) n {$base} AND t.resolved_at BETWEEN ? AND ? GROUP BY ym", [...$sp, $from . ' 00:00:00', $to . ' 23:59:59']), 'ym', 'n');
        $byStatus = Database::all("SELECT t.status, COUNT(*) n {$base} AND t.status <> 'draft' GROUP BY t.status ORDER BY n DESC", $sp);
        $times = Database::one("SELECT AVG(TIMESTAMPDIFF(MINUTE, t.submitted_at, t.first_staff_response_at)) first_min, AVG(TIMESTAMPDIFF(HOUR, t.submitted_at, t.resolved_at)) res_h, SUM(t.escalated_at IS NOT NULL) esc, SUM(t.reopen_count > 0) reopened, SUM(t.first_response_due_at IS NOT NULL) sla_n, SUM(t.first_staff_response_at IS NOT NULL AND t.first_staff_response_at <= t.first_response_due_at) sla_met, SUM(t.sla_breached_at IS NOT NULL) sla_breached {$base} AND t.submitted_at BETWEEN ? AND ?", [...$sp, $from . ' 00:00:00', $to . ' 23:59:59']);
        $months = self::months($from, $to);

        return [
            'summary' => ['Opened in period' => (string) array_sum($opened), 'Resolved in period' => (string) array_sum($resolved), 'Average first response' => $times['first_min'] === null ? '-' : round((float) $times['first_min'] / 60, 1) . ' h', 'Average time to resolve' => $times['res_h'] === null ? '-' : round((float) $times['res_h'], 1) . ' h', 'Escalated' => (string) (int) $times['esc'], 'Reopened' => (string) (int) $times['reopened'],
                'First reply within SLA' => (int) $times['sla_n'] > 0 ? round((int) $times['sla_met'] * 100 / (int) $times['sla_n'], 1) . '%' : '-', 'SLA missed' => (string) (int) $times['sla_breached']],
            'tables' => [
                ['title' => 'By month', 'columns' => ['Month', 'Opened', 'Resolved'], 'rows' => array_map(static fn (string $m): array => [$m, (string) ($opened[$m] ?? 0), (string) ($resolved[$m] ?? 0)], $months)],
                ['title' => 'Open and closed by status', 'columns' => ['Status', 'Tickets'], 'rows' => array_map(static fn (array $r): array => [self::label((string) $r['status']), (string) $r['n']], $byStatus)],
            ],
            'charts' => [['title' => 'Tickets opened per month', 'svg' => Chart::bars($months, array_map(static fn (string $m): int => $opened[$m] ?? 0, $months), 'Tickets opened per month')]],
        ];
    }
}
