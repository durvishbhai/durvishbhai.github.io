<?php

declare(strict_types=1);

use Vertex\Core\Env;

return [
    // Public self-registration (creates CLIENT accounts only). Off by default; also needs working email.
    'registration_enabled' => (bool) Env::get('REGISTRATION_ENABLED', false),
    'password_min_length' => 12,
    'email_verify_ttl_minutes' => 24 * 60,
    'password_reset_ttl_minutes' => 60,
    'invite_ttl_minutes' => 72 * 60,
    // Rate limits: [max attempts, window seconds]
    'limits' => [
        'password_reset_ip' => [5, 3600],
        'password_reset_email' => [3, 3600],
        'register_ip' => [5, 3600],
        'verify_resend_ip' => [5, 3600],
        'verify_resend_email' => [3, 3600],
        'stepup_user' => [5, 600],
        'token_ip' => [30, 3600],
    ],
];
