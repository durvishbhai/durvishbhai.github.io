<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Config;
use Vertex\Core\Database;
use Vertex\Core\HttpException;
use Vertex\Core\Logger;
use Vertex\Core\ValidationException;

/**
 * Module 21: PhonePe Standard Checkout for invoice payments, with webhooks.
 *
 * What is guaranteed by this code:
 *  - A payment is recorded ONLY after the server asks PhonePe's order-status API and it says COMPLETED for the amount we asked for. Neither the browser's return
 *    to our site nor the webhook body is trusted on its own: the webhook merely tells us when to ask.
 *  - The webhook must carry the Authorization header PhonePe computes (SHA-256 of "username:password" from the merchant dashboard), compared in constant time.
 *  - Every confirmation is idempotent: the same PhonePe transaction (or a replayed/duplicate webhook) can never create a second payment.
 *  - Amounts are integer paise; the invoice, not the browser, decides how much may be paid.
 *
 * What it cannot guarantee: it was written against PhonePe's published Standard Checkout API and tested with a simulated PhonePe (tests/), NOT against the
 * live or sandbox PhonePe service, because that needs merchant credentials. Run the sandbox checklist in docs/18 before taking real money.
 */
final class PhonePeGateway
{
    public static function enabled(): bool
    {
        return (bool) Config::get('phonepe.enabled') && (string) Config::get('phonepe.client_id') !== '' && (string) Config::get('phonepe.client_secret') !== ''
            && str_starts_with((string) Config::get('app.url'), 'https://');
    }

    /** @return array{ready:bool,reason:string} */
    public static function status(): array
    {
        $why = !(bool) Config::get('phonepe.enabled') ? 'PhonePe is switched off.' : ((string) Config::get('phonepe.client_id') === '' || (string) Config::get('phonepe.client_secret') === '' ? 'The client ID or secret is missing.'
            : (!str_starts_with((string) Config::get('app.url'), 'https://') ? 'The site address (APP_URL) must start with https:// for PhonePe.' : ((string) Config::get('phonepe.webhook_username') === '' || (string) Config::get('phonepe.webhook_password') === '' ? 'Ready to take payments; add the webhook username and password so confirmations arrive even if the customer closes the page.' : '')));
        $blocking = !(bool) Config::get('phonepe.enabled') || (string) Config::get('phonepe.client_id') === '' || (string) Config::get('phonepe.client_secret') === '' || !str_starts_with((string) Config::get('app.url'), 'https://');

        return ['ready' => !$blocking, 'reason' => $why];
    }

    /** @return array{auth:string,api:string} */
    private static function urls(): array
    {
        $env = (string) Config::get('phonepe.environment', 'sandbox') === 'production' ? 'production' : 'sandbox';

        return (array) Config::get("phonepe.urls.{$env}");
    }

