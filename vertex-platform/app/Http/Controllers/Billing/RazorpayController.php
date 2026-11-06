<?php

declare(strict_types=1);

namespace Vertex\Http\Controllers\Billing;

use Vertex\Core\Request;
use Vertex\Core\Response;
use Vertex\Http\Controllers\Controller;
use Vertex\Services\RazorpayGateway;

/** Razorpay's webhook: signature-checked (HMAC-SHA256 of the raw body), no session, no CSRF. Payments are requested by the team: see PaymentRequestController. */
final class RazorpayController extends Controller
{
    /** @param array<string,string> $params */
    public function webhook(Request $request, array $params = []): Response
    {
        $raw = (string) ($request->server['VERTEX_RAW_BODY'] ?? '');
        if ($raw === '') {
            $raw = (string) file_get_contents('php://input');
        }
        $r = RazorpayGateway::webhook((string) ($request->server['HTTP_X_RAZORPAY_SIGNATURE'] ?? ''), mb_substr($raw, 0, 65536), (string) ($request->server['HTTP_X_RAZORPAY_EVENT_ID'] ?? ''));

        return new Response($r['status'], json_encode(['ok' => $r['status'] === 200]), ['Content-Type' => 'application/json; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }
}
