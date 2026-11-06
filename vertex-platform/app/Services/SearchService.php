<?php

declare(strict_types=1);

namespace Vertex\Services;

/**
 * One search box across the modules the signed-in user can already open. It calls each module's own list query, so the scope rules
 * (assigned projects only, own records only, no archived records) are the same as on the module's own list; nothing here widens access.
 */
final class SearchService
{
    public const MIN_LENGTH = 2;
    public const PER_GROUP = 5;

    /** @return list<array{label:string,total:int,more:string,items:list<array{title:string,sub:string,url:string}>}> only groups that have hits */
    public static function search(int $userId, string $q): array
    {
        $q = trim(mb_substr($q, 0, 100));
        if (mb_strlen($q) < self::MIN_LENGTH) {
            return [];
        }
        $enc = rawurlencode($q);
        $groups = [];
        $add = static function (string $label, string $more, callable $run) use (&$groups): void {
            try {
                [$total, $items] = $run();
            } catch (\Vertex\Core\HttpException) {
                return; // not allowed to use this module at all
            }
            if ($total > 0) {
                $groups[] = ['label' => $label, 'total' => $total, 'more' => $more, 'items' => $items];
            }
        };
        $per = self::PER_GROUP;

        $add('Clients', '/clients?q=' . $enc, function () use ($userId, $q, $per): array {
            $r = ClientService::paginate($userId, ['q' => $q], 1, $per);

            return [$r['total'], array_map(static fn (array $c): array => ['title' => trim(($c['company_name'] ?: $c['contact_name'])), 'sub' => (string) $c['email'], 'url' => '/clients/' . $c['public_id']], $r['rows'])];
        });
        $add('CRM enquiries', '/crm/enquiries?q=' . $enc, function () use ($userId, $q, $per): array {
            $r = EnquiryQuery::paginate($userId, ['q' => $q], 1, $per);

            return [$r['total'], array_map(static fn (array $e): array => ['title' => $e['reference'] . ' · ' . $e['contact_name'], 'sub' => (string) $e['stage_name'], 'url' => '/crm/enquiries/' . $e['public_id']], $r['rows'])];
        });
        $add('Projects', '/projects?q=' . $enc, function () use ($userId, $q, $per): array {
            $r = ProjectPolicy::visibleTo($userId, ['q' => $q], 1, $per);

            return [$r['total'], array_map(static fn (array $p): array => ['title' => $p['code'] . ' · ' . $p['title'], 'sub' => (string) $p['status'], 'url' => '/projects/' . $p['public_id']], $r['rows'])];
        });
        if (Gate::can('tasks.view', $userId)) {
            $add('Tasks', '/tasks?q=' . $enc, function () use ($userId, $q, $per): array {
                $r = TaskQuery::paginate($userId, ['q' => $q], 1, $per);

                return [$r['total'], array_map(static fn (array $t): array => ['title' => (string) $t['title'], 'sub' => $t['project_code'] . ' · ' . $t['status'], 'url' => '/tasks/' . $t['public_id']], $r['rows'])];
            });
        }
        $add('Support tickets', '/tickets?q=' . $enc, function () use ($userId, $q, $per): array {
            $r = TicketQuery::paginate($userId, ['q' => $q], 1, $per);

            return [$r['total'], array_map(static fn (array $t): array => ['title' => $t['reference'] . ' · ' . $t['subject'], 'sub' => (string) $t['status'], 'url' => '/tickets/' . $t['public_id']], $r['rows'])];
        });
        if (Gate::can('invoices.view_all', $userId)) {
            $add('Invoices', '/invoices?q=' . $enc, function () use ($userId, $q, $per): array {
                $r = InvoiceService::paginate($userId, ['q' => $q], 1, $per);

                return [$r['total'], array_map(static fn (array $i): array => ['title' => $i['reference'] . ' · ' . $i['title'], 'sub' => $i['client_name'] . ' · ' . $i['status'], 'url' => '/invoices/' . $i['public_id']], $r['rows'])];
            });
        }
        if (Gate::canAny(['quotes.create', 'quotes.send', 'quotes.record_decision'], $userId)) {
            $add('Quotations', '/quotations?q=' . $enc, function () use ($userId, $q, $per): array {
                $r = QuotationService::paginate($userId, ['q' => $q], 1, $per);

                return [$r['total'], array_map(static fn (array $x): array => ['title' => $x['reference'] . ' · ' . $x['title'], 'sub' => $x['client_name'] . ' · ' . $x['status'], 'url' => '/quotations/' . $x['public_id']], $r['rows'])];
            });
        }
        if (Gate::canAny(['renewals.view', 'renewals.manage'], $userId)) {
            $add('Domains & hosting', '/renewals?q=' . $enc, function () use ($userId, $q, $per): array {
                $r = RenewalService::paginate($userId, ['q' => $q], 1, $per);

                return [$r['total'], array_map(static fn (array $s): array => ['title' => $s['identifier'] . ' · ' . $s['reference'], 'sub' => $s['client_name'] . ' · ' . $s['status'], 'url' => '/renewals/' . $s['public_id']], $r['rows'])];
            });
        }
        if (Gate::can('users.view', $userId)) {
            $add('Users', '/admin/users?q=' . $enc, function () use ($q, $per): array {
                $r = (new \Vertex\Repositories\UserRepository())->paginate(['q' => $q], 1, $per);

                return [$r['total'], array_map(static fn (array $u): array => ['title' => (string) $u['name'], 'sub' => $u['email'] . ' · ' . $u['role_names'], 'url' => '/admin/users/' . $u['public_id']], $r['rows'])];
            });
        }

        return $groups;
    }
}
