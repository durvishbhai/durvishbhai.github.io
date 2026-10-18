<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Database;

/**
 * What the home screen shows. Every figure comes from a query that already applies the person's own visibility rules (the same scopes as the lists
 * they link to), and a block is built only when the person holds the permission for it, so the page never hints at records they cannot open.
 */
final class DashboardService
{
    /**
     * @return array{stats:list<array{n:string,label:string,href:string,tone:string}>,todo:list<array{title:string,sub:string,href:string,tone:string,when:string}>,actions:list<array{label:string,href:string}>,notifications:list<array<string,mixed>>}
     */
    public static function build(int $userId): array
    {
        $stats = [];
        $todo = [];
        $actions = [];
        $today = AppTime::today();
        $add = static function (string $n, string $label, string $href, string $tone = '') use (&$stats): void {
            $stats[] = ['n' => $n, 'label' => $label, 'href' => $href, 'tone' => $tone];
        };

        // Projects the person can see
        try {
            $scope = ProjectPolicy::scopeSql($userId);
            if ($scope !== null) {
                [$sw, $sp] = $scope;
                $n = (int) Database::one("SELECT COUNT(*) AS c FROM projects p LEFT JOIN clients c ON c.id = p.client_id WHERE ({$sw}) AND p.archived_at IS NULL AND p.status IN ('in_progress','ready_to_start','awaiting_client_approval','awaiting_payment')", $sp)['c'];
                $add((string) $n, 'Active projects', '/projects');
            }
        } catch (\Throwable) {
        }

        // Tasks, deadlines and milestones
        try {
            if (Gate::can('tasks.view', $userId)) {
                $d = TaskQuery::deadlines($userId);
                $add((string) count($d['overdue_tasks']), 'Overdue tasks', '/deadlines', count($d['overdue_tasks']) > 0 ? 'bad' : '');
                $add((string) count($d['upcoming_tasks']), 'Due this week', '/deadlines');
                if ($d['blocked_tasks'] !== []) {
                    $add((string) count($d['blocked_tasks']), 'Blocked tasks', '/tasks?status=blocked', 'warn');
                }
                foreach (array_slice($d['overdue_tasks'], 0, 4) as $t) {
                    $todo[] = ['title' => (string) $t['title'], 'sub' => (string) ($t['project_title'] ?? $t['project_code'] ?? ''), 'href' => '/tasks/' . ($t['public_id'] ?? ''), 'tone' => 'bad', 'when' => 'Overdue since ' . local_date((string) $t['due_date'])];
                }
                foreach (array_slice($d['upcoming_tasks'], 0, 5) as $t) {
                    $todo[] = ['title' => (string) $t['title'], 'sub' => (string) ($t['project_title'] ?? $t['project_code'] ?? ''), 'href' => '/tasks/' . ($t['public_id'] ?? ''), 'tone' => '', 'when' => 'Due ' . local_date((string) $t['due_date'])];
                }
                foreach (array_slice($d['overdue_milestones'], 0, 3) as $m) {
                    $todo[] = ['title' => 'Milestone: ' . $m['title'], 'sub' => (string) $m['project_title'], 'href' => '/projects/' . $m['project_public_id'], 'tone' => 'bad', 'when' => 'Overdue since ' . local_date((string) $m['target_date'])];
                }
            }
        } catch (\Throwable) {
        }

        // Sales follow-ups
        try {
            if (Gate::can('enquiries.view_all', $userId) || Gate::can('enquiries.view_assigned', $userId)) {
                $e = EnquiryQuery::dashboard($userId);
                $open = 0;
                foreach ($e['stages'] as $s) {
                    $open += ($s['kind'] ?? '') === 'open' ? (int) $s['n'] : 0;
                }
                $add((string) $open, 'Open enquiries', '/crm/enquiries');
                $late = count($e['overdue']);
                if ($late > 0) {
                    $add((string) $late, 'Follow-ups overdue', '/crm', 'bad');
                }
                foreach (array_slice($e['overdue'], 0, 3) as $f) {
                    $todo[] = ['title' => 'Follow up: ' . ($f['company_name'] ?: $f['contact_name']), 'sub' => (string) $f['reference'], 'href' => '/crm/enquiries/' . $f['public_id'], 'tone' => 'bad', 'when' => 'Was due ' . local_date((string) $f['next_followup_date'])];
                }
                foreach (array_slice($e['today'], 0, 3) as $f) {
                    $todo[] = ['title' => 'Follow up: ' . ($f['company_name'] ?: $f['contact_name']), 'sub' => (string) $f['reference'], 'href' => '/crm/enquiries/' . $f['public_id'], 'tone' => '', 'when' => 'Due today'];
                }
            }
        } catch (\Throwable) {
        }

        // Support queue (staff) or own tickets (client)
        try {
            if (TicketQuery::staffView($userId)) {
                $t = TicketQuery::dashboard($userId);
                $add((string) ($t['open'] ?? 0), 'Open tickets', '/tickets');
                if (($t['unassigned'] ?? 0) > 0) {
                    $add((string) $t['unassigned'], 'Unassigned tickets', '/tickets?group=unassigned', 'warn');
                }
                if (($t['escalated'] ?? 0) > 0) {
                    $add((string) $t['escalated'], 'Escalated', '/tickets', 'bad');
                }
            }
        } catch (\Throwable) {
        }

        // Money (staff)
        try {
            if (Gate::can('invoices.view_all', $userId)) {
                $r = Database::one("SELECT COUNT(*) AS n, COALESCE(SUM(due_date IS NOT NULL AND due_date < ?), 0) AS late FROM invoices WHERE status IN ('issued','partially_paid')", [$today]);
                $add((string) (int) $r['n'], 'Unpaid invoices', '/invoices');
                if ((int) $r['late'] > 0) {
                    $add((string) (int) $r['late'], 'Invoices overdue', '/invoices', 'bad');
                }
            }
            if (Gate::can('payments.reconcile', $userId)) {
                $p = (int) Database::one("SELECT COUNT(*) AS c FROM client_payments WHERE status = 'pending_verification'")['c'];
                if ($p > 0) {
                    $add((string) $p, 'Payments to verify', '/client-payments', 'warn');
                }
            }
        } catch (\Throwable) {
        }

        // Client view
        try {
            if (Gate::can('invoices.view_own', $userId) && Database::one('SELECT 1 AS x FROM clients WHERE user_id = ?', [$userId]) !== null) {
                $unpaid = 0;
                $late = 0;
                foreach (InvoiceService::forClient($userId) as $i) {
                    if (in_array($i['status'], ['issued', 'partially_paid'], true)) {
                        $unpaid++;
                        $late += $i['due_date'] !== null && $i['due_date'] < $today ? 1 : 0;
                        $todo[] = ['title' => 'Pay invoice ' . $i['reference'], 'sub' => (string) $i['title'], 'href' => '/my/invoices/' . $i['public_id'], 'tone' => $i['due_date'] !== null && $i['due_date'] < $today ? 'bad' : '', 'when' => $i['due_date'] ? 'Due ' . local_date((string) $i['due_date']) : 'Unpaid'];
                    }
                }
                $add((string) $unpaid, 'Invoices to pay', '/my/invoices', $late > 0 ? 'bad' : '');
            }
        } catch (\Throwable) {
        }

        // Approvals waiting
        try {
            $w = array_sum(array_column(ApprovalCenter::pending($userId), 'count'));
            if ($w > 0) {
                array_unshift($stats, ['n' => (string) $w, 'label' => 'Waiting for your decision', 'href' => '/approvals', 'tone' => 'warn']);
            }
        } catch (\Throwable) {
        }

        // Quick actions
        foreach ([['projects.create', '/projects/create', 'New project'], ['enquiries.create', '/crm/enquiries/create', 'New enquiry'], ['clients.create', '/clients/create', 'New client'], ['quotes.create', '/quotations/new', 'New quotation'],
            ['invoices.create', '/invoices/new', 'New invoice'], ['tickets.create', '/support/tickets/new', 'Get support'], ['scope.request', '/my/scope-requests', 'Request a change'], ['cms.view', '/admin/cms', 'Edit the website'], ['ai.use', '/ai', 'Ask the AI assistant']] as [$perm, $href, $label]) {
            $customerOnly = in_array($perm, ['tickets.create', 'scope.request'], true);
            if (Gate::can($perm, $userId) && (!$customerOnly || Database::one('SELECT 1 AS x FROM clients WHERE user_id = ?', [$userId]) !== null)) {
                $actions[] = ['label' => $label, 'href' => $href];
            }
        }

        return ['stats' => array_slice($stats, 0, 10), 'todo' => array_slice($todo, 0, 12), 'actions' => array_slice($actions, 0, 6), 'notifications' => Notifier::paginate($userId, true, 1, 5)['rows']];
    }
}
