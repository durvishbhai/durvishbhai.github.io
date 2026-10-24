<?php

declare(strict_types=1);

namespace Vertex\Http\Controllers\Billing;

use Vertex\Core\HttpException;
use Vertex\Core\Request;
use Vertex\Core\Response;
use Vertex\Core\Session;
use Vertex\Http\Controllers\Controller;
use Vertex\Services\AppTime;
use Vertex\Services\BusinessProfile;
use Vertex\Services\ClientPaymentService;
use Vertex\Services\InvoiceService;
use Vertex\Services\ManualUpi;
use Vertex\Services\QuotationService;

/** The client's own invoices and quotations. A client can pay, submit proof of payment and accept or decline; nothing else. */
final class MyBillingController extends Controller
{
    /** @param array<string,string> $params */
    public function invoices(Request $request, array $params = []): Response
    {
        return $this->page('my-billing/invoices', ['title' => 'My invoices', 'rows' => InvoiceService::forClient($this->actorId())]);
    }

    /** @param array<string,string> $params */
    public function invoice(Request $request, array $params = []): Response
    {
        $uid = $this->actorId();
        $i = InvoiceService::requireOwn($uid, $params['invoice'] ?? '');
        $bal = InvoiceService::balance($i);
        $payable = in_array($i['status'], ['issued', 'partially_paid'], true) ? max(0, $bal['outstanding'] - $bal['pending']) : 0;
        $r = $this->page('my-billing/invoice', ['title' => $i['reference'], 'i' => $i, 'items' => InvoiceService::items($i), 'bal' => $bal, 'payments' => InvoiceService::clientPayments($i), 'creditNotes' => \Vertex\Services\CreditNoteService::forInvoice($i),
            'biz' => $i['business_snapshot'] ? (array) json_decode((string) $i['business_snapshot'], true) : BusinessProfile::get(), 'payable' => $payable,
            'today' => AppTime::today(), 'manual' => ManualUpi::active() ? ManualUpi::settings() : null, 'razorpayOn' => \Vertex\Services\ManualUpi::razorpayOn(),
            'claims' => \Vertex\Core\Database::all("SELECT reference, gross_minor, method, status, received_on FROM client_payments WHERE invoice_id = ? AND source = 'client_claim' AND recorded_by = ? ORDER BY id DESC LIMIT 10", [$i['id'], $uid])]);
        Session::forget('_old');

        return $r;
    }

    /** @param array<string,string> $params */
    public function print(Request $request, array $params = []): Response
    {
        $i = InvoiceService::requireOwn($this->actorId(), $params['invoice'] ?? '');

        return $this->page('invoices/print', ['title' => $i['reference'], 'i' => $i, 'items' => InvoiceService::items($i), 'bal' => InvoiceService::balance($i), 'payments' => InvoiceService::clientPayments($i), 'back' => '/my/invoices/' . $i['public_id']], 'layouts/print');
    }

    /** "I have paid": records a claim for staff to verify against the bank. @param array<string,string> $params */
    public function claim(Request $request, array $params = []): Response
    {
        $uid = $this->actorId();
        $i = InvoiceService::requireOwn($uid, $params['invoice'] ?? '');
        $to = '/my/invoices/' . $i['public_id'];
        $in = ['amount' => $request->string('amount'), 'method' => $request->string('method'), 'reference' => $request->string('reference'), 'paid_on' => $request->string('paid_on')];
        $proof = $request->files['proof'] ?? null;

        return $this->guarded(function () use ($uid, $i, $to, $in, $proof): Response {
            $res = ClientPaymentService::claim($uid, $i, $in, is_array($proof) && !is_array($proof['name'] ?? null) ? $proof : null);

            return $this->ok($to, "Thank you. We have your payment details ({$res['reference']}) and sent them to our team for approval. The payment is successful once they approve it; until then the invoice still shows as unpaid.");
        }, $to, $in);
    }

    /** The business's UPI QR picture, for signed-in clients while manual UPI is in use. @param array<string,string> $params */
    public function upiQr(Request $request, array $params = []): Response
    {
        return ManualUpi::qrResponse();
    }

    /** @param array<string,string> $params */
    public function quotes(Request $request, array $params = []): Response
    {
        return $this->page('my-billing/quotes', ['title' => 'My quotations', 'rows' => QuotationService::forClient($this->actorId())]);
    }

    /** @param array<string,string> $params */
    public function quote(Request $request, array $params = []): Response
    {
        $q = QuotationService::requireOwn($this->actorId(), $params['quote'] ?? '');
        $r = $this->page('my-billing/quote', ['title' => $q['reference'], 'q' => $q, 'items' => QuotationService::items($q), 'biz' => BusinessProfile::get()]);
        Session::forget('_old');

        return $r;
    }

    /** @param array<string,string> $params */
    public function quoteDecision(Request $request, array $params = []): Response
    {
        $uid = $this->actorId();
        $q = QuotationService::requireOwn($uid, $params['quote'] ?? '');
        $to = '/my/quotes/' . $q['public_id'];
        $decision = $request->string('decision');
        if ($request->string('confirm') !== '1') {
            return $this->back($to, ['Tick the box to confirm your decision.']);
        }

        return $this->guarded(function () use ($uid, $q, $to, $decision, $request): Response {
            QuotationService::clientDecision($uid, $q, $decision, $request->string('note'));

            return $this->ok($to, $decision === 'accept' ? 'Thank you. You accepted this quotation; our team has been told.' : 'You declined this quotation; our team has been told.');
        }, $to, ['note' => $request->string('note')]);
    }
}
