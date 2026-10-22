<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Config;
use Vertex\Core\Database;
use Vertex\Core\HttpException;
use Vertex\Core\Logger;
use Vertex\Core\ValidationException;

/**
 * Online payments through Razorpay, asked for by the KIS team.
 *
 * A member of the team types an amount for an invoice; Razorpay is asked for a collection of exactly that amount and the QR shown is built only from
 * Razorpay's answer for that collection:
 *   1. a single-use, fixed-amount Razorpay UPI QR (POST /v1/payments/qr_codes) when Razorpay returns the UPI string for it, otherwise
 *   2. a Razorpay Payment Link for that exact amount (POST /v1/payment_links, partial payments off); we draw a QR that opens it, or
 *   3. Standard Web Checkout: the client presses "Pay" on their own page, we create a Razorpay Order for that exact amount (POST /v1/orders), checkout.js opens
 *      Razorpay's payment window, and the three values it returns are verified (HMAC-SHA256 of "order_id|payment_id" with the key secret) and then confirmed with Razorpay.
 *
 * What is guaranteed by this code:
 *  - Nothing is created before an amount is entered; the amount can never exceed what is still due; the browser supplies nothing after creation.
 *  - Razorpay's answer must repeat the amount we asked for (and, for a QR, "fixed amount" and "single use"), and a UPI string's am= must equal it, or the request is discarded.
 *  - A payment is recorded ONLY after the server asks Razorpay and finds a captured payment of exactly the order's amount. A webhook body is never believed;
 *    after its HMAC signature is checked it only tells us when to ask.
 *  - Every confirmation is idempotent: the same Razorpay payment can never create a second payment record.
 *
 * What it cannot guarantee: it was written from Razorpay's public API (Basic auth, QR Codes, Payment Links, webhooks signed with HMAC-SHA256) and tested with a
 * simulated Razorpay, NOT against the live or test Razorpay service, which needs your API keys. Run the checklist in docs/18 with test keys first.
 */
final class RazorpayGateway
{
    /** Razorpay's smallest amount: Rs 1.00. */
    public const MIN_MINOR = 100;

    public static function enabled(): bool
    {
        return (bool) Config::get('razorpay.enabled') && (string) Config::get('razorpay.key_id') !== '' && (string) Config::get('razorpay.key_secret') !== ''
            && str_starts_with((string) Config::get('app.url'), 'https://');
    }

    /** "test" or "live" from the key id (rzp_test_… / rzp_live_…). */
    public static function mode(): string
    {
        return str_starts_with((string) Config::get('razorpay.key_id'), 'rzp_live_') ? 'live' : 'test';
    }

    /** @return array{ready:bool,reason:string} */
    public static function status(): array
    {
        if (!(bool) Config::get('razorpay.enabled')) {
            return ['ready' => false, 'reason' => 'Razorpay is switched off.'];
        }
        if ((string) Config::get('razorpay.key_id') === '' || (string) Config::get('razorpay.key_secret') === '') {
            return ['ready' => false, 'reason' => 'The Razorpay Key ID and Key Secret have not been saved.'];
        }
        if (!str_starts_with((string) Config::get('app.url'), 'https://')) {
            return ['ready' => false, 'reason' => 'The site address (APP_URL) must start with https:// for online payments.'];
        }
        $note = self::mode() === 'test' ? 'Test mode: no real money moves. ' : '';
        if ((string) Config::get('razorpay.webhook_secret') === '') {
            return ['ready' => true, 'reason' => $note . 'No webhook secret is saved, so payments are noticed only when someone opens the request page or the scheduled check runs. Add the webhook for instant confirmation.'];
        }

        return ['ready' => true, 'reason' => trim($note)];
    }