    private static function token(): string
    {
        $cache = (string) Config::get('uploads.root', VERTEX_ROOT . '/storage/uploads');
        $file = dirname($cache) . '/cache/phonepe-' . substr(hash('sha256', (string) Config::get('phonepe.client_id') . Config::get('phonepe.environment')), 0, 16) . '.json';
        $c = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (is_array($c) && ($c['expires_at'] ?? 0) > time() + 60 && is_string($c['token'] ?? null)) {
            return $c['token'];
        }
        $res = Http::request('POST', self::urls()['auth'], ['Content-Type' => 'application/x-www-form-urlencoded'], http_build_query([
            'client_id' => Config::get('phonepe.client_id'), 'client_version' => Config::get('phonepe.client_version', '1'), 'client_secret' => Config::get('phonepe.client_secret'), 'grant_type' => 'client_credentials',
        ]), 20);
        $d = json_decode($res['body'], true);
        if ($res['status'] !== 200 || !is_array($d) || empty($d['access_token'])) {
            throw new \RuntimeException('PhonePe authentication failed (HTTP ' . $res['status'] . ').');
        }
        $exp = (int) ($d['expires_at'] ?? (time() + 600));
        if (!is_dir(dirname($file))) {
            @mkdir(dirname($file), 0750, true);
        }
        @file_put_contents($file, json_encode(['token' => $d['access_token'], 'expires_at' => $exp]), LOCK_EX);
        @chmod($file, 0600);

        return (string) $d['access_token'];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{status:int,data:array<string,mixed>}
     */
    private static function api(string $method, string $path, ?array $payload = null): array
    {
        $res = Http::request($method, self::urls()['api'] . $path, ['Authorization' => 'O-Bearer ' . self::token(), 'Content-Type' => 'application/json'], $payload === null ? null : json_encode($payload, JSON_THROW_ON_ERROR), 25);
        $d = json_decode($res['body'], true);

        return ['status' => $res['status'], 'data' => is_array($d) ? $d : []];
    }

    // ------------------------------------------------------------------------------------------------ payment requests (staff)

    /** Smallest payable request: Rs 1.00 (PhonePe's own minimum for an order). */
    public const MIN_MINOR = 100;

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
     * A member of the team asks the client to pay an amount THEY typed: PhonePe is asked for an order of exactly that amount and the QR shown is built only
     * from PhonePe's answer for that order. Nothing is generated before an amount is entered, and nothing the browser sends can change it afterwards.
     *
     * @return array<string,mixed> the stored order row
     */
    public static function createRequest(int $actorId, array $invoice, int $amountMinor, string $note = ''): array
    {
        Gate::authorize('payments.request', $actorId);
        if (!in_array($invoice['status'], ['issued', 'partially_paid'], true)) {
            throw new ValidationException(['Only an issued invoice with a balance can be paid.']);
        }
        if (!self::enabled()) {
            throw new ValidationException(['PhonePe payments are not switched on (Administration -> Integrations & settings -> PhonePe).']);
        }
        $due = self::requestable($invoice);
        if ($amountMinor < self::MIN_MINOR) {
            throw new ValidationException(['The smallest amount PhonePe accepts is ₹1.00.']);
        }
        if ($amountMinor > $due) {
            throw new ValidationException(['The amount is more than what is still due on this invoice (₹' . FieldRules::formatMinor($due) . ').']);
        }
        $note = trim((string) preg_replace('/\s+/', ' ', $note));
        if (mb_strlen($note) > 200 || preg_match('/[<>]/', $note)) {
            throw new ValidationException(['The note must be at most 200 characters, without < or >.']);
        }
        if (!RateLimiter::hit('phonepe_request_user', (string) $actorId)) {
            throw new HttpException(429);
        }
        $mid = 'KIS' . $invoice['id'] . 'X' . strtoupper(bin2hex(random_bytes(6)));
        $ttl = max(5, (int) Config::get('phonepe.order_ttl_minutes', 30));
        $return = rtrim((string) Config::get('app.url'), '/') . '/my/invoices/' . $invoice['public_id'];
        $message = 'Payment for invoice ' . $invoice['reference'];
        $mode = (string) Config::get('phonepe.qr_mode', 'auto');
        $flow = '';
        $payload = '';
        $providerOrder = '';
        if (in_array($mode, ['auto', 'direct'], true)) {
            try {
                $r = self::api('POST', '/payments/v2/pay', ['merchantOrderId' => $mid, 'amount' => $amountMinor, 'expireAfter' => $ttl * 60, 'metaInfo' => ['udf1' => (string) $invoice['reference'], 'udf2' => 'staff-request'],
                    'paymentFlow' => ['type' => 'PG', 'message' => $message, 'merchantUrls' => ['redirectUrl' => $return], 'paymentMode' => ['type' => 'UPI_QR']]]);
                $qr = trim((string) ($r['data']['qrData'] ?? ''));
                $intent = trim((string) ($r['data']['intentUrl'] ?? ''));
                if ($r['status'] === 200 && $qr !== '' && self::scannable($qr)) {
                    [$flow, $payload, $providerOrder] = ['upi_qr', $qr, (string) ($r['data']['orderId'] ?? '')];
                } elseif ($r['status'] === 200 && $intent !== '' && self::scannable($intent)) {
                    [$flow, $payload, $providerOrder] = ['upi_intent', $intent, (string) ($r['data']['orderId'] ?? '')];
                } else {
                    Logger::error('PhonePe UPI QR not available', ['http' => $r['status'], 'code' => $r['data']['code'] ?? null]);
                }
            } catch (\Throwable $e) {
                Logger::error('PhonePe UPI QR request failed', ['invoice' => (int) $invoice['id'], 'error' => $e->getMessage()]);
            }
            if ($flow === '' && $mode === 'direct') {
                throw new ValidationException(['PhonePe did not return a UPI QR for this order (the UPI QR option may not be enabled for your merchant account). Nothing was charged.']);
            }
            if ($flow !== '') {
                self::assertAmountInPayload($payload, $amountMinor);
            }
        }
        if ($flow === '') { // PhonePe's hosted checkout page, which itself shows the UPI QR for the order; we draw a QR that opens it
            $mid = 'KIS' . $invoice['id'] . 'X' . strtoupper(bin2hex(random_bytes(6))); // a fresh id: the first attempt may already exist at PhonePe
            try {
                $r = self::api('POST', '/checkout/v2/pay', ['merchantOrderId' => $mid, 'amount' => $amountMinor, 'expireAfter' => $ttl * 60, 'metaInfo' => ['udf1' => (string) $invoice['reference'], 'udf2' => 'staff-request'],
                    'paymentFlow' => ['type' => 'PG_CHECKOUT', 'message' => $message, 'merchantUrls' => ['redirectUrl' => $return]]]);
            } catch (\Throwable $e) {
                Logger::error('PhonePe request failed', ['invoice' => (int) $invoice['id'], 'error' => $e->getMessage()]);
                throw new ValidationException(['We could not reach PhonePe. Nothing was requested. Please try again.']);
            }
            $url = (string) ($r['data']['redirectUrl'] ?? '');
            if ($r['status'] !== 200 || $url === '' || !str_starts_with($url, 'https://') || mb_strlen($url) > 1900) {
                Logger::error('PhonePe request refused', ['invoice' => (int) $invoice['id'], 'http' => $r['status'], 'code' => $r['data']['code'] ?? null]);
                throw new ValidationException(['PhonePe did not accept the payment request. Nothing was requested. Please try again later.']);
            }
            [$flow, $payload, $providerOrder] = ['checkout_link', $url, (string) ($r['data']['orderId'] ?? '')];
        }
        $pub = uuid_hex();
        Database::run("INSERT INTO gateway_orders (public_id, merchant_order_id, provider, flow, invoice_id, client_id, amount_minor, qr_payload, note, expires_at, status, provider_order_id, created_by, created_at, updated_at)
            VALUES (?, ?, 'phonepe', ?, ?, ?, ?, ?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? MINUTE), 'pending', ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())",
            [$pub, $mid, $flow, $invoice['id'], $invoice['client_id'], $amountMinor, $payload, $note === '' ? null : $note, $ttl, $providerOrder === '' ? null : $providerOrder, $actorId]);
        $id = Database::insertId();
        Audit::record('gateway.request_created', 'invoice', (int) $invoice['id'], [], ['amount_minor' => $amountMinor, 'merchant_order_id' => $mid], ['provider' => 'phonepe', 'flow' => $flow], $actorId);
        $clientUser = Database::one('SELECT user_id FROM clients WHERE id = ?', [$invoice['client_id']]);
        if ($clientUser !== null && $clientUser['user_id'] !== null) {
            Notifier::send([(int) $clientUser['user_id']], 'payment.requested', 'Payment request: ₹' . FieldRules::formatMinor($amountMinor), 'A PhonePe payment request for invoice ' . $invoice['reference'] . ' is waiting for you.', '/my/pay/' . $pub, null, 'gateway_order', $id, "pay-request:{$id}");
        }

        return Database::one('SELECT * FROM gateway_orders WHERE id = ?', [$id]);
    }

