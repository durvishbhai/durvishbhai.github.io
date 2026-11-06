<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\HttpException;

/**
 * CSV export of the lists a user may already see. It reuses each module's own list query, so the scope rules (assigned projects, own records, archived
 * hidden) are exactly the ones on screen; `data.export` is an extra gate, and every export is audited with its type, filters and row count.
 */
final class DataExport
{
    public const MAX_ROWS = 10000;

    /** @return array<string,array{label:string,can:callable,fetch:callable,columns:array<string,string>}> */
    public static function types(): array
    {
        return [
            'clients' => ['label' => 'Clients', 'can' => fn (int $u): bool => Gate::canAny(['clients.view_all', 'clients.view_assigned'], $u),
                'fetch' => fn (int $u, array $f, int $p): array => ClientService::paginate($u, $f, $p, 100),
                'columns' => ['Name' => 'contact_name', 'Company' => 'company_name', 'Type' => 'client_type', 'Email' => 'email', 'Phone' => 'phone', 'Status' => 'status', 'Created (UTC)' => 'created_at']],
            'enquiries' => ['label' => 'CRM enquiries', 'can' => fn (int $u): bool => Gate::canAny(['enquiries.view_all', 'enquiries.view_assigned'], $u),
                'fetch' => fn (int $u, array $f, int $p): array => EnquiryQuery::paginate($u, $f, $p, 100),
                'columns' => ['Reference' => 'reference', 'Name' => 'contact_name', 'Company' => 'company_name', 'Email' => 'email', 'Service' => 'service', 'Source' => 'source', 'Stage' => 'stage_name', 'Priority' => 'priority', 'Owner' => 'owner_name', 'Next follow-up' => 'next_followup_date', 'Created (UTC)' => 'created_at']],
            'projects' => ['label' => 'Projects', 'can' => fn (int $u): bool => Gate::canAny(['projects.view_all', 'projects.view_assigned'], $u),
                'fetch' => fn (int $u, array $f, int $p): array => ProjectPolicy::visibleTo($u, $f, $p, 100),
                'columns' => ['Code' => 'code', 'Title' => 'title', 'Client' => 'company_name', 'Contact' => 'client_name', 'Service' => 'service_category', 'Status' => 'status', 'Priority' => 'priority', 'Start' => 'start_date', 'Target' => 'target_date', 'Tasks' => 'task_total', 'Tasks done' => 'task_done']],
            'tasks' => ['label' => 'Tasks', 'can' => fn (int $u): bool => Gate::can('tasks.view', $u) && Gate::canAny(['projects.view_all', 'projects.view_assigned'], $u),
                'fetch' => fn (int $u, array $f, int $p): array => TaskQuery::paginate($u, $f, $p, 100),
                'columns' => ['Project' => 'project_code', 'Task' => 'title', 'Status' => 'status', 'Priority' => 'priority', 'Assignee' => 'assignee_name', 'Start' => 'start_date', 'Due' => 'due_date', 'Estimate (min)' => 'estimate_minutes', 'Logged (min)' => 'logged_minutes']],
            'tickets' => ['label' => 'Support tickets', 'can' => fn (int $u): bool => Gate::canAny(['tickets.view_all', 'tickets.view_assigned'], $u),
                'fetch' => fn (int $u, array $f, int $p): array => TicketQuery::paginate($u, $f, $p, 100),
                'columns' => ['Reference' => 'reference', 'Kind' => 'kind', 'Subject' => 'subject', 'Client' => 'client_name', 'Project' => 'project_code', 'Status' => 'status', 'Priority' => 'priority', 'Assigned to' => 'assignee_name', 'Created (UTC)' => 'created_at']],
            'invoices' => ['label' => 'Invoices', 'can' => fn (int $u): bool => Gate::can('invoices.view_all', $u),
                'fetch' => fn (int $u, array $f, int $p): array => InvoiceService::paginate($u, $f, $p, 100),
                'columns' => ['Invoice' => 'reference', 'Title' => 'title', 'Client' => 'client_name', 'Status' => 'status', 'Issued' => 'issue_date', 'Due' => 'due_date', 'Total (INR)' => 'total_minor@money', 'Paid (INR)' => 'paid_minor@money']],
            'quotations' => ['label' => 'Quotations', 'can' => fn (int $u): bool => Gate::canAny(['quotes.create', 'quotes.send', 'quotes.record_decision'], $u),
                'fetch' => fn (int $u, array $f, int $p): array => QuotationService::paginate($u, $f, $p, 100),
                'columns' => ['Quotation' => 'reference', 'Title' => 'title', 'Client' => 'client_name', 'Status' => 'status', 'Valid until' => 'valid_until', 'Total (INR)' => 'total_minor@money', 'Created (UTC)' => 'created_at']],
            'users' => ['label' => 'Users', 'can' => fn (int $u): bool => Gate::can('users.view', $u),
                'fetch' => fn (int $u, array $f, int $p): array => (new \Vertex\Repositories\UserRepository())->paginate($f, $p, 100),
                'columns' => ['Name' => 'name', 'Email' => 'email', 'Status' => 'status', 'Roles' => 'role_names', 'Last sign-in (UTC)' => 'last_login_at']],
        ];
    }

    /** @return array<string,string> key => label of the exports this user may run */
    public static function available(int $userId): array
    {
        if (!Gate::can('data.export', $userId)) {
            return [];
        }
        $out = [];
        foreach (self::types() as $k => $t) {
            if (($t['can'])($userId)) {
                $out[$k] = $t['label'];
            }
        }

        return $out;
    }

    /** @param array<string,mixed> $filters @return array{csv:string,count:int,truncated:bool} */
    public static function run(int $userId, string $type, array $filters): array
    {
        Gate::authorize('data.export', $userId);
        $def = self::types()[$type] ?? throw new HttpException(404);
        if (!($def['can'])($userId)) {
            throw new HttpException(403);
        }
        $rows = [];
        $truncated = false;
        for ($page = 1; $page <= (int) ceil(self::MAX_ROWS / 100); $page++) {
            $res = ($def['fetch'])($userId, $filters, $page);
            foreach ($res['rows'] as $r) {
                $line = [];
                foreach ($def['columns'] as $src) {
                    $line[] = self::cell($r, $src);
                }
                $rows[] = $line;
            }
            if (count($rows) >= (int) $res['total']) {
                break;
            }
            if (count($rows) >= self::MAX_ROWS) {
                $truncated = true;
                break;
            }
        }
        Audit::record('data.exported', null, null, [], [], ['type' => $type, 'rows' => count($rows), 'filters' => array_filter($filters, static fn ($v): bool => $v !== '' && $v !== null && $v !== false), 'truncated' => $truncated], $userId);

        return ['csv' => Csv::build(array_keys($def['columns']), $rows), 'count' => count($rows), 'truncated' => $truncated];
    }

    /** @param array<string,mixed> $row */
    private static function cell(array $row, string $src): string
    {
        if (str_ends_with($src, '@money')) {
            $v = $row[substr($src, 0, -6)] ?? null;

            return $v === null ? '' : number_format(((int) $v) / 100, 2, '.', '');
        }

        return (string) ($row[$src] ?? '');
    }
}