    /**
     * @param array<string,mixed>|null $payload
     * @return array{status:int,data:array<string,mixed>}
     */
    private static function api(string $method, string $path, ?array $payload = null): array
    {
        $auth = base64_encode((string) Config::get('razorpay.key_id') . ':' . (string) Config::get('razorpay.key_secret'));
        $res = Http::request($method, rtrim((string) Config::get('razorpay.base_url', 'https://api.razorpay.com/v1'), '/') . $path,
            ['Authorization' => 'Basic ' . $auth, 'Content-Type' => 'application/json', 'Accept' => 'application/json'], $payload === null ? null : json_encode($payload, JSON_UNESCAPED_UNICODE), 25);
        $d = json_decode($res['body'], true);

        return ['status' => $res['status'], 'data' => is_array($d) ? $d : []];
    }

    // ------------------------------------------------------------------------------------------------ payment requests (staff)

    /** What can still be requested on an invoice: the balance minus payments the client has reported and staff have not verified yet. */
    public static function requestable(array $invoice): int
    {
        if (!in_array($invoice['status'], ['issued', 'partially_paid'], true)) {
            return 0;
        }
        $bal = InvoiceService::balance($invoice);

        return max(0, $bal['outstanding'] - $bal['pending']);
    }

    /**
     * @return array<string,mixed> the stored order row
     */
    public static function createRequest(int $actorId, array $invoice, int $amountMinor, string $note = '', string $method = ''): array
    {
        Gate::authorize('payments.request', $actorId);
        if (!in_array($invoice['status'], ['issued', 'partially_paid'], true)) {
            throw new ValidationException(['Only an issued invoice with a balance can be paid.']);
        }
        if (!self::enabled()) {
            throw new ValidationException(['Razorpay payments are not switched on (Administration -> Integrations & settings -> Razorpay).']);
        }
        $due = self::requestable($invoice);
        if ($amountMinor < self::MIN_MINOR) {
            throw new ValidationException(['The smallest amount Razorpay accepts is ₹1.00.']);
        }
        if ($amountMinor > $due) {
            throw new ValidationException(['The amount is more than what is still due on this invoice (₹' . FieldRules::formatMinor($due) . ').']);
        }
        $note = trim((string) preg_replace('/\s+/', ' ', $note));
        if (mb_strlen($note) > 200 || preg_match('/[<>]/', $note)) {
            throw new ValidationException(['The note must be at most 200 characters, without < or >.']);
        }
        if (!RateLimiter::hit('payment_request_user', (string) $actorId)) {
            throw new HttpException(429);
        }
        $mid = 'KIS' . $invoice['id'] . 'X' . strtoupper(bin2hex(random_bytes(6)));
        $ttl = max(15, (int) Config::get('razorpay.order_ttl_minutes', 30)); // Razorpay payment links must live at least 15 minutes
        $expiresAt = time() + $ttl * 60;
        $description = mb_substr('Invoice ' . $invoice['reference'] . ' - ' . (string) $invoice['title'], 0, 250);
        $notes = ['merchant_order_id' => $mid, 'invoice' => (string) $invoice['reference'], 'source' => 'staff-request'];
        $mode = (string) Config::get('razorpay.qr_mode', 'auto');
        if ($method === 'checkout' || ($method === '' && $mode === 'checkout')) {
            $mode = 'checkout'; // the client pays in Razorpay's checkout window on their own page; the Razorpay order is created when they press Pay
        } elseif ($method === 'qr' && $mode === 'checkout') {
            $mode = 'auto';
        }
        $flow = '';
        $payload = '';
        $rzpId = '';
        $imageUrl = null;

        if ($mode === 'auto') {
            try {
                $r = self::api('POST', '/payments/qr_codes', ['type' => 'upi_qr', 'name' => mb_substr(SiteText::company(), 0, 40), 'usage' => 'single_use', 'fixed_amount' => true, 'payment_amount' => $amountMinor,
                    'description' => $description, 'close_by' => $expiresAt, 'notes' => $notes]);
                $d = $r['data'];
                $id = (string) ($d['id'] ?? '');
                $upi = trim((string) ($d['image_content'] ?? ''));
                $exact = $r['status'] === 200 && str_starts_with($id, 'qr_') && (int) ($d['payment_amount'] ?? -1) === $amountMinor && ($d['fixed_amount'] ?? false) === true && ($d['usage'] ?? '') === 'single_use';
                if ($exact && $upi !== '' && preg_match('#^upi://\S+$#i', $upi) && mb_strlen($upi) <= 1900) {
                    self::assertAmountInUpi($upi, $amountMinor);
                    [$flow, $payload, $rzpId] = ['upi_qr', $upi, $id];
                    $imageUrl = self::https((string) ($d['image_url'] ?? ''));
                } elseif ($id !== '' && str_starts_with($id, 'qr_')) {
                    // Razorpay made a QR but gave us no UPI string to draw: close it so there is never a second open way to pay, then use a payment link
                    self::api('POST', '/payments/qr_codes/' . rawurlencode($id) . '/close');
                    Logger::error('Razorpay QR created without a usable UPI string; closed and falling back to a payment link', ['qr' => $id]);
                } else {
                    Logger::error('Razorpay QR not available', ['http' => $r['status'], 'code' => $d['error']['code'] ?? null]);
                }
            } catch (ValidationException $e) {
                throw $e;
            } catch (\Throwable $e) {
                Logger::error('Razorpay QR request failed', ['invoice' => (int) $invoice['id'], 'error' => $e->getMessage()]);
            }
        }
        if ($mode === 'checkout') {
            $flow = 'checkout';
        }
        if ($flow === '') {
            try {
                $r = self::api('POST', '/payment_links', ['amount' => $amountMinor, 'currency' => 'INR', 'accept_partial' => false, 'reference_id' => $mid, 'description' => $description, 'expire_by' => $expiresAt,
                    'notify' => ['sms' => false, 'email' => false], 'reminder_enable' => false, 'notes' => $notes,
                    'callback_url' => rtrim((string) Config::get('app.url'), '/') . '/my/invoices/' . $invoice['public_id'], 'callback_method' => 'get']);
            } catch (\Throwable $e) {
                Logger::error('Razorpay payment link request failed', ['invoice' => (int) $invoice['id'], 'error' => $e->getMessage()]);
                throw new ValidationException(['We could not reach Razorpay. Nothing was requested. Please try again.']);
            }
            $d = $r['data'];
            $id = (string) ($d['id'] ?? '');
            $url = (string) ($d['short_url'] ?? '');
            if ($r['status'] !== 200 || !str_starts_with($id, 'plink_') || !str_starts_with($url, 'https://') || mb_strlen($url) > 1900) {
                Logger::error('Razorpay payment link refused', ['http' => $r['status'], 'code' => $d['error']['code'] ?? null]);
                throw new ValidationException(['Razorpay did not accept the payment request (' . (string) ($d['error']['description'] ?? 'HTTP ' . $r['status']) . '). Nothing was requested.']);
            }
            if ((int) ($d['amount'] ?? -1) !== $amountMinor || ($d['accept_partial'] ?? false) === true) {
                self::api('POST', '/payment_links/' . rawurlencode($id) . '/cancel');
                Logger::security('Razorpay payment link differs from the request', ['requested' => $amountMinor, 'returned' => $d['amount'] ?? null]);
                throw new ValidationException(['Razorpay returned a payment link for a different amount than the one entered. It was cancelled and nothing was requested.']);
            }
            [$flow, $payload, $rzpId] = ['payment_link', $url, $id];
        }
        $pub = uuid_hex();
        Database::run("INSERT INTO gateway_orders (public_id, merchant_order_id, provider, flow, invoice_id, client_id, amount_minor, qr_payload, qr_image_url, note, expires_at, status, provider_order_id, created_by, created_at, updated_at)
            VALUES (?, ?, 'razorpay', ?, ?, ?, ?, ?, ?, ?, FROM_UNIXTIME(?), 'pending', ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())",
            [$pub, $mid, $flow, $invoice['id'], $invoice['client_id'], $amountMinor, $payload, $imageUrl, $note === '' ? null : $note, $expiresAt, $rzpId === '' ? null : $rzpId, $actorId]);
        $id = Database::insertId();
        Audit::record('gateway.request_created', 'invoice', (int) $invoice['id'], [], ['amount_minor' => $amountMinor, 'merchant_order_id' => $mid], ['provider' => 'razorpay', 'flow' => $flow], $actorId);
        $clientUser = Database::one('SELECT user_id FROM clients WHERE id = ?', [$invoice['client_id']]);
        if ($clientUser !== null && $clientUser['user_id'] !== null) {
            Notifier::send([(int) $clientUser['user_id']], 'payment.requested', 'Payment request: ₹' . FieldRules::formatMinor($amountMinor), 'A payment request for invoice ' . $invoice['reference'] . ' is waiting for you.', '/my/pay/' . $pub, null, 'gateway_order', $id, "pay-request:{$id}");
        }

        return Database::one('SELECT * FROM gateway_orders WHERE id = ?', [$id]);
    }

