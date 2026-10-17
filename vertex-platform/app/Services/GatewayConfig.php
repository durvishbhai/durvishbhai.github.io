<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Database;
use Vertex\Core\ValidationException;

/**
 * Google Pay settings, edited in Administration → Payments (`billing.configure`, with the password).
 *
 * Google Pay is NOT a stand-alone payment gateway. There are two real ways to take Google Pay money, and this class stores the fields for both:
 *
 *  1. `google_pay_upi` — Google Pay (or any UPI app) pays the business's UPI ID directly. The system builds a `upi://pay` link for an invoice; the client pays
 *     in the app and submits the transaction number; staff verify it against the bank. WORKS TODAY, confirmation is manual (UPI has no callback to a plain
 *     UPI ID), so it never shows a payment as received by itself.
 *  2. `google_pay_api` — the Google Pay API for the web (card tokens). Google hands the encrypted token to a payment PROCESSOR (Razorpay, PayU, Cashfree,
 *     Stripe …) that charges the card. This needs a Google Pay & Wallet Console merchant ID, the processor's merchant id and API credentials, and a
 *     processor-specific adapter that is NOT installed: the fields are stored (secrets encrypted with SecretBox, never shown again) so the connection can be
 *     completed, and the screen says the integration is not active. No payment is ever shown as taken through it.
 */
final class GatewayConfig
{
    public const UPI = 'google_pay_upi';
    public const API = 'google_pay_api';
    /** Processor adapters that actually exist in this release (none). A processor must be in this list before the API mode can be active. */
    public const ADAPTERS = [];
    public const NETWORKS = ['VISA' => 'Visa', 'MASTERCARD' => 'Mastercard', 'AMEX' => 'American Express', 'DISCOVER' => 'Discover', 'JCB' => 'JCB', 'INTERAC' => 'Interac'];
    public const AUTH_METHODS = ['PAN_ONLY' => 'Card on file (PAN_ONLY)', 'CRYPTOGRAM_3DS' => 'Device token (CRYPTOGRAM_3DS)'];
    private const SECRETS = ['processor_key_secret', 'webhook_secret'];

    /** @return array{enabled:bool,config:array<string,mixed>,secrets_set:array<string,string>,updated_at:?string,updated_by:?string} */
    public static function get(string $provider): array
    {
        $row = Database::one('SELECT g.*, u.name AS updated_by_name FROM payment_gateway_config g LEFT JOIN users u ON u.id = g.updated_by WHERE g.provider = ?', [$provider]);
        $hints = [];
        if ($row !== null && $row['secrets_enc'] !== null) {
            foreach ((array) json_decode((string) ($row['config_json'] ?? '{}'), true) as $k => $v) {
                if (str_ends_with((string) $k, '_hint')) {
                    $hints[substr((string) $k, 0, -5)] = (string) $v;
                }
            }
        }

        return ['enabled' => $row !== null && (int) $row['enabled'] === 1, 'config' => $row === null ? [] : array_filter((array) json_decode((string) ($row['config_json'] ?? '{}'), true), static fn ($k): bool => !str_ends_with((string) $k, '_hint'), ARRAY_FILTER_USE_KEY),
            'secrets_set' => $hints, 'updated_at' => $row['updated_at'] ?? null, 'updated_by' => $row['updated_by_name'] ?? null];
    }

    /** The decrypted secret for a processor adapter. Never rendered, never logged. */
    public static function secret(string $provider, string $name): ?string
    {
        $row = Database::one('SELECT secrets_enc FROM payment_gateway_config WHERE provider = ?', [$provider]);
        if ($row === null || $row['secrets_enc'] === null) {
            return null;
        }
        $plain = SecretBox::open((string) $row['secrets_enc']);
        $all = $plain === null ? [] : (array) json_decode($plain, true);

        return isset($all[$name]) ? (string) $all[$name] : null;
    }

    // ------------------------------------------------------------------------------------------------ UPI (Google Pay app)