    /** True for a text a QR scanner can use: a UPI link or an https address, of a sensible size. */
    private static function scannable(string $s): bool
    {
        return mb_strlen($s) <= 1900 && (bool) preg_match('#^(upi://|https://)\S+$#i', $s);
    }

    /**
     * A UPI link carries its amount in "am=". When it is there it must be exactly what the team entered, or the QR is refused: this is what makes sure the
     * QR on screen asks for that amount and no other.
     */
    private static function assertAmountInPayload(string $payload, int $amountMinor): void
    {
        if (!str_starts_with(strtolower($payload), 'upi://')) {
            return;
        }
        parse_str((string) parse_url($payload, PHP_URL_QUERY), $q);
        if (isset($q['am']) && !self::sameAmount((string) $q['am'], $amountMinor)) {
            Logger::security('PhonePe QR amount differs from the request', ['requested' => $amountMinor, 'in_qr' => (string) $q['am']]);
            throw new ValidationException(['PhonePe returned a QR for a different amount than the one entered. It was discarded and nothing was requested.']);
        }
    }

    private static function sameAmount(string $am, int $minor): bool
    {
        return preg_match('/^\d{1,9}(\.\d{1,2})?$/', $am) === 1 && (int) round(((float) $am) * 100) === $minor;
    }

