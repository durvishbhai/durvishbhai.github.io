<?php

declare(strict_types=1);

namespace Vertex\Core;

/**
 * Baseline response headers. The CSP forbids inline script/style, so templates must use external assets only.
 * When a payment gateway is added (a later phase) its script/frame origin must be added here deliberately.
 */
final class SecurityHeadersPolicy
{
    /** @return array<string,string> */
    public static function headers(): array
    {
        $h = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=()',
            'Cross-Origin-Opener-Policy' => 'same-origin',
            'Content-Security-Policy' => "default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; "
                . "object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'",
        ];
        if (Config::get('app.env') === 'production') {
            $h['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }

        return $h;
    }
}
