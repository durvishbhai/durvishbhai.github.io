<?php

declare(strict_types=1);

namespace Vertex\Http\Controllers\Billing;

use Vertex\Core\Database;
use Vertex\Core\HttpException;
use Vertex\Core\Request;
use Vertex\Core\Response;
use Vertex\Http\Controllers\Controller;
use Vertex\Services\ClientPaymentService;
use Vertex\Services\CreditNoteService;
use Vertex\Services\DocumentPdf;
use Vertex\Services\Gate;
use Vertex\Services\InvoiceService;
use Vertex\Services\QuotationService;
use Vertex\Services\ReceiptService;

/** Downloadable PDFs and printable receipts / credit notes, for staff and for the client's own documents. Same access rules as the pages they belong to. */
final class DocumentController extends Controller
{
    private function pdf(string $bytes, string $name): Response
    {
        return new Response(200, $bytes, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $name) . '.pdf"', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }

    /** @param array<string,string> $params */
    public function invoicePdf(Request $request, array $params = []): Response
    {
        $i = InvoiceService::requireStaff($this->actorId(), $params['invoice'] ?? '');

        return $this->pdf(DocumentPdf::invoice($i), 'invoice-' . ($i['reference'] ?? 'draft'));
    }

    /** @param array<string,string> $params */
    public function myInvoicePdf(Request $request, array $params = []): Response
    {
        $i = InvoiceService::requireOwn($this->actorId(), $params['invoice'] ?? '');

        return $this->pdf(DocumentPdf::invoice($i), 'invoice-' . $i['reference']);
    }

    /** @param array<string,string> $params */
    public function quotationPdf(Request $request, array $params = []): Response
    {
        $q = QuotationService::requireStaff($this->actorId(), $params['quote'] ?? '');

        return $this->pdf(DocumentPdf::quotation($q), 'quotation-' . $q['reference']);
    }

    /** @param array<string,string> $params */
    public function myQuotePdf(Request $request, array $params = []): Response
    {
        $q = QuotationService::requireOwn($this->actorId(), $params['quote'] ?? '');

        return $this->pdf(DocumentPdf::quotation($q), 'quotation-' . $q['reference']);
    }

    /** @return array{0:array<string,mixed>,1:?array<string,mixed>} */
    private function staffReceipt(string $publicId): array
    {
        $p = ClientPaymentService::requireView($this->actorId(), $publicId);
        if (!in_array($p['status'], ['verified', 'reversed'], true)) {
            throw new HttpException(404);
        }

        return [$p, $p['invoice_id'] !== null ? InvoiceService::find((string) $p['invoice_public_id']) : null];
    }

    /** @return array{0:array<string,mixed>,1:?array<string,mixed>} */
    private function ownReceipt(string $publicId): array
    {
        $uid = $this->actorId();
        $p = Gate::can('receipts.view_own', $uid) ? ClientPaymentService::find($publicId) : null;
        $owner = $p === null ? null : Database::one('SELECT user_id FROM clients WHERE id = ?', [$p['client_id']]);
        if ($p === null || $owner === null || (int) $owner['user_id'] !== $uid || !in_array($p['status'], ['verified', 'reversed'], true)) {
            throw new HttpException(404);
        }

        return [$p, $p['invoice_id'] !== null ? InvoiceService::find((string) $p['invoice_public_id']) : null];
    }

    /** @param array<string,string> $params */
    public function receipt(Request $request, array $params = []): Response
    {
        [$p, $i] = $this->staffReceipt($params['payment'] ?? '');

        $r = ReceiptService::issueForPayment((int) $p['id'], $this->actorId()) ?? throw new HttpException(404);

        return (new ReceiptController())->document($r, '/client-payments/' . $p['public_id'], '/client-payments/' . $p['public_id'] . '/receipt/pdf', true);
    }

    /** @param array<string,string> $params */
    public function receiptPdf(Request $request, array $params = []): Response
    {
        [$p, $i] = $this->staffReceipt($params['payment'] ?? '');

        return (new ReceiptController())->pdfResponse(ReceiptService::issueForPayment((int) $p['id'], $this->actorId()) ?? throw new HttpException(404));
    }

    /** @param array<string,string> $params */
    public function myReceipt(Request $request, array $params = []): Response
    {
        [$p, $i] = $this->ownReceipt($params['payment'] ?? '');

        $r = ReceiptService::issueForPayment((int) $p['id'], null) ?? throw new HttpException(404);

        return (new ReceiptController())->document($r, $i !== null ? '/my/invoices/' . $i['public_id'] : '/my/receipts', '/my/receipts/' . $p['public_id'] . '/pdf', false);
    }

    /** @param array<string,string> $params */
    public function myReceiptPdf(Request $request, array $params = []): Response
    {
        [$p, $i] = $this->ownReceipt($params['payment'] ?? '');

        return (new ReceiptController())->pdfResponse(ReceiptService::issueForPayment((int) $p['id'], null) ?? throw new HttpException(404));
    }

    /** @param array<string,string> $params */
    public function creditNote(Request $request, array $params = []): Response
    {
        $n = CreditNoteService::requireStaff($this->actorId(), $params['note'] ?? '');

        return $this->page('invoices/credit-note', ['title' => 'Credit note ' . $n['reference'], 'n' => $n, 'back' => '/invoices/' . $n['invoice_public_id'], 'pdf' => '/credit-notes/' . $n['public_id'] . '/pdf'], 'layouts/print');
    }

    /** @param array<string,string> $params */
    public function creditNotePdf(Request $request, array $params = []): Response
    {
        $n = CreditNoteService::requireStaff($this->actorId(), $params['note'] ?? '');

        return $this->pdf(DocumentPdf::creditNote($n), 'credit-note-' . $n['reference']);
    }

    /** @param array<string,string> $params */
    public function myCreditNote(Request $request, array $params = []): Response
    {
        $n = CreditNoteService::requireOwn($this->actorId(), $params['note'] ?? '');

        return $this->page('invoices/credit-note', ['title' => 'Credit note ' . $n['reference'], 'n' => $n, 'back' => '/my/invoices/' . $n['invoice_public_id'], 'pdf' => '/my/credit-notes/' . $n['public_id'] . '/pdf'], 'layouts/print');
    }

    /** @param array<string,string> $params */
    public function myCreditNotePdf(Request $request, array $params = []): Response
    {
        $n = CreditNoteService::requireOwn($this->actorId(), $params['note'] ?? '');

        return $this->pdf(DocumentPdf::creditNote($n), 'credit-note-' . $n['reference']);
    }
}
