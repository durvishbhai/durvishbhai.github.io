<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Config;
use Vertex\Core\Logger;
use Vertex\Core\Session;

/**
 * "Continue with Google" (OpenID Connect, authorization-code flow, all on the server).
 *
 * The browser is sent to Google with a one-time `state` and `nonce` kept in the session. Google returns a code; the server exchanges it for tokens over TLS
 * with the client secret and reads the ID token it gets straight from Google's token endpoint (OIDC Core 3.1.3.7 allows TLS to Google to stand in for the
 * signature check). The claims must name OUR client ID as audience, Google as issuer, be unexpired, carry our nonce and a verified email. Nothing from the
 * browser's address bar is believed except the one-time code and the state, which must match the session. No Google token is stored anywhere.
 */
final class GoogleAuth
{
    private const SESSION = 'google_oauth';

    public static function enabled(): bool
    {
        return (bool) Config::get('google.enabled') && self::configured();
    }

    public static function configured(): bool
    {
        return (string) Config::get('google.client_id') !== '' && (string) Config::get('google.client_secret') !== '' && str_starts_with((string) Config::get('app.url'), 'https://');
    }

    /** The address to paste into the Google Cloud Console as "Authorized redirect URI". */
    public static function redirectUri(): string
    {
        return rtrim((string) Config::get('app.url'), '/') . '/auth/google/callback';
    }

    /** What the Owner sees on the settings screen. @return array{ready:bool,reason:string} */
    public static function status(): array
    {
        if (!str_starts_with((string) Config::get('app.url'), 'https://')) {
            return ['ready' => false, 'reason' => 'APP_URL must be the site\'s https:// address.'];
        }
        if (!self::configured()) {
            return ['ready' => false, 'reason' => 'Save the Google client ID and client secret first.'];
        }

        return (bool) Config::get('google.enabled') ? ['ready' => true, 'reason' => ''] : ['ready' => false, 'reason' => 'Switched off.'];
    }

    /** The Google address to send the browser to. Starts a new one-time state. */
    public static function authorizeUrl(): string
    {
        $state = bin2hex(random_bytes(24));
        $nonce = bin2hex(random_bytes(24));
        Session::put(self::SESSION, ['state' => hash('sha256', $state), 'nonce' => $nonce, 'exp' => time() + (int) Config::get('google.state_ttl_seconds', 600)]);

        return (string) Config::get('google.auth_url') . '?' . http_build_query([
            'client_id' => (string) Config::get('google.client_id'), 'redirect_uri' => self::redirectUri(), 'response_type' => 'code', 'scope' => 'openid email profile',
            'state' => $state, 'nonce' => $nonce, 'prompt' => 'select_account',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Turns the callback into a verified Google identity.
     *
     * @return array{sub:string,email:string,name:string}
     * @throws \RuntimeException with a message that is safe to show
     */
    public static function identify(string $code, string $state): array
    {
        $saved = Session::get(self::SESSION);
        Session::forget(self::SESSION); // one try per start
        if (!is_array($saved) || (int) ($saved['exp'] ?? 0) < time() || $state === '' || !hash_equals((string) ($saved['state'] ?? ''), hash('sha256', $state))) {
            throw new \RuntimeException('That Google sign-in took too long or did not start here. Please try again.');
        }
        if ($code === '' || strlen($code) > 2048) {
            throw new \RuntimeException('Google did not confirm the sign-in. Please try again.');
        }
        try {
            $r = Http::request('POST', (string) Config::get('google.token_url'), ['Content-Type' => 'application/x-www-form-urlencoded', 'Accept' => 'application/json'], http_build_query([
                'code' => $code, 'client_id' => (string) Config::get('google.client_id'), 'client_secret' => (string) Config::get('google.client_secret'),
                'redirect_uri' => self::redirectUri(), 'grant_type' => 'authorization_code',
            ]), 20);
        } catch (\RuntimeException $e) {
            Logger::error('Google token request failed', ['error' => $e->getMessage()]);
            throw new \RuntimeException('Google could not be reached. Please try again, or sign in with your password.');
        }
        $tokens = json_decode($r['body'], true);
        if ($r['status'] !== 200 || !is_array($tokens) || !is_string($tokens['id_token'] ?? null)) {
            Logger::error('Google token exchange refused', ['status' => $r['status']]);
            throw new \RuntimeException('Google did not confirm the sign-in. Please try again.');
        }
        $claims = self::claims($tokens['id_token']);
        $now = time();
        $iss = (string) ($claims['iss'] ?? '');
        $aud = $claims['aud'] ?? '';
        $audOk = $aud === (string) Config::get('google.client_id') || (is_array($aud) && in_array((string) Config::get('google.client_id'), $aud, true));
        $verified = $claims['email_verified'] ?? false;
        if (!in_array($iss, ['https://accounts.google.com', 'accounts.google.com'], true) || !$audOk || (int) ($claims['exp'] ?? 0) < $now - 30
            || !hash_equals((string) $saved['nonce'], (string) ($claims['nonce'] ?? '')) || !($verified === true || $verified === 'true')
            || !is_string($claims['sub'] ?? null) || $claims['sub'] === '' || strlen($claims['sub']) > 64 || !filter_var($claims['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
            Logger::security('Google ID token rejected');
            throw new \RuntimeException('Google did not confirm a verified email address for this account.');
        }
        $name = trim(preg_replace('/[<>]+/', '', (string) ($claims['name'] ?? '')) ?? '');

        return ['sub' => $claims['sub'], 'email' => mb_strtolower((string) $claims['email']), 'name' => mb_substr($name !== '' ? $name : (string) explode('@', (string) $claims['email'])[0], 0, 120)];
    }

    /** @return array<string,mixed> the payload of a JWT, or [] when it is not one */
    private static function claims(string $jwt): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return [];
        }
        $json = base64_decode(strtr($parts[1], '-_', '+/') . str_repeat('=', (4 - strlen($parts[1]) % 4) % 4), true);
        $d = $json === false ? null : json_decode($json, true);

        return is_array($d) ? $d : [];
    }
}
