<?php

declare(strict_types=1);

use Vertex\Core\Env;

return [
    // Public self-registration (creates CLIENT accounts only). Off by default; also needs working email.
    'registration_enabled' => (bool) Env::get('REGISTRATION_ENABLED', false),
    'password_min_length' => 12,
    // Two-factor login is optional for everyone. Set TWO_FACTOR_REQUIRED_LEVEL to a role level (e.g. 90 = Administrator and above) to make it compulsory; 0 = off.
    'two_factor_required_level' => (int) Env::get('TWO_FACTOR_REQUIRED_LEVEL', 0),
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
        'totp_user' => [5, 600],
        'search_user' => [120, 600],
        'scope_request_user' => [10, 3600],
        'feedback_user' => [60, 3600],
        'token_ip' => [30, 3600],
        'public_form_ip' => [5, 3600],
        'attachment_user' => [30, 3600],
        'upload_user' => [40, 3600],
        'download_user' => [200, 3600],
        'revision_user' => [20, 3600],
        'ticket_create_user' => [20, 3600],
        'ticket_message_user' => [60, 3600],
        'partner_details_user' => [10, 3600],
        'partner_referral_user' => [30, 3600],
        'payout_request_user' => [10, 3600],
        'payment_claim_user' => [10, 3600],
        'ai_user' => [30, 3600],
        'phonepe_start_user' => [10, 3600],
        'cms_upload_user' => [60, 3600],
        'career_ip' => [5, 3600],
    ],
];
