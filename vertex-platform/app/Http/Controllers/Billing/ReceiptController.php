<?php

declare(strict_types=1);

namespace Vertex\Http\Controllers\Billing;

use Vertex\Core\Database;
use Vertex\Core\Request;
use Vertex\Core\Response;
use Vertex\Core\Session;
use Vertex\Http\Controllers\Controller;
use Vertex\Services\DocumentPdf;
use Vertex\Services\Gate;
use Vertex\Services\QrCode;
use Vertex\Services\RateLimiter;
use Vertex\Services\ReceiptService;

/**
 * Payment receipts. Staff list them and issue one by hand for a payment made outside the system (receipts.issue); clients see their own; and anyone can check a
 * receipt on the public verification page with the code printed on it (no sign-in, no contact details shown, rate limited).
 */
final class ReceiptController extends Controller
{
    // ------------------------------------------------------------------------------------------------ staff

    /** @param array<string,string> $params */
    public function index(Request $request, array $params = []): Response
    {
        $rows = ReceiptService::recent();

        return $this->page('receipts/index', ['title' => 'Receipts', 'rows' => array_map(static fn (array $r): array => ReceiptService::present($r) + ['id' => $r['public_id']], $rows), 'canIssue' => Gate::can('receipts.issue', $this->actorId())]);
    }

    /** @param array<string,string> $params */
    public function create(Request $request, array $params = []): Response
    {
        $clients = Database::all("SELECT public_id, COALESCE(NULLIF(company_name, ''), contact_name) AS name, email FROM clients WHERE status = 'active' AND merged_into_client_id IS NULL ORDER BY name LIMIT 500");
        $r = $this->page('receipts/new', ['title' => 'Issue a receipt', 'clients' => $clients, 'methods' => ReceiptService::METHODS, 'today' => \Vertex\Services\AppTime::today(), 'now' => \Vertex\Services\AppTime::now()->format('H:i')]);
        Session::forget('_old');

        return $r;
    }

    /** @param array<string,string> $params */
    public function store(Request $request, array $params = []): Response
    {
        $lines = [];
        foreach ((array) $request->input('lines', []) as $l) {
            if (is_array($l)) {
                $lines[] = ['description' => (string) ($l['description'] ?? ''), 'amount' => (string) ($l['amount'] ?? '')];
            }
        }
        $in = ['client' => $request->string('client'), 'client_name' => $request->string('client_name'), 'client_email' => $request->string('client_email'), 'client_phone' => $request->string('client_phone'),
            'client_address' => $request->string('client_address'), 'lines' => $lines, 'discount' => $request->string('discount') ?: '0', 'tax' => $request->string('tax') ?: '0', 'method' => $request->string('method'),
            'txn_id' => $request->string('txn_id'), 'paid_on' => $request->string('paid_on'), 'paid_time' => $request->string('paid_time'), 'against' => $request->string('against'), 'note' => $request->string('note')];
        $uid = $this->actorId();

        return $this->guarded(function () use ($request, $in, $uid): Response {
            Gate::authorize('receipts.issue', $uid);
            $this->requireStepUp($request);
            $r = ReceiptService::issueManual($uid, $in);

            return $this->ok('/receipts/' . $r['public_id'], 'Receipt ' . $r['number'] . ' was issued.');
        }, '/receipts/new', array_filter($in, 'is_string'));
    }

    /** @param array<string,string> $params */
    public function show(Request $request, array $params = []): Response
    {
        $r = ReceiptService::requireStaff($this->actorId(), $params['receipt'] ?? '');

        return $this->document($r, '/receipts', '/receipts/' . $r['public_id'] . '/pdf', true);
    }

    /** @param array<string,string> $params */
    public function pdf(Request $request, array $params = []): Response
    {
        $r = ReceiptService::requireStaff($this->actorId(), $params['receipt'] ?? '');

        return $this->pdfResponse($r);
    }

    /** @param array<string,string> $params */
    public function void(Request $request, array $params = []): Response
    {
        $r = ReceiptService::requireStaff($this->actorId(), $params['receipt'] ?? '');
        $to = '/receipts/' . $r['public_id'];

        return $this->guarded(function () use ($request, $r, $to): Response {
            $this->requireStepUp($request);
            ReceiptService::void($this->actorId(), $r, $request->string('reason'));

            return $this->ok($to, 'The receipt was voided. It still exists and the verification page now says it is void.');
        }, $to);
    }