    private static function https(string $u): ?string
    {
        return str_starts_with($u, 'https://') && mb_strlen($u) <= 300 ? $u : null;
    }

    /** A UPI string carries its amount in "am=": when present it must be exactly what the team entered, or the QR is refused. */
    private static function assertAmountInUpi(string $upi, int $amountMinor): void
    {
        parse_str((string) parse_url($upi, PHP_URL_QUERY), $q);
        if (isset($q['am']) && !(preg_match('/^\d{1,9}(\.\d{1,2})?$/', (string) $q['am']) === 1 && (int) round(((float) $q['am']) * 100) === $amountMinor)) {
            Logger::security('Razorpay QR amount differs from the request', ['requested' => $amountMinor, 'in_qr' => (string) $q['am']]);
            throw new ValidationException(['Razorpay returned a QR for a different amount than the one entered. It was discarded and nothing was requested.']);
        }
    }

    /** @return array<string,mixed>|null */
    public static function findRequest(string $publicId): ?array
    {
        return preg_match('/^[a-f0-9]{32}$/', $publicId) ? Database::one("SELECT * FROM gateway_orders WHERE public_id = ? AND provider = 'razorpay'", [$publicId]) : null;
    }

    /** @param array<string,mixed> $o an order row @return bool the QR may still be shown and paid */
    public static function isLive(array $o): bool
    {
        return $o['status'] === 'pending' && $o['expires_at'] !== null && strtotime((string) $o['expires_at'] . ' UTC') > time();
    }

