<?php

declare(strict_types=1);

use Vertex\Core\Env;

/*
 * MAIL_DRIVER:
 *   smtp - real delivery through PHPMailer (needs MAIL_HOST etc.)
 *   log  - DEVELOPMENT ONLY: writes each email to storage/logs/mail-YYYY-MM-DD.log instead of sending
 *   none - email is not configured; flows that need email (registration, verification, password reset, invites)
 *          are disabled and say so. This is the default.
 */
return [
    'driver' => (string) Env::get('MAIL_DRIVER', 'none'),
    'host' => (string) Env::get('MAIL_HOST', ''),
    'port' => (int) Env::get('MAIL_PORT', 587),
    'encryption' => (string) Env::get('MAIL_ENCRYPTION', 'tls'), // tls (STARTTLS) | ssl | none
    'username' => (string) Env::get('MAIL_USERNAME', ''),
    'password' => (string) Env::get('MAIL_PASSWORD', ''),
    'from_address' => (string) Env::get('MAIL_FROM_ADDRESS', ''),
    'from_name' => (string) Env::get('MAIL_FROM_NAME', (string) Env::get('APP_NAME', 'Krazy Integrated Solutions')),
];
