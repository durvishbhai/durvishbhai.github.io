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
    'order_ttl_minutes' => 30,
];