    /** @return list<array<string,mixed>> requests that can still be paid for one invoice, newest first */
    public static function liveRequests(int $invoiceId): array
    {
        return Database::all("SELECT * FROM gateway_orders WHERE invoice_id = ? AND provider = 'razorpay' AND status = 'pending' AND expires_at > UTC_TIMESTAMP() ORDER BY id DESC", [$invoiceId]);
    }

    /** The team withdraws a request: the QR disappears and Razorpay is asked to close it. A payment already made is still recorded when Razorpay confirms it. @param array<string,mixed> $order */
    public static function cancelRequest(int $actorId, array $order): void
    {
        Gate::authorize('payments.request', $actorId);
        if (!in_array($order['status'], ['pending', 'created'], true)) {
            throw new ValidationException(['This request is no longer open.']);
        }
        Database::run("UPDATE gateway_orders SET status = 'cancelled', cancelled_by = ?, cancelled_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE id = ? AND status IN ('pending','created')", [$actorId, $order['id']]);
        Audit::record('gateway.request_cancelled', 'gateway_order', (int) $order['id'], ['status' => $order['status']], ['status' => 'cancelled'], ['merchant_order_id' => $order['merchant_order_id']], $actorId);
        $rid = (string) ($order['provider_order_id'] ?? '');
        if ($rid !== '' && !str_starts_with($rid, 'order_') && self::enabled()) { // an Order cannot be cancelled at Razorpay (it simply expires unpaid); links and QR codes are closed. Best effort: if Razorpay cannot be reached the QR is still hidden here and a late payment is still recorded
            try {
                self::api('POST', (str_starts_with($rid, 'plink_') ? '/payment_links/' : '/payments/qr_codes/') . rawurlencode($rid) . (str_starts_with($rid, 'plink_') ? '/cancel' : '/close'));
            } catch (\Throwable $e) {
                Logger::error('Razorpay could not be asked to close a withdrawn request', ['order' => $order['merchant_order_id'], 'error' => $e->getMessage()]);
            }
        }
    }

