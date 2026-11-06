<?php

declare(strict_types=1);

namespace Vertex\Http\Controllers\Billing;

use Vertex\Core\Database;
use Vertex\Core\HttpException;
use Vertex\Core\Request;
use Vertex\Core\Response;
use Vertex\Core\Session;
use Vertex\Http\Controllers\Controller;
use Vertex\Services\BusinessProfile;
use Vertex\Services\Gate;
use Vertex\Services\InvoiceService;
use Vertex\Services\QuotationService;

/** Staff screens for quotations. Every action re-checks its own permission in the service. */
final class QuotationController extends Controller
{
    private const PER_PAGE = 20;

    /** @param array<string,string> $params */
    public function index(Request $request, array $params = []): Response
    {
        $f = [];
        foreach (['q', 'status', 'client'] as $k) {
            $f[$k] = mb_substr($request->string($k), 0, 100);
        }
        $page = $request->page();
        $res = QuotationService::paginate($this->actorId(), $f, $page, self::PER_PAGE);

        return $this->page('quotations/index', ['title' => 'Quotations', 'rows' => $res['rows'], 'total' => $res['total'], 'page' => $page, 'perPage' => self::PER_PAGE, 'filters' => $f,
            'qs' => array_filter($f, static fn ($v): bool => $v !== ''), 'canCreate' => Gate::can('quotes.create', $this->actorId())]);
    }

    /** @return array<string,mixed> */
    private function formData(): array
    {
        return ['clients' => Database::all("SELECT public_id, contact_name, company_name FROM clients WHERE archived_at IS NULL ORDER BY COALESCE(NULLIF(company_name, ''), contact_name) LIMIT 1000"),
            'projects' => Database::all('SELECT p.public_id, p.code, p.title, c.public_id AS client_public_id FROM projects p JOIN clients c ON c.id = p.client_id WHERE p.archived_at IS NULL ORDER BY p.code DESC LIMIT 500')];
    }

    /** @param array<string,string> $params */
    public function create(Request $request, array $params = []): Response
    {
        Gate::authorize('quotes.create', $this->actorId());
        $r = $this->page('quotations/form', ['title' => 'New quotation', 'q' => null, 'items' => []] + $this->formData());
        Session::forget('_old');

        return $r;
    }

    /** @return array<string,mixed> */
    private function fields(Request $request): array
    {
        return ['title' => $request->string('title'), 'project' => $request->string('project'), 'valid_until' => $request->string('valid_until'), 'client_notes' => $request->string('client_notes'),
            'internal_notes' => $request->string('internal_notes'), 'items' => $request->body['items'] ?? []];
    }

    /** @param array<string,string> $params */
    public function store(Request $request, array $params = []): Response
    {
        $in = $this->fields($request);
        $client = $request->string('client');

        return $this->guarded(function () use ($client, $in): Response {
            $r = QuotationService::create($this->actorId(), $client, $in);

            return $this->ok('/quotations/' . $r['public_id'], "Quotation {$r['reference']} saved as a draft.");
        }, '/quotations/new', $in + ['client' => $client]);
    }

    /** @param array<string,mixed> $in @return array<string,string> */
    private function flat(array $in): array
    {
        return array_map(static fn ($v): string => is_scalar($v) ? (string) $v : '', array_diff_key($in, ['items' => 1]));
    }

    /** @param array<string,string> $params */
    public function show(Request $request, array $params = []): Response
    {
        $uid = $this->actorId();
        $q = QuotationService::requireStaff($uid, $params['quote'] ?? '');
        $r = $this->page('quotations/show', ['title' => $q['reference'], 'q' => $q, 'items' => QuotationService::items($q), 'canCreate' => Gate::can('quotes.create', $uid), 'canSend' => Gate::can('quotes.send', $uid),
            'canDecide' => Gate::can('quotes.record_decision', $uid), 'canInvoice' => Gate::can('invoices.create', $uid), 'profileOk' => BusinessProfile::isComplete()] + $this->formData());
        Session::forget('_old');

        return $r;
    }

    /** @param array<string,string> $params */
    public function action(Request $request, array $params = []): Response
    {
        $uid = $this->actorId();
        $q = QuotationService::requireStaff($uid, $params['quote'] ?? '');
        $to = '/quotations/' . $q['public_id'];
        $action = $request->string('action');

        return $this->guarded(function () use ($request, $uid, $q, $to, $action): Response {
            switch ($action) {
                case 'edit':
                    QuotationService::update($uid, $q, $this->fields($request));

                    return $this->ok($to, 'Saved.');
                case 'send':
                    QuotationService::send($uid, $q);

                    return $this->ok($to, 'Sent. The client was notified in the portal.');
                case 'withdraw':
                    QuotationService::withdraw($uid, $q, $request->string('reason'));

                    return $this->ok($to, 'Withdrawn.');
                case 'decision':
                    QuotationService::recordOfflineDecision($uid, $q, $request->string('decision'), $request->string('note'));

                    return $this->ok($to, 'The decision was recorded.');
                case 'invoice':
                    $inv = InvoiceService::createFromQuotation($uid, $q);

                    return $this->ok('/invoices/' . $inv['public_id'], 'A draft invoice was created from this quotation. Review it, then issue it.');
            }
            throw new HttpException(404);
        }, $to, $this->fields($request) + ['reason' => $request->string('reason'), 'note' => $request->string('note')]);
    }
}
