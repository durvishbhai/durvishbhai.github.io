<?php

declare(strict_types=1);

namespace Vertex\Http\Controllers\Billing;

use Vertex\Core\Database;
use Vertex\Core\HttpException;
use Vertex\Core\Request;
use Vertex\Core\Response;
use Vertex\Http\Controllers\Controller;
use Vertex\Services\InvoiceService;
use Vertex\Services\PhonePeGateway;

/** PhonePe: start (client), return page (client), webhook (PhonePe, signature-checked, no session, no CSRF). */
final class PhonePeController extends Controller
{
    /** @param array<string,string> $params */
    public function start(Request $request, array $params = []): Response
    {
        $uid = $this->actorId();
        $i = InvoiceService::requireOwn($uid, $params['invoice'] ?? '');
        $to = '/my/invoices/' . $i['public_id'];

        return $this->guarded(fn (): Response => Response::redirect(PhonePeGateway::start($uid, $i)), $to);
    }

    /** Where PhonePe sends the customer back. Nothing in the address is believed: the server asks PhonePe. @param array<string,string> $params */
    public function returned(Request $request, array $params = []): Response
    {
        $uid = $this->actorId();
        $i = InvoiceService::requireOwn($uid, $params['invoice'] ?? '');
        $to = '/my/invoices/' . $i['public_id'];
        $mid = $request->string('o');
        $order = preg_match('/^(KIS|VIS)\d+X[A-F0-9]{12}$/', $mid) ? Database::one('SELECT * FROM gateway_orders WHERE merchant_order_id = ? AND invoice_id = ?', [$mid, $i['id']]) : null;
        if ($order === null) {
            throw new HttpException(404);
        }
        try {
            $r = PhonePeGateway::reconcile($mid);
        } catch (\Throwable) {
            return $this->ok($to, 'We could not confirm the payment with PhonePe just now. If money was taken it will appear on this invoice shortly; there is no need to pay again.');
        }

        return match ($r['state']) {
            'COMPLETED' => $this->ok($to, 'Payment received. Thank you.'),
            'FAILED' => $this->back($to, ['The payment did not go through and nothing was charged. You can try again.']),
            default => $this->ok($to, 'Your payment is still being confirmed by PhonePe. This page will show it as soon as it arrives; there is no need to pay again.'),
        };
    }

    /** @param array<string,string> $params */
    public function webhook(Request $request, array $params = []): Response
    {
        $raw = (string) ($request->server['VERTEX_RAW_BODY'] ?? '');
        if ($raw === '') {
            $raw = (string) file_get_contents('php://input');
        }
        $r = PhonePeGateway::webhook((string) ($request->server['HTTP_AUTHORIZATION'] ?? $request->server['REDIRECT_HTTP_AUTHORIZATION'] ?? ''), mb_substr($raw, 0, 65536));

        return new Response($r['status'], json_encode(['ok' => $r['status'] === 200]), ['Content-Type' => 'application/json; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }
}