    // ------------------------------------------------------------------------------------------------ confirming payments

    /**
     * Asks Razorpay about one of OUR orders and records the payment when (and only when) a captured payment of exactly the order's amount exists.
     *
     * @return array{state:string,recorded:bool}
     */
    public static function reconcile(string $merchantOrderId): array
    {
        $order = Database::one("SELECT * FROM gateway_orders WHERE merchant_order_id = ? AND provider = 'razorpay'", [$merchantOrderId]);
        if ($order === null) {
            throw new HttpException(404);
        }
        if ($order['status'] === 'completed') {
            return ['state' => 'COMPLETED', 'recorded' => false];
        }
        $rid = (string) ($order['provider_order_id'] ?? '');
        if ($rid === '') {
            return ['state' => 'PENDING', 'recorded' => false]; // checkout request: the client has not pressed Pay yet, so there is no Razorpay order to ask about
        }
        $isLink = str_starts_with($rid, 'plink_');
        $r = self::api('GET', $isLink ? '/payment_links/' . rawurlencode($rid) : (str_starts_with($rid, 'order_') ? '/orders/' . rawurlencode($rid) . '/payments' : '/payments/qr_codes/' . rawurlencode($rid) . '/payments?count=50'));
        if ($r['status'] !== 200) {
            throw new \RuntimeException('Razorpay status check answered HTTP ' . $r['status']);
        }
        Database::run('UPDATE gateway_orders SET last_checked_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE id = ?', [$order['id']]);
        $want = (int) $order['amount_minor'];
        $txn = '';
        $other = null;
        if ($isLink) {
            $d = $r['data'];
            if (($d['status'] ?? '') === 'paid' || ($d['status'] ?? '') === 'partially_paid') {
                foreach ((array) ($d['payments'] ?? []) as $p) {
                    if (is_array($p) && (int) ($p['amount'] ?? 0) === $want && in_array((string) ($p['status'] ?? 'captured'), ['captured', 'paid'], true)) {
                        $txn = (string) ($p['payment_id'] ?? $p['id'] ?? '');
                    } elseif (is_array($p)) {
                        $other = (int) ($p['amount'] ?? 0);
                    }
                }
                if ($txn === '' && (int) ($d['amount_paid'] ?? 0) === $want && ($d['status'] ?? '') === 'paid') {
                    $txn = $rid; // Razorpay says the whole amount was paid but did not list the payment in this answer
                }
            }
            $closed = in_array((string) ($d['status'] ?? ''), ['expired', 'cancelled'], true);
        } else {
            foreach ((array) ($r['data']['items'] ?? []) as $p) {
                if (!is_array($p) || !in_array((string) ($p['status'] ?? ''), ['captured', 'authorized'], true)) {
                    continue;
                }
                if ((int) ($p['amount'] ?? 0) !== $want) {
                    $other = (int) ($p['amount'] ?? 0);
                } elseif (($p['status'] ?? '') === 'captured') {
                    $txn = (string) ($p['id'] ?? '');
                } // an authorized (not yet captured) payment of the right amount is simply still pending
            }
            $closed = false;
        }
        if ($txn === '') {
            if ($other !== null && $other !== 0) {
                Logger::security('Razorpay amount mismatch', ['order' => $merchantOrderId, 'expected' => $want, 'reported' => $other]);
                Audit::record('gateway.amount_mismatch', 'gateway_order', (int) $order['id'], [], ['expected_minor' => $want, 'reported_minor' => $other], [], null);
                throw new \RuntimeException('Razorpay reported a different amount than the order. A person must check this order.');
            }
            if ($order['status'] === 'pending' && ($closed || strtotime(($order['expires_at'] ?? $order['created_at']) . ' UTC') < time() - 60)) {
                Database::run("UPDATE gateway_orders SET status = 'expired', updated_at = UTC_TIMESTAMP() WHERE id = ? AND status = 'pending'", [$order['id']]);

                return ['state' => 'EXPIRED', 'recorded' => false];
            }

            return ['state' => 'PENDING', 'recorded' => false];
        }
        $recorded = self::recordCompleted($order, $txn);

        return ['state' => 'COMPLETED', 'recorded' => $recorded];
    }

