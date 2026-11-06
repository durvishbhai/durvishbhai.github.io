<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Config;
use Vertex\Core\Logger;
use Vertex\Core\Request;
use Vertex\Core\ValidationException;

/**
 * Cloudflare Turnstile on the public forms. Off by default. When the Owner switches it on (and both keys are saved: the secret key is encrypted and never reaches the browser),
 * every protected public form needs a valid token, checked on the SERVER with Cloudflare; if Cloudflare cannot be reached the form is refused (it fails closed).
 */
final class Turnstile
{
    public const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
    public const ORIGIN = 'https://challenges.cloudflare.com';

    public static function enabled(): bool
    {
        return (bool) Config::get('turnstile.enabled', false) && trim((string) Config::get('turnstile.site_key', '')) !== '' && trim((string) Config::get('turnstile.secret_key', '')) !== '';
    }

    public static function siteKey(): string
    {
        return trim((string) Config::get('turnstile.site_key', ''));
    }

    public static function verify(Request $request): bool
    {
        $token = $request->string('cf-turnstile-response');
        if ($token === '' || mb_strlen($token) > 2048) {
            return false;
        }
        try {
            $r = Http::request('POST', self::VERIFY_URL, ['Content-Type' => 'application/x-www-form-urlencoded'], http_build_query(['secret' => (string) Config::get('turnstile.secret_key'), 'response' => $token, 'remoteip' => $request->ip()]), 10);
            $j = json_decode($r['body'], true);

            return $r['status'] === 200 && is_array($j) && ($j['success'] ?? false) === true;
        } catch (\Throwable $e) {
            Logger::error('Turnstile check could not be made: ' . $e->getMessage());

            return false;
        }
    }

    /** @throws ValidationException */
    public static function require(Request $request): void
    {
        if (self::enabled() && !self::verify($request)) {
            throw new ValidationException(['Please complete the security check and try again.']);
        }
    }
}