    // ------------------------------------------------------------------------------------------------ the client

    /** @param array<string,string> $params */
    public function mine(Request $request, array $params = []): Response
    {
        $rows = array_map(static fn (array $r): array => ReceiptService::present($r), ReceiptService::forClient($this->actorId()));

        return $this->page('receipts/my-index', ['title' => 'My receipts', 'rows' => $rows]);
    }

    /** @param array<string,string> $params */
    public function mineShow(Request $request, array $params = []): Response
    {
        $r = ReceiptService::requireOwn($this->actorId(), $params['receipt'] ?? '');

        return $this->document($r, '/my/receipts', '/my/receipt/' . $r['public_id'] . '/pdf', false);
    }

    /** @param array<string,string> $params */
    public function minePdf(Request $request, array $params = []): Response
    {
        return $this->pdfResponse(ReceiptService::requireOwn($this->actorId(), $params['receipt'] ?? ''));
    }

    // ------------------------------------------------------------------------------------------------ public verification

    /** @param array<string,string> $params */
    public function verify(Request $request, array $params = []): Response
    {
        $code = (string) ($params['code'] ?? $request->query['code'] ?? '');
        $number = $request->string('number');
        $searched = $code !== '';
        $found = null;
        $limited = false;
        if ($searched) {
            if (!RateLimiter::hit('receipt_verify_ip', $request->ip())) {
                $limited = true;
            } else {
                // The code in the address (from the QR) is enough; typed in by hand, the receipt number must match too.
                $found = isset($params['code']) ? ReceiptService::byCode($code) : ReceiptService::byNumberAndCode($number, $code);
            }
        }
        $v = $found === null ? null : ReceiptService::present($found);
        $public = $v === null ? null : [
            'number' => $v['number'], 'effective' => $v['effective'], 'sealOk' => $v['sealOk'], 'amount' => $v['paidMinor'], 'refunded' => $v['refunded'], 'method' => $v['methodLabel'], 'paidDate' => $v['paidDate'], 'paidTime' => $v['paidTime'],
            'issuedDate' => $v['issuedDate'], 'client' => ReceiptService::maskName((string) ($v['client']['name'] ?? '')), 'against' => (string) ($v['against'] ?? ''), 'txn' => '••••' . substr((string) $v['txn'], -4),
            'by' => $v['manual'] ? 'the KIS team' : 'the KIS system', 'voidReason' => $v['effective'] === 'void' ? (string) $v['voidReason'] : '',
        ];
        $title = 'Verify a payment receipt';
        $r = Response::html(\Vertex\Core\View::render('public/verify-receipt', ['title' => $title, 'meta' => ['description' => 'Check that a KIS payment receipt is genuine.', 'canonical' => '', 'ogImage' => '', 'ogTitle' => $title], 'noindex' => true,
            'errors' => [], 'notice' => null, 'searched' => $searched, 'limited' => $limited, 'r' => $public, 'number' => $number, 'code' => $code], 'layouts/public'), $found !== null || !$searched ? 200 : 404, ['Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer']);

        return $r;
    }

    // ------------------------------------------------------------------------------------------------ shared

    /** The receipt page for a receipt the caller was already allowed to open (also used by the older per-payment receipt links). @param array<string,mixed> $r */
    public function document(array $r, string $back, string $pdf, bool $staff): Response
    {
        $v = ReceiptService::present($r);
        $uid = $this->actorId();

        return $this->page('receipts/document', ['title' => 'Receipt ' . $r['number'], 'v' => $v, 'back' => $back, 'pdf' => $pdf, 'qr' => QrCode::svg((string) $v['verifyUrl'], 'QR code to verify receipt ' . $r['number']), 'staff' => $staff,
            'canVoid' => $staff && Gate::can('receipts.void', $uid) && $r['status'] === 'valid', 'rid' => $r['public_id']], 'layouts/print');
    }

    /** @param array<string,mixed> $r */
    public function pdfResponse(array $r): Response
    {
        return new Response(200, DocumentPdf::receiptDocument(ReceiptService::present($r)), ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="receipt-' . preg_replace('/[^A-Za-z0-9._-]/', '_', (string) $r['number']) . '.pdf"', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }
}