    /** Records the verified payment for an order exactly once. @param array<string,mixed> $order */
    private static function recordCompleted(array $order, string $txn): bool
    {
        $recorded = false;
        Database::transaction(function () use ($order, $txn, &$recorded): void {
            $locked = Database::one('SELECT * FROM gateway_orders WHERE id = ? FOR UPDATE', [$order['id']]);
            if ($locked['status'] === 'completed') {
                return;
            }
            $invoice = InvoiceService::find((string) Database::one('SELECT public_id FROM invoices WHERE id = ?', [$order['invoice_id']])['public_id']);
            $p = ClientPaymentService::recordGateway($invoice, (int) $order['amount_minor'], 'Razorpay ' . $txn, 'Confirmed by Razorpay (order ' . $order['merchant_order_id'] . ')');
            Database::run("UPDATE gateway_orders SET status = 'completed', provider_txn_id = ?, payment_id = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?", [mb_substr($txn, 0, 80), $p['id'], $order['id']]);
            $recorded = $p['created'];
        });

        return $recorded;
    }

    // ------------------------------------------------------------------------------------------------ Standard Web Checkout (client pays in Razorpay's window)

    /**
     * Step 1: "create order". Creates (once) the Razorpay Order for the exact amount of one of OUR requests and returns what checkout.js needs. The browser supplies
     * no amount: it comes from the request the team made. The key SECRET never leaves the server; only the public key id is returned.
     *
     * @param array<string,mixed> $order @return array<string,mixed>
     * @throws \RuntimeException code 400 (not payable), 401 (Razorpay refused our keys), 500 (anything else)
     */
    public static function createCheckoutOrder(array $order, array $clientHint = []): array
    {
        if (!self::enabled()) {
            throw new \RuntimeException('Online payment is not available right now.', 500);
        }
        if ($order['flow'] !== 'checkout' || !self::isLive($order)) {
            throw new \RuntimeException('This payment request is not open any more. Please ask the team for a new one.', 400);
        }
        $amount = (int) $order['amount_minor'];
        if ($amount < self::MIN_MINOR) {
            throw new \RuntimeException('The amount is below Razorpay\'s minimum of ₹1.00.', 400);
        }
        if ((string) ($order['provider_order_id'] ?? '') === '') {
            try {
                $r = self::api('POST', '/orders', ['amount' => $amount, 'currency' => 'INR', 'receipt' => (string) $order['merchant_order_id'], 'notes' => ['merchant_order_id' => (string) $order['merchant_order_id'], 'source' => 'staff-request']]);
            } catch (\Throwable $e) {
                Logger::error('Razorpay order request failed', ['order' => $order['merchant_order_id'], 'error' => $e->getMessage()]);
                throw new \RuntimeException('We could not reach Razorpay. Nothing was charged. Please try again.', 500);
            }
            if ($r['status'] === 401) {
                Logger::error('Razorpay refused the API keys');
                throw new \RuntimeException('Online payment is not set up correctly (Razorpay refused the API keys). Please tell the team.', 401);
            }
            $id = (string) ($r['data']['id'] ?? '');
            if ($r['status'] !== 200 || !str_starts_with($id, 'order_')) {
                Logger::error('Razorpay order refused', ['http' => $r['status'], 'code' => $r['data']['error']['code'] ?? null]);
                throw new \RuntimeException('Razorpay did not accept the payment request. Nothing was charged.', 500);
            }
            if ((int) ($r['data']['amount'] ?? -1) !== $amount || ($r['data']['currency'] ?? '') !== 'INR') {
                Logger::security('Razorpay order differs from the request', ['requested' => $amount, 'returned' => $r['data']['amount'] ?? null]);
                throw new \RuntimeException('Razorpay returned an order for a different amount. Nothing was charged.', 500);
            }
            Database::run('UPDATE gateway_orders SET provider_order_id = ?, updated_at = UTC_TIMESTAMP() WHERE id = ? AND (provider_order_id IS NULL OR provider_order_id = \'\')', [$id, $order['id']]);
            $order['provider_order_id'] = (string) Database::one('SELECT provider_order_id FROM gateway_orders WHERE id = ?', [$order['id']])['provider_order_id'];
        }
        $inv = Database::one('SELECT reference, title FROM invoices WHERE id = ?', [$order['invoice_id']]);

        return ['order_id' => (string) $order['provider_order_id'], 'amount' => $amount, 'currency' => 'INR', 'key_id' => (string) Config::get('razorpay.key_id'), 'name' => SiteText::company(),
            'description' => mb_substr('Invoice ' . (string) ($inv['reference'] ?? '') . ' - ' . (string) ($inv['title'] ?? ''), 0, 250), 'prefill' => array_intersect_key($clientHint, ['name' => 1, 'email' => 1, 'contact' => 1])];
    }

