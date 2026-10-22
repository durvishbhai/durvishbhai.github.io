<?php

declare(strict_types=1);

use Vertex\Core\Env;

/** PhonePe payment gateway (Standard Checkout). Off until the merchant credentials are saved and it is switched on in Administration -> Integrations & settings. */
return [
    'enabled' => (bool) Env::get('PHONEPE_ENABLED', false),
    'environment' => (string) Env::get('PHONEPE_ENV', 'sandbox'), // sandbox | production
    'client_id' => (string) Env::get('PHONEPE_CLIENT_ID', ''),
    'client_secret' => (string) Env::get('PHONEPE_CLIENT_SECRET', ''),
    'client_version' => (string) Env::get('PHONEPE_CLIENT_VERSION', '1'),
    'webhook_username' => (string) Env::get('PHONEPE_WEBHOOK_USERNAME', ''),
    'webhook_password' => (string) Env::get('PHONEPE_WEBHOOK_PASSWORD', ''),
    'urls' => [
        'sandbox' => ['auth' => 'https://api-preprod.phonepe.com/apis/pg-sandbox/v1/oauth/token', 'api' => 'https://api-preprod.phonepe.com/apis/pg-sandbox'],
        'production' => ['auth' => 'https://api.phonepe.com/apis/identity-manager/v1/oauth/token', 'api' => 'https://api.phonepe.com/apis/pg'],
    ],
    // How the QR is made: auto = ask PhonePe for a UPI QR and fall back to its hosted checkout link; direct = UPI QR only; checkout = hosted checkout link only
    'qr_mode' => (string) Env::get('PHONEPE_QR_MODE', 'auto'),
    'order_ttl_minutes' => 30,
];
