<?php

declare(strict_types=1);

use Vertex\Core\Env;

/** Razorpay online payments (QR / payment links asked for by the team). Off until the API keys are saved and it is switched on in Administration -> Integrations & settings. */
return [
    'enabled' => (bool) Env::get('RAZORPAY_ENABLED', false),
    'key_id' => (string) Env::get('RAZORPAY_KEY_ID', ''),           // rzp_test_... or rzp_live_...
    'key_secret' => (string) Env::get('RAZORPAY_KEY_SECRET', ''),
    'webhook_secret' => (string) Env::get('RAZORPAY_WEBHOOK_SECRET', ''),
    'base_url' => 'https://api.razorpay.com/v1',
    // auto = ask for a fixed-amount UPI QR and use it when Razorpay returns its UPI string, otherwise a Payment Link for the exact amount; link = Payment Link only
    'qr_mode' => (string) Env::get('RAZORPAY_QR_MODE', 'auto'),
    'order_ttl_minutes' => 30,
];
