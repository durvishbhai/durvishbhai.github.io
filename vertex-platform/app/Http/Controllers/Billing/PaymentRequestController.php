<?php

declare(strict_types=1);

namespace Vertex\Http\Controllers\Billing;

use Vertex\Core\Database;
use Vertex\Core\HttpException;
use Vertex\Core\Request;
use Vertex\Core\Response;
use Vertex\Http\Controllers\Controller;
use Vertex\Services\FieldRules;
use Vertex\Services\InvoiceService;
use Vertex\Services\RazorpayGateway;
use Vertex\Services\QrCode;
use Vertex\Services\RateLimiter;
use Vertex\Core\SecurityHeadersPolicy;

/**
 * Payment requests (Razorpay) made by the KIS team (permission payments.request): enter the amount for an invoice, get one QR for exactly that amount, watch it
 * get paid. The client sees the same QR on their own page; nobody but the team can create or change a request.
 */
final class PaymentRequestController extends Controller
{
    /** @param array<string,string> $params */
    public function index(Request $request, array $params = []): Response
    {
        $rows = Database::all("SELECT o.*, i.reference AS invoice_ref, i.public_id AS invoice_pub, COALESCE(NULLIF(c.company_name, ''), c.contact_name) AS client_name, u.name AS by_name FROM gateway_orders o
            JOIN invoices i ON i.id = o.invoice_id JOIN clients c ON c.id = o.client_id LEFT JOIN users u ON u.id = o.created_by WHERE o.provider = 'razorpay' ORDER BY o.id DESC LIMIT 100");

        return $this->page('billing/payments/index', ['title' => 'Payment requests', 'rows' => $rows, 'ready' => RazorpayGateway::status()]);
    }

    /** @param array<string,string> $params */
    public function create(Request $request, array $params = []): Response
    {
        $i = InvoiceService::requireStaff($this->actorId(), $params['invoice'] ?? '');
        $r = $this->page('billing/payments/new', ['title' => 'Request a payment', 'i' => $i, 'due' => RazorpayGateway::requestable($i), 'ready' => RazorpayGateway::status(), 'live' => RazorpayGateway::liveRequests((int) $i['id'])]);
        \Vertex\Core\Session::forget('_old');

        return $r;
    }

    /** @param array<string,string> $params */
    public function store(Request $request, array $params = []): Response
    {
        $uid = $this->actorId();
        $i = InvoiceService::requireStaff($uid, $params['invoice'] ?? '');
        $back = '/invoices/' . $i['public_id'] . '/pay-request';

        return $this->guarded(function () use ($request, $uid, $i): Response {
            $amount = FieldRules::moneyMinor($request->input('amount'), 'Amount', true);
            $o = RazorpayGateway::createRequest($uid, $i, (int) $amount, $request->string('note'), $request->string('method'));

            return Response::redirect('/payment-requests/' . $o['public_id']);
        }, $back, ['amount' => $request->string('amount'), 'note' => $request->string('note'), 'method' => $request->string('method')]);
    }

    /** @param array<string,string> $params */
    public function show(Request $request, array $params = []): Response
    {
        $o = RazorpayGateway::findRequest($params['order'] ?? '') ?? throw new HttpException(404);

        return $this->page('billing/payments/show', self::view($o, false));
    }

    /** @param array<string,string> $params */
    public function status(Request $request, array $params = []): Response
    {
        $o = RazorpayGateway::findRequest($params['order'] ?? '') ?? throw new HttpException(404);

        return Response::json(self::poll($o));
    }

    /** @param array<string,string> $params */
    public function cancel(Request $request, array $params = []): Response
    {
        $o = RazorpayGateway::findRequest($params['order'] ?? '') ?? throw new HttpException(404);
        $to = '/payment-requests/' . $o['public_id'];

        return $this->guarded(function () use ($o, $to): Response {
            RazorpayGateway::cancelRequest($this->actorId(), $o);

            return $this->ok($to, 'The request was withdrawn and its QR is no longer shown. If the customer had already paid, the payment is still recorded when Razorpay confirms it.');
        }, $to);
    }

    // ------------------------------------------------------------------------------------------------ the client's own view

    /** @param array<string,string> $params */
    public function mine(Request $request, array $params = []): Response
    {
        $o = $this->ownOrder($params['order'] ?? '');

        $r = $this->page('billing/payments/show', self::view($o, true));
        if ($o['flow'] === 'checkout' && RazorpayGateway::isLive($o)) {
            foreach (SecurityHeadersPolicy::checkout() as $name => $value) {
                $r->headers[$name] = $value;
            }
        }

        return $r;
    }

    /** @param array<string,string> $params */
    public function mineStatus(Request $request, array $params = []): Response
    {
        return Response::json(self::poll($this->ownOrder($params['order'] ?? '')));
    }

    /**
     * Razorpay Standard Web Checkout, step 1 (the "create order" endpoint). Called by app.js when the client presses Pay. The browser sends no amount: the order is
     * made for the exact amount the team entered. Answers {order_id, amount, currency, key_id, ...} (key id only, never the secret); errors are JSON with a
     * plain message: 400 not payable, 401 Razorpay refused our keys, 429 too many tries, 500 anything else.
     *
     * @param array<string,string> $params
     */
    public function checkout(Request $request, array $params = []): Response
    {
        $o = $this->ownOrder($params['order'] ?? '');
        if (!RateLimiter::hit('checkout_order_user', (string) $this->actorId())) {
            return Response::json(['error' => 'Too many attempts. Please wait a little and try again.'], 429);
        }
        $u = Database::one('SELECT name, email FROM users WHERE id = ?', [$this->actorId()]) ?? [];
        try {
            return Response::json(RazorpayGateway::createCheckoutOrder($o, ['name' => (string) ($u['name'] ?? ''), 'email' => (string) ($u['email'] ?? '')]));
        } catch (\RuntimeException $e) {
            return Response::json(['error' => $e->getMessage()], in_array($e->getCode(), [400, 401], true) ? $e->getCode() : 500);
        }
    }

    /**
     * Razorpay Standard Web Checkout, step 3 (the "verify" endpoint): razorpay_payment_id, razorpay_order_id and razorpay_signature as returned by checkout.js.
     * Missing field or a signature that does not match -> 400 and nothing is recorded as paid.
     *
     * @param array<string,string> $params
     */
    public function verify(Request $request, array $params = []): Response
    {
        $o = $this->ownOrder($params['order'] ?? '');
        $pay = $request->string('razorpay_payment_id');
        $ord = $request->string('razorpay_order_id');
        $sig = $request->string('razorpay_signature');
        if ($pay === '' || $ord === '' || $sig === '') {
            return Response::json(['success' => false, 'error' => 'razorpay_payment_id, razorpay_order_id and razorpay_signature are all required.'], 400);
        }
        try {
            $r = RazorpayGateway::verifyCheckout($o, $pay, $ord, $sig);
        } catch (\Throwable) {
            return Response::json(['success' => false, 'error' => 'We could not confirm the payment with Razorpay right now. If money was taken it will be matched to the invoice automatically.'], 500);
        }

        return Response::json($r['ok'] ? ['success' => true, 'message' => $r['message'], 'recorded' => $r['recorded']] : ['success' => false, 'error' => $r['message']], $r['ok'] ? 200 : $r['status']);
    }

    /** @return array<string,mixed> the order, only when it is for the signed-in client */
    private function ownOrder(string $publicId): array
    {
        $o = RazorpayGateway::findRequest($publicId) ?? throw new HttpException(404);
        $i = InvoiceService::requireOwn($this->actorId(), (string) Database::one('SELECT public_id FROM invoices WHERE id = ?', [$o['invoice_id']])['public_id']);
        if ((int) $i['id'] !== (int) $o['invoice_id']) {
            throw new HttpException(404);
        }

        return $o;
    }

    /** @param array<string,mixed> $o @return array<string,mixed> */
    private static function view(array $o, bool $mine): array
    {
        $inv = Database::one("SELECT i.public_id, i.reference, i.title, COALESCE(NULLIF(c.company_name, ''), c.contact_name) AS client_name FROM invoices i JOIN clients c ON c.id = i.client_id WHERE i.id = ?", [$o['invoice_id']]);
        $live = RazorpayGateway::isLive($o);

        return ['title' => 'Payment request', 'o' => $o, 'inv' => $inv, 'mine' => $mine, 'live' => $live, 'svg' => $live && $o['flow'] !== 'checkout' ? QrCode::svg((string) $o['qr_payload'], 'QR code to pay ₹' . FieldRules::formatMinor((int) $o['amount_minor'])) : '',
            'statusUrl' => ($mine ? '/my/pay/' : '/payment-requests/') . $o['public_id'] . '/status', 'expiresAt' => (int) strtotime((string) $o['expires_at'] . ' UTC'), 'invoiceUrl' => ($mine ? '/my/invoices/' : '/invoices/') . $inv['public_id']];
    }

    /**
     * Asks Razorpay (at most every few seconds) whether the order has been paid, and says where it stands. The browser never decides: a payment is recorded only
     * by RazorpayGateway::reconcile() after Razorpay's own answer.
     *
     * @param array<string,mixed> $o @return array<string,mixed>
     */
    private static function poll(array $o): array
    {
        $recorded = false;
        if (in_array($o['status'], ['pending', 'cancelled', 'expired'], true) && ($o['last_checked_at'] === null || strtotime((string) $o['last_checked_at'] . ' UTC') < time() - 3)) {
            try {
                $recorded = RazorpayGateway::reconcile((string) $o['merchant_order_id'])['recorded'];
            } catch (\Throwable) {
                // Razorpay could not be asked right now; the page simply tries again
            }
            $o = Database::one('SELECT * FROM gateway_orders WHERE id = ?', [$o['id']]) ?? $o;
        }

        return ['status' => (string) $o['status'], 'live' => RazorpayGateway::isLive($o), 'paid' => $o['status'] === 'completed', 'recorded' => $recorded];
    }
}