    /** @return array<string,mixed>|null */
    public static function findRequest(string $publicId): ?array
    {
        return preg_match('/^[a-f0-9]{32}$/', $publicId) ? Database::one("SELECT * FROM gateway_orders WHERE public_id = ? AND provider = 'phonepe'", [$publicId]) : null;
    }

    /** @param array<string,mixed> $o an order row @return bool the QR may still be shown and paid */
    public static function isLive(array $o): bool
    {
        return $o['status'] === 'pending' && $o['expires_at'] !== null && strtotime((string) $o['expires_at'] . ' UTC') > time();
    }

    /** @return list<array<string,mixed>> requests that can still be paid for one invoice, newest first */
    public static function liveRequests(int $invoiceId): array
    {
        return Database::all("SELECT * FROM gateway_orders WHERE invoice_id = ? AND provider = 'phonepe' AND status = 'pending' AND expires_at > UTC_TIMESTAMP() ORDER BY id DESC", [$invoiceId]);
    }

    /** The team withdraws a request: the QR disappears. A payment that was already made is still recorded when PhonePe confirms it. @param array<string,mixed> $order */
    public static function cancelRequest(int $actorId, array $order): void
    {
        Gate::authorize('payments.request', $actorId);
        if (!in_array($order['status'], ['pending', 'created'], true)) {
            throw new ValidationException(['This request is no longer open.']);
        }
        Database::run("UPDATE gateway_orders SET status = 'cancelled', cancelled_by = ?, cancelled_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE id = ? AND status IN ('pending','created')", [$actorId, $order['id']]);
        Audit::record('gateway.request_cancelled', 'gateway_order', (int) $order['id'], ['status' => $order['status']], ['status' => 'cancelled'], ['merchant_order_id' => $order['merchant_order_id']], $actorId);
    }

    /**
     * Asks PhonePe for the state of one of OUR orders and records the payment when (and only when) it is COMPLETED for the right amount.
     *
     * @return array{state:string,recorded:bool}
     */
    public static function reconcile(string $merchantOrderId): array
    {
        $order = Database::one('SELECT * FROM gateway_orders WHERE merchant_order_id = ?', [$merchantOrderId]);
        if ($order === null) {
            throw new HttpException(404);
        }
        if ($order['status'] === 'completed') {
            return ['state' => 'COMPLETED', 'recorded' => false];
        }
        $r = self::api('GET', '/checkout/v2/order/' . rawurlencode($merchantOrderId) . '/status?details=false');
        if ($r['status'] !== 200) {
            throw new \RuntimeException('PhonePe status check answered HTTP ' . $r['status']);
        }
        $state = strtoupper((string) ($r['data']['state'] ?? 'PENDING'));
        Database::run('UPDATE gateway_orders SET last_checked_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE id = ?', [$order['id']]);
        if ($state === 'FAILED') {
            Database::run("UPDATE gateway_orders SET status = 'failed', updated_at = UTC_TIMESTAMP() WHERE id = ? AND status = 'pending'", [$order['id']]);

            return ['state' => 'FAILED', 'recorded' => false];
        }
        if ($state !== 'COMPLETED') {
            if ($order['status'] === 'pending' && strtotime(($order['expires_at'] ?? $order['created_at']) . ' UTC') < time() - 60) {
                Database::run("UPDATE gateway_orders SET status = 'expired', updated_at = UTC_TIMESTAMP() WHERE id = ? AND status = 'pending'", [$order['id']]);
            }

            return ['state' => $state, 'recorded' => false];
        }
        $paid = (int) ($r['data']['amount'] ?? 0);
        if ($paid !== (int) $order['amount_minor']) {
            Logger::security('PhonePe amount mismatch', ['order' => $merchantOrderId, 'expected' => (int) $order['amount_minor'], 'reported' => $paid]);
            Audit::record('gateway.amount_mismatch', 'gateway_order', (int) $order['id'], [], ['expected_minor' => (int) $order['amount_minor'], 'reported_minor' => $paid], [], null);
            throw new \RuntimeException('PhonePe reported a different amount than the order. A person must check this order.');
        }
        $txn = '';
        foreach ((array) ($r['data']['paymentDetails'] ?? []) as $pd) {
            if (is_array($pd) && strtoupper((string) ($pd['state'] ?? '')) === 'COMPLETED') {
                $txn = (string) ($pd['transactionId'] ?? '');
            }
        }
        $ref = 'PhonePe ' . ($txn !== '' ? $txn : (string) ($r['data']['orderId'] ?? $merchantOrderId));
        $recorded = false;
        Database::transaction(function () use ($order, $ref, $txn, &$recorded): void {
            $locked = Database::one('SELECT * FROM gateway_orders WHERE id = ? FOR UPDATE', [$order['id']]);
            if ($locked['status'] === 'completed') {
                return;
            }
            $invoice = InvoiceService::find((string) Database::one('SELECT public_id FROM invoices WHERE id = ?', [$order['invoice_id']])['public_id']);
            $p = ClientPaymentService::recordGateway($invoice, (int) $order['amount_minor'], $ref, 'Confirmed by PhonePe (order ' . $order['merchant_order_id'] . ')');
            Database::run("UPDATE gateway_orders SET status = 'completed', provider_txn_id = ?, payment_id = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?", [$txn === '' ? null : $txn, $p['id'], $order['id']]);
            $recorded = $p['created'];
        });

        return ['state' => 'COMPLETED', 'recorded' => $recorded];
    }

