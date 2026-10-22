<?php

declare(strict_types=1);

namespace Vertex\Core;

/**
 * Baseline response headers. The CSP forbids inline script/style, so templates must use external assets only.
 * The only exception is the client's pay page for a Razorpay Standard Checkout request (checkout()), which may load Razorpay's checkout.js and frame its payment window.
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

    /**
     * Headers for the one page that opens Razorpay's checkout window. Everything stays 'self' except Razorpay's own hosts, which checkout.js needs: its script,
     * its payment frame and its API/analytics calls. Popups (UPI apps, bank 3-D Secure pages) need the opener relationship, hence same-origin-allow-popups.
     *
     * @return array<string,string>
     */
    public static function checkout(): array
    {
        $rzp = 'https://checkout.razorpay.com https://api.razorpay.com';

        return [
            'Content-Security-Policy' => "default-src 'self'; img-src 'self' data: https:; style-src 'self' 'unsafe-inline'; script-src 'self' https://checkout.razorpay.com; "
                . "frame-src {$rzp}; connect-src 'self' {$rzp} https://lumberjack.razorpay.com; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'",
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=(self "https://checkout.razorpay.com" "https://api.razorpay.com")',
            'Cross-Origin-Opener-Policy' => 'same-origin-allow-popups',
        ];
    }
}