    /** @param array<string,mixed> $in */
    public static function saveUpi(int $actorId, array $in): void
    {
        Gate::authorize('billing.configure', $actorId);
        $errors = [];
        $vpa = trim((string) ($in['upi_id'] ?? ''));
        $name = trim((string) ($in['payee_name'] ?? ''));
        $mcc = trim((string) ($in['merchant_code'] ?? ''));
        $enabled = !empty($in['enabled']) && $in['enabled'] !== '0';
        if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._\-]{1,255}@[a-zA-Z][a-zA-Z0-9]{1,63}$/', $vpa)) {
            $errors[] = 'Enter the UPI ID exactly as it appears in Google Pay, for example yourname@okhdfcbank.';
        }
        if (mb_strlen($name) < 2 || mb_strlen($name) > 99 || preg_match('/[\x00-\x1F<>]/u', $name)) {
            $errors[] = 'Enter the payee name that should appear in the payment app (2 to 99 characters).';
        }
        if ($mcc !== '' && !preg_match('/^\d{4}$/', $mcc)) {
            $errors[] = 'The merchant category code is four digits (leave it empty if you do not have one).';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $before = self::get(self::UPI);
        self::store(self::UPI, $enabled, ['upi_id' => $vpa, 'payee_name' => $name, 'merchant_code' => $mcc], null, $actorId, ['enabled' => $before['enabled'], 'upi_id' => self::mask((string) ($before['config']['upi_id'] ?? ''))], ['enabled' => $enabled, 'upi_id' => self::mask($vpa)]);
    }

    public static function upiActive(): bool
    {
        $g = self::get(self::UPI);

        return $g['enabled'] && !empty($g['config']['upi_id']) && !empty($g['config']['payee_name']);
    }

    /**
     * The `upi://pay` link that opens Google Pay (or another UPI app) with the payee, amount and invoice reference filled in. It moves no money by itself:
     * the client still approves the payment in their app, and the system learns of it only when the client submits the transaction number and staff verify it.
     *
     * @param array<string,mixed> $invoice
     */
    public static function upiLink(array $invoice, int $amountMinor): ?string
    {
        if (!self::upiActive() || $amountMinor < 1) {
            return null;
        }
        $c = self::get(self::UPI)['config'];
        $q = ['pa' => (string) $c['upi_id'], 'pn' => (string) $c['payee_name'], 'am' => number_format($amountMinor / 100, 2, '.', ''), 'cu' => 'INR', 'tn' => 'Invoice ' . (string) $invoice['reference']];
        if (!empty($c['merchant_code'])) {
            $q['mc'] = (string) $c['merchant_code'];
        }

        return 'upi://pay?' . http_build_query($q, '', '&', PHP_QUERY_RFC3986);
    }

    // ------------------------------------------------------------------------------------------------ Google Pay API (card tokens via a processor)

    /** @param array<string,mixed> $in */
    public static function saveApi(int $actorId, array $in): void
    {
        Gate::authorize('billing.configure', $actorId);
        $errors = [];
        $env = (string) ($in['environment'] ?? '');
        if (!in_array($env, ['TEST', 'PRODUCTION'], true)) {
            $errors[] = 'Choose TEST or PRODUCTION.';
        }
        $merchantId = trim((string) ($in['merchant_id'] ?? ''));
        if ($merchantId !== '' && !preg_match('/^[A-Za-z0-9]{8,32}$/', $merchantId)) {
            $errors[] = 'The Google Pay merchant ID is 8 to 32 letters and digits (it is shown in the Google Pay & Wallet Console).';
        }
        if ($env === 'PRODUCTION' && $merchantId === '') {
            $errors[] = 'PRODUCTION needs the Google Pay merchant ID that Google approved for your business.';
        }
        $merchantName = trim((string) ($in['merchant_name'] ?? ''));
        if (mb_strlen($merchantName) < 2 || mb_strlen($merchantName) > 100 || preg_match('/[\x00-\x1F<>]/u', $merchantName)) {
            $errors[] = 'Enter the merchant name shown to the buyer in Google Pay (2 to 100 characters).';
        }
        $origin = trim((string) ($in['merchant_origin'] ?? ''));
        if ($origin !== '' && !preg_match('#^https://[a-z0-9]([a-z0-9.\-]*[a-z0-9])?(:\d{2,5})?$#i', $origin)) {
            $errors[] = 'The website must be an https address with no path, for example https://pay.example.com.';
        }
        $processor = strtolower(trim((string) ($in['processor'] ?? '')));
        if (!preg_match('/^[a-z][a-z0-9_]{1,40}$/', $processor)) {
            $errors[] = 'Enter the payment processor identifier that Google Pay should send the token to (for example razorpay, payu, cashfree, stripe): it is in your processor\'s Google Pay documentation.';
        }
        $processorMerchantId = trim((string) ($in['processor_merchant_id'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9_.\-]{2,100}$/', $processorMerchantId)) {
            $errors[] = 'Enter your merchant ID at the payment processor (letters, digits and . _ - only).';
        }
        $keyId = trim((string) ($in['processor_key_id'] ?? ''));
        if ($keyId !== '' && !preg_match('/^[A-Za-z0-9_.\-]{4,120}$/', $keyId)) {
            $errors[] = 'The processor API key ID may contain only letters, digits and . _ -.';
        }
        $networks = array_values(array_intersect(array_keys(self::NETWORKS), array_map('strval', (array) ($in['networks'] ?? []))));
        $methods = array_values(array_intersect(array_keys(self::AUTH_METHODS), array_map('strval', (array) ($in['auth_methods'] ?? []))));
        if ($networks === [] || $methods === []) {
            $errors[] = 'Choose at least one card network and one authentication method.';
        }
        $newSecrets = [];
        foreach (self::SECRETS as $name) {
            $v = (string) ($in[$name] ?? '');
            if ($v !== '') {
                if (strlen($v) < 8 || strlen($v) > 200 || preg_match('/\s|[\x00-\x1F]/', $v)) {
                    $errors[] = ($name === 'webhook_secret' ? 'The webhook secret' : 'The processor API secret') . ' must be 8 to 200 characters with no spaces.';
                } else {
                    $newSecrets[$name] = $v;
                }
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $clear = !empty($in['clear_secrets']) && $in['clear_secrets'] !== '0';
        $before = self::get(self::API);
        $hints = $clear ? [] : $before['secrets_set'];
        $existing = [];
        if (!$clear) {
            try {
                foreach (self::SECRETS as $name) {
                    $s = self::secret(self::API, $name);
                    if ($s !== null) {
                        $existing[$name] = $s;
                    }
                }
            } catch (\RuntimeException) {
                throw new ValidationException(['APP_KEY is not set on the server, so saved secrets cannot be read. Set it in .env first.']);
            }
        }
        $all = $newSecrets + $existing;
        foreach ($newSecrets as $name => $v) {
            $hints[$name] = '••••' . substr($v, -4);
        }
        $cfg = ['environment' => $env, 'merchant_id' => $merchantId, 'merchant_name' => $merchantName, 'merchant_origin' => $origin, 'processor' => $processor, 'processor_merchant_id' => $processorMerchantId,
            'processor_key_id' => $keyId, 'networks' => $networks, 'auth_methods' => $methods, 'country' => 'IN', 'currency' => 'INR'];
        foreach ($hints as $k => $h) {
            $cfg[$k . '_hint'] = $h;
        }
        try {
            $sealed = $all === [] ? null : SecretBox::seal(json_encode($all, JSON_THROW_ON_ERROR));
        } catch (\RuntimeException) {
            throw new ValidationException(['APP_KEY is not set on the server, so secrets cannot be stored safely. Set it in .env first.']);
        }
        self::store(self::API, false, $cfg, $sealed, $actorId, ['environment' => $before['config']['environment'] ?? null, 'processor' => $before['config']['processor'] ?? null, 'secrets' => array_keys($before['secrets_set'])],
            ['environment' => $env, 'processor' => $processor, 'secrets' => array_keys($hints), 'secrets_replaced' => array_keys($newSecrets), 'secrets_cleared' => $clear]);
    }

    // ------------------------------------------------------------------------------------------------ status

    /**
     * What each mode can really do right now.
     *
     * @return array{state:'not_configured'|'ready'|'configured_inactive',label:string,notes:list<string>}
     */
    public static function status(string $provider): array
    {
        $g = self::get($provider);
        if ($provider === self::UPI) {
            if ($g['config'] === []) {
                return ['state' => 'not_configured', 'label' => 'Not configured', 'notes' => ['Add your Google Pay UPI ID to let clients pay invoices from the Google Pay app.']];
            }
            if (!$g['enabled']) {
                return ['state' => 'configured_inactive', 'label' => 'Saved, switched off', 'notes' => ['Clients do not see a Pay button until you switch it on.']];
            }

            return ['state' => 'ready', 'label' => 'On: clients can pay in Google Pay, you confirm each payment', 'notes' => ['Payments are NOT confirmed automatically. After a client submits the transaction number, check your bank and verify it (Client payments).']];
        }
        if ($g['config'] === []) {
            return ['state' => 'not_configured', 'label' => 'Not configured', 'notes' => []];
        }
        $notes = [];
        $processor = (string) ($g['config']['processor'] ?? '');
        if (!in_array($processor, self::ADAPTERS, true)) {
            $notes[] = "The integration with the processor \"{$processor}\" is not installed in this release. The fields are saved so it can be connected, but NO payment is taken or confirmed through Google Pay API yet, and clients are not offered it.";
        }
        if (($g['config']['environment'] ?? '') === 'PRODUCTION' && empty($g['config']['merchant_id'])) {
            $notes[] = 'PRODUCTION needs the approved Google Pay merchant ID.';
        }
        if (!isset($g['secrets_set']['processor_key_secret'])) {
            $notes[] = 'The processor API secret has not been saved.';
        }
        if (!isset($g['secrets_set']['webhook_secret'])) {
            $notes[] = 'The webhook secret has not been saved.';
        }

        return ['state' => 'configured_inactive', 'label' => 'Fields saved, not active', 'notes' => $notes];
    }

    /** A harmless self-check of the saved UPI settings: formats, and the link the client would get. @return array{ok:bool,messages:list<string>,sample:?string} */
    public static function checkUpi(): array
    {
        $g = self::get(self::UPI);
        if ($g['config'] === []) {
            return ['ok' => false, 'messages' => ['Nothing is saved yet.'], 'sample' => null];
        }
        $sample = self::upiLink(['reference' => 'INV-TEST-00000'], 10000);
        if ($sample === null) {
            $c = $g['config'];
            $link = 'upi://pay?' . http_build_query(['pa' => $c['upi_id'] ?? '', 'pn' => $c['payee_name'] ?? '', 'am' => '100.00', 'cu' => 'INR', 'tn' => 'Invoice INV-TEST-00000'], '', '&', PHP_QUERY_RFC3986);

            return ['ok' => true, 'messages' => ['The settings are valid, but the Pay button is switched off.', 'This only checks the format. It cannot confirm that the UPI ID exists or can receive money: pay yourself ₹1 from Google Pay once to prove it.'], 'sample' => $link];
        }

        return ['ok' => true, 'messages' => ['The settings are valid and the Pay button is on.', 'This only checks the format. It cannot confirm that the UPI ID exists or can receive money: pay yourself ₹1 from Google Pay once to prove it.'], 'sample' => $sample];
    }

    // ------------------------------------------------------------------------------------------------ internals

    /** @param array<string,mixed> $config */
    private static function store(string $provider, bool $enabled, array $config, ?string $sealed, int $actorId, array $before, array $after): void
    {
        Database::transaction(function () use ($provider, $enabled, $config, $sealed, $actorId, $before, $after): void {
            Database::run('INSERT INTO payment_gateway_config (provider, enabled, config_json, secrets_enc, updated_by, updated_at) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())
                           ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), config_json = VALUES(config_json), secrets_enc = VALUES(secrets_enc), updated_by = VALUES(updated_by), updated_at = UTC_TIMESTAMP()',
                [$provider, $enabled ? 1 : 0, json_encode($config, JSON_THROW_ON_ERROR), $sealed, $actorId]);
            Audit::record('billing.gateway_changed', 'payment_gateway', 0, $before, $after, ['provider' => $provider], $actorId);
        });
    }

    private static function mask(string $v): string
    {
        return $v === '' ? '' : '••••' . substr($v, -6);
    }
}