    /**
     * Step 3: verify what checkout.js returned. The signature is HMAC-SHA256("order_id|payment_id", key secret); a mismatch changes nothing. A matching signature is
     * then confirmed with Razorpay itself (the payment must belong to this order, be in INR and for exactly the amount; an authorized payment is captured) before the
     * invoice payment is recorded.
     *
     * @param array<string,mixed> $order @return array{ok:bool,status:int,message:string,recorded:bool}
     */
    public static function verifyCheckout(array $order, string $paymentId, string $rzpOrderId, string $signature): array
    {
        if (!preg_match('/^pay_[A-Za-z0-9]{6,40}$/', $paymentId) || !preg_match('/^order_[A-Za-z0-9]{6,40}$/', $rzpOrderId) || !preg_match('/^[a-f0-9]{64}$/i', $signature)) {
            return ['ok' => false, 'status' => 400, 'message' => 'The payment details are incomplete or malformed.', 'recorded' => false];
        }
        $expected = hash_hmac('sha256', $rzpOrderId . '|' . $paymentId, (string) Config::get('razorpay.key_secret'));
        if ((string) Config::get('razorpay.key_secret') === '' || !hash_equals($expected, strtolower($signature)) || $rzpOrderId !== (string) ($order['provider_order_id'] ?? '')) {
            Logger::security('Razorpay checkout signature mismatch', ['order' => $order['merchant_order_id'] ?? null]);

            return ['ok' => false, 'status' => 400, 'message' => 'The payment could not be verified, so nothing was recorded. If money was taken it will be matched to the invoice after Razorpay confirms it.', 'recorded' => false];
        }
        $r = self::api('GET', '/payments/' . rawurlencode($paymentId));
        $p = $r['data'];
        if ($r['status'] !== 200 || ($p['order_id'] ?? '') !== $rzpOrderId || (int) ($p['amount'] ?? -1) !== (int) $order['amount_minor'] || ($p['currency'] ?? '') !== 'INR') {
            Logger::security('Razorpay checkout payment does not match the order', ['order' => $order['merchant_order_id'], 'http' => $r['status']]);

            return ['ok' => false, 'status' => 400, 'message' => 'Razorpay did not confirm a payment of the exact amount for this request, so nothing was recorded.', 'recorded' => false];
        }
        if (($p['status'] ?? '') === 'authorized') {
            $c = self::api('POST', '/payments/' . rawurlencode($paymentId) . '/capture', ['amount' => (int) $order['amount_minor'], 'currency' => 'INR']);
            $p['status'] = $c['status'] === 200 ? (string) ($c['data']['status'] ?? 'captured') : 'authorized';
        }
        if (($p['status'] ?? '') !== 'captured') {
            return ['ok' => false, 'status' => 202, 'message' => 'Razorpay has not captured the payment yet. This page will update once it does; there is no need to pay again.', 'recorded' => false];
        }

        return ['ok' => true, 'status' => 200, 'message' => 'Payment received. Thank you.', 'recorded' => self::recordCompleted($order, $paymentId)];
    }