    /**
     * Handles PhonePe's callback. The body is never believed: after the signature check it only triggers reconcile() for the order it names.
     *
     * @return array{status:int,outcome:string}
     */
    public static function webhook(string $authorization, string $body): array
    {
        $user = (string) Config::get('phonepe.webhook_username');
        $pass = (string) Config::get('phonepe.webhook_password');
        if ($user === '' || $pass === '') {
            return ['status' => 401, 'outcome' => 'not_configured']; // same answer as a wrong password: do not reveal the setup state
        }
        $expected = hash('sha256', $user . ':' . $pass);
        $given = strtolower(trim(preg_replace('/^(sha256\s*)?[:=]?\s*/i', '', $authorization) ?? ''));
        if (!hash_equals($expected, $given)) {
            Logger::security('PhonePe webhook rejected: bad authorization');
            return ['status' => 401, 'outcome' => 'unauthorized'];
        }
        $d = json_decode($body, true);
        $payload = is_array($d['payload'] ?? null) ? $d['payload'] : [];
        $mid = (string) ($payload['merchantOrderId'] ?? $payload['originalMerchantOrderId'] ?? '');
        $event = mb_substr((string) ($d['event'] ?? ''), 0, 60);
        $key = hash('sha256', $body);
        $dup = Database::run('INSERT IGNORE INTO gateway_webhook_events (provider, event_key, merchant_order_id, event, received_at) VALUES (\'phonepe\', ?, ?, ?, UTC_TIMESTAMP())', [$key, $mid === '' ? null : mb_substr($mid, 0, 64), $event])->rowCount() === 0;
        if ($mid === '' || !preg_match('/^(KIS|VIS)\d+X[A-F0-9]{12}$/', $mid)) {
            return ['status' => 200, 'outcome' => 'ignored'];
        }
        try {
            $r = self::reconcile($mid);
            Database::run('UPDATE gateway_webhook_events SET processed_at = UTC_TIMESTAMP(), outcome = ? WHERE event_key = ?', [mb_substr($r['state'] . ($dup ? ' (repeat)' : ''), 0, 60), $key]);

            return ['status' => 200, 'outcome' => strtolower($r['state'])];
        } catch (HttpException) {
            return ['status' => 200, 'outcome' => 'unknown_order'];
        } catch (\Throwable $e) {
            Logger::error('PhonePe webhook processing failed', ['order' => $mid, 'error' => $e->getMessage()]);

            return ['status' => 500, 'outcome' => 'retry']; // PhonePe retries on a non-2xx answer
        }
    }

    /** Orders that are still pending and old enough to be worth asking about (the scheduler calls this). @return int orders reconciled */
    public static function reconcilePending(): int
    {
        if (!self::enabled()) {
            return 0;
        }
        $n = 0;
        foreach (Database::all("SELECT merchant_order_id FROM gateway_orders WHERE status = 'pending' AND created_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 2 DAY) AND created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 3 MINUTE) ORDER BY id LIMIT 50") as $o) {
            try {
                $n += self::reconcile((string) $o['merchant_order_id'])['recorded'] ? 1 : 0;
            } catch (\Throwable $e) {
                Logger::error('PhonePe reconcile failed', ['order' => $o['merchant_order_id'], 'error' => $e->getMessage()]);
            }
        }

        return $n;
    }
}
