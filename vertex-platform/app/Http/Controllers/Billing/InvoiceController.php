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
use Vertex\Services\ClientPaymentService;
use Vertex\Services\CreditNoteService;
use Vertex\Services\Gate;
use Vertex\Services\InvoiceService;

/** Staff screens for invoices. An issued invoice is never edited; issuing and voiding need the password. */
final class InvoiceController extends Controller
{
    private const PER_PAGE = 20;

    /** @param array<string,string> $params */
    public function index(Request $request, array $params = []): Response
    {
        $uid = $this->actorId();
        $f = [];
        foreach (['q', 'status', 'client', 'from', 'to'] as $k) {
            $f[$k] = mb_substr($request->string($k), 0, 100);
        }
        $f['overdue'] = $request->string('overdue') === '1';
        $page = $request->page();
        $res = InvoiceService::paginate($uid, $f, $page, self::PER_PAGE);

        return $this->page('invoices/index', ['title' => 'Invoices', 'rows' => $res['rows'], 'total' => $res['total'], 'page' => $page, 'perPage' => self::PER_PAGE, 'filters' => $f,
            'qs' => array_filter($f, static fn ($v): bool => $v !== '' && $v !== false), 'dash' => InvoiceService::dashboard($uid), 'canCreate' => Gate::can('invoices.create', $uid), 'profileOk' => BusinessProfile::isComplete()]);
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
        Gate::authorize('invoices.create', $this->actorId());
        $r = $this->page('invoices/form', ['title' => 'New invoice', 'i' => null, 'items' => []] + $this->formData());
        Session::forget('_old');

        return $r;
    }

    /** @return array<string,mixed> */
    private function fields(Request $request): array
    {
        return ['title' => $request->string('title'), 'project' => $request->string('project'), 'due_date' => $request->string('due_date'), 'discount' => $request->string('discount'), 'coupon_code' => $request->string('coupon_code'),
            'client_notes' => $request->string('client_notes'), 'internal_notes' => $request->string('internal_notes'), 'items' => $request->body['items'] ?? []];
    }

    /** @param array<string,string> $params */
    public function store(Request $request, array $params = []): Response
    {
        $in = $this->fields($request);
        $client = $request->string('client');

        return $this->guarded(function () use ($client, $in): Response {
            $r = InvoiceService::create($this->actorId(), $client, $in);

            return $this->ok('/invoices/' . $r['public_id'], 'Saved as a draft. It gets its number when you issue it.');
        }, '/invoices/new', $in + ['client' => $client]);
    }

    /** @param array<string,string> $params */
    public function show(Request $request, array $params = []): Response
    {
        $uid = $this->actorId();
        $i = InvoiceService::requireStaff($uid, $params['invoice'] ?? '');
        $r = $this->page('invoices/show', ['title' => $i['reference'] ?? 'Draft invoice', 'i' => $i, 'items' => InvoiceService::items($i), 'bal' => InvoiceService::balance($i), 'payments' => InvoiceService::payments($i),
            'canEdit' => Gate::can('invoices.edit', $uid), 'canIssue' => Gate::can('invoices.issue', $uid), 'canVoid' => Gate::can('invoices.void', $uid), 'canRecord' => Gate::can('payments.record', $uid), 'creditNotes' => CreditNoteService::forInvoice($i), 'canCredit' => Gate::can('creditnotes.issue', $uid), 'creditable' => CreditNoteService::creditable($i),
            'profileOk' => BusinessProfile::isComplete(), 'methods' => \Vertex\Core\Config::get('referrals.payment_methods')] + $this->formData());
        Session::forget('_old');

        return $r;
    }

    /** The printable invoice (use the browser's Print → Save as PDF). @param array<string,string> $params */
    public function print(Request $request, array $params = []): Response
    {
        $i = InvoiceService::requireStaff($this->actorId(), $params['invoice'] ?? '');

        return $this->page('invoices/print', ['title' => $i['reference'] ?? 'Draft invoice', 'i' => $i, 'items' => InvoiceService::items($i), 'bal' => InvoiceService::balance($i), 'payments' => InvoiceService::clientPayments($i), 'back' => '/invoices/' . $i['public_id']], 'layouts/print');
    }

    /** @param array<string,string> $params */
    public function action(Request $request, array $params = []): Response
    {
        $uid = $this->actorId();
        $i = InvoiceService::requireStaff($uid, $params['invoice'] ?? '');
        $to = '/invoices/' . $i['public_id'];
        $action = $request->string('action');

        return $this->guarded(function () use ($request, $uid, $i, $to, $action): Response {
            switch ($action) {
                case 'edit':
                    InvoiceService::update($uid, $i, $this->fields($request));

                    return $this->ok($to, 'Saved.');
                case 'issue':
                    $this->requireStepUp($request);
                    InvoiceService::issue($uid, $i);

                    return $this->ok($to, 'The invoice was issued and the client was notified. It can no longer be edited.');
                case 'void':
                    $this->requireStepUp($request);
                    InvoiceService::void($uid, $i, $request->string('reason'));

                    return $this->ok($to, 'The invoice was voided. Its number is not reused.');
                case 'credit_note':
                    $this->requireStepUp($request);
                    $n = CreditNoteService::issue($uid, $i, $request->string('amount'), $request->string('reason'));

                    return $this->ok($to, "Credit note {$n['reference']} issued. The client sees the reduced amount.");
                case 'void_credit_note':
                    $this->requireStepUp($request);
                    $note = CreditNoteService::find($request->string('note')) ?? throw new HttpException(404);
                    if ((int) $note['invoice_id'] !== (int) $i['id']) {
                        throw new HttpException(404);
                    }
                    CreditNoteService::void($uid, $note, $request->string('reason'));

                    return $this->ok($to, 'The credit note was voided.');
                case 'payment':
                    $res = ClientPaymentService::record($uid, ['invoice' => $i['public_id'], 'role' => $request->string('role'), 'gross' => $request->string('gross'), 'tax' => '0', 'method' => $request->string('method'),
                        'external_ref' => $request->string('external_ref'), 'received_on' => $request->string('received_on'), 'notes' => $request->string('notes')]);

                    return $this->ok($to, "Payment {$res['reference']} recorded. It counts only after a second person verifies it (Client payments).");
            }
            throw new HttpException(404);
        }, $to, $this->fields($request) + ['reason' => $request->string('reason'), 'gross' => $request->string('gross'), 'external_ref' => $request->string('external_ref')]);
    }
}