    /**
     * Handles Razorpay's webhook. The signature is HMAC-SHA256 of the raw body with the webhook secret (header X-Razorpay-Signature). The body is never believed:
     * after the signature check it only names the order to ask Razorpay about.
     *
     * @return array{status:int,outcome:string}
     */
    public static function webhook(string $signature, string $body, string $eventId = ''): array
    {
        $secret = (string) Config::get('razorpay.webhook_secret');
        if ($secret === '') {
            return ['status' => 401, 'outcome' => 'not_configured']; // same answer as a wrong signature: do not reveal the setup state
        }
        if (!hash_equals(hash_hmac('sha256', $body, $secret), strtolower(trim($signature)))) {
            Logger::security('Razorpay webhook rejected: bad signature');

            return ['status' => 401, 'outcome' => 'unauthorized'];
        }
        $d = json_decode($body, true);
        $payload = is_array($d['payload'] ?? null) ? $d['payload'] : [];
        $event = mb_substr((string) ($d['event'] ?? ''), 0, 60);
        $rid = (string) ($payload['qr_code']['entity']['id'] ?? $payload['payment_link']['entity']['id'] ?? $payload['payment']['entity']['order_id'] ?? '');
        $mid = (string) ($payload['payment']['entity']['notes']['merchant_order_id'] ?? $payload['qr_code']['entity']['notes']['merchant_order_id'] ?? $payload['payment_link']['entity']['notes']['merchant_order_id'] ?? '');
        if (!preg_match('/^KIS\d+X[A-F0-9]{12}$/', $mid) && preg_match('/^(qr|plink|order)_[A-Za-z0-9]{6,40}$/', $rid)) {
            $o = Database::one("SELECT merchant_order_id FROM gateway_orders WHERE provider = 'razorpay' AND provider_order_id = ?", [$rid]);
            $mid = (string) ($o['merchant_order_id'] ?? '');
        }
        $key = hash('sha256', $eventId !== '' ? 'rzp:' . $eventId : $body);
        $dup = Database::run('INSERT IGNORE INTO gateway_webhook_events (provider, event_key, merchant_order_id, event, received_at) VALUES (\'razorpay\', ?, ?, ?, UTC_TIMESTAMP())', [$key, $mid === '' ? null : $mid, $event])->rowCount() === 0;
        if (!preg_match('/^KIS\d+X[A-F0-9]{12}$/', $mid)) {
            return ['status' => 200, 'outcome' => 'ignored'];
        }
        try {
            $r = self::reconcile($mid);
            Database::run('UPDATE gateway_webhook_events SET processed_at = UTC_TIMESTAMP(), outcome = ? WHERE event_key = ?', [mb_substr($r['state'] . ($dup ? ' (repeat)' : ''), 0, 60), $key]);

            return ['status' => 200, 'outcome' => strtolower($r['state'])];
        } catch (HttpException) {
            return ['status' => 200, 'outcome' => 'unknown_order'];
        } catch (\Throwable $e) {
            Logger::error('Razorpay webhook processing failed', ['order' => $mid, 'error' => $e->getMessage()]);

            return ['status' => 500, 'outcome' => 'retry']; // Razorpay retries on a non-2xx answer
        }
    }

    /** Orders that are still pending and old enough to be worth asking about (the scheduler calls this). @return int payments recorded */
    public static function reconcilePending(): int
    {
        if (!self::enabled()) {
            return 0;
        }
        $n = 0;
        foreach (Database::all("SELECT merchant_order_id FROM gateway_orders WHERE provider = 'razorpay' AND status = 'pending' AND created_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 2 DAY) AND created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 3 MINUTE) ORDER BY id LIMIT 50") as $o) {
            try {
                $n += self::reconcile((string) $o['merchant_order_id'])['recorded'] ? 1 : 0;
            } catch (\Throwable $e) {
                Logger::error('Razorpay reconcile failed', ['order' => $o['merchant_order_id'], 'error' => $e->getMessage()]);
            }
        }

        return $n;
    }
}
