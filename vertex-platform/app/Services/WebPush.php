<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Config;
use Vertex\Core\Database;
use Vertex\Core\Logger;

/**
 * Web Push without any outside library: message encryption (RFC 8291, aes128gcm) and server identification (VAPID, RFC 8292) with PHP's OpenSSL.
 * The browser's push service (Google, Mozilla, Apple, Microsoft) only ever sees an encrypted message; only the person's device can read it.
 */
final class WebPush
{
    /** Push services a subscription may point at. Anything else is refused so the server never calls an address a visitor made up. */
    private const HOSTS = ['fcm.googleapis.com', 'android.googleapis.com', '.push.services.mozilla.com', '.notify.windows.com', '.push.apple.com', 'web.push.apple.com'];

    public static function b64e(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    public static function b64d(string $s): string
    {
        return (string) base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
    }

    public static function allowedEndpoint(string $url): bool
    {
        $p = parse_url($url);
        if (!is_array($p) || ($p['scheme'] ?? '') !== 'https' || !isset($p['host']) || isset($p['user']) || strlen($url) > 1000) {
            return false;
        }
        $h = strtolower((string) $p['host']);
        foreach (self::HOSTS as $allowed) {
            if ($allowed[0] === '.' ? str_ends_with($h, $allowed) : $h === $allowed) {
                return true;
            }
        }

        return false;
    }

    // ------------------------------------------------------------------------------------------------ keys

    /** The server's VAPID key pair; created the first time it is needed. @return array{public:string,private:string} public as base64url raw point, private as PEM */
    public static function keys(): array
    {
        $row = Database::one('SELECT public_key, private_sealed FROM push_vapid WHERE id = 1');
        if ($row === null) {
            $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
            if ($key === false || !openssl_pkey_export($key, $pem)) {
                throw new \RuntimeException('Could not create the notification key.');
            }
            Database::run('INSERT IGNORE INTO push_vapid (id, public_key, private_sealed, created_at) VALUES (1, ?, ?, UTC_TIMESTAMP())', [self::b64e(self::publicPoint($key)), SecretBox::seal($pem)]);
            $row = Database::one('SELECT public_key, private_sealed FROM push_vapid WHERE id = 1') ?? throw new \RuntimeException('Notification key missing.');
        }
        $pem = SecretBox::open((string) $row['private_sealed']) ?? throw new \RuntimeException('The notification key cannot be read (APP_KEY changed). Subscriptions must be renewed.');

        return ['public' => (string) $row['public_key'], 'private' => $pem];
    }

    /** The uncompressed public point (0x04 || X || Y, 65 bytes) of an EC key. */
    private static function publicPoint(\OpenSSLAsymmetricKey $key): string
    {
        $ec = openssl_pkey_get_details($key)['ec'] ?? throw new \RuntimeException('Not an EC key.');

        return "\x04" . str_pad((string) $ec['x'], 32, "\0", STR_PAD_LEFT) . str_pad((string) $ec['y'], 32, "\0", STR_PAD_LEFT);
    }

    private static function publicKeyFromPoint(string $point): \OpenSSLAsymmetricKey
    {
        $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $point;
        $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";

        return openssl_pkey_get_public($pem) ?: throw new \InvalidArgumentException('Invalid subscription key.');
    }

    // ------------------------------------------------------------------------------------------------ encryption

    /**
     * Encrypts $payload for one subscription (RFC 8291). $localKey and $salt are only for tests; in use both are fresh and random.
     *
     * @param string $uaPublic the browser's public key (65 bytes) @param string $auth the browser's 16-byte auth secret
     * @return string the request body
     */
    public static function encrypt(string $payload, string $uaPublic, string $auth, ?\OpenSSLAsymmetricKey $localKey = null, ?string $salt = null): string
    {
        if (strlen($uaPublic) !== 65 || $uaPublic[0] !== "\x04" || strlen($auth) < 16) {
            throw new \InvalidArgumentException('Invalid subscription keys.');
        }
        $localKey ??= openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]) ?: throw new \RuntimeException('No key.');
        $salt ??= random_bytes(16);
        $asPublic = self::publicPoint($localKey);
        $secret = openssl_pkey_derive(self::publicKeyFromPoint($uaPublic), $localKey, 32);
        if ($secret === false) {
            throw new \RuntimeException('Key agreement failed.');
        }
        $ikm = hash_hkdf('sha256', $secret, 32, "WebPush: info\0" . $uaPublic . $asPublic, $auth);
        $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);
        $tag = '';
        $cipher = openssl_encrypt(substr($payload, 0, 3000) . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
        if ($cipher === false) {
            throw new \RuntimeException('Encryption failed.');
        }

        return $salt . pack('N', 4096) . chr(65) . $asPublic . $cipher . $tag;
    }

    /** The receiving side (what a phone does). Used by the tests to prove the message can be read. */
    public static function decrypt(string $body, \OpenSSLAsymmetricKey $uaPrivate, string $uaPublic, string $auth): string
    {
        $salt = substr($body, 0, 16);
        $idLen = ord($body[20]);
        $asPublic = substr($body, 21, $idLen);
        $rest = substr($body, 21 + $idLen);
        $secret = openssl_pkey_derive(self::publicKeyFromPoint($asPublic), $uaPrivate, 32);
        $ikm = hash_hkdf('sha256', (string) $secret, 32, "WebPush: info\0" . $uaPublic . $asPublic, $auth);
        $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);
        $plain = openssl_decrypt(substr($rest, 0, -16), 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, substr($rest, -16));

        return $plain === false ? '' : rtrim($plain, "\x02\0");
    }

    // ------------------------------------------------------------------------------------------------ identification (VAPID)

    /** @param string $privatePem the server key */
    public static function jwt(string $audience, string $subject, string $privatePem, ?int $now = null): string
    {
        $head = self::b64e((string) json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $claims = self::b64e((string) json_encode(['aud' => $audience, 'exp' => ($now ?? time()) + 12 * 3600, 'sub' => $subject]));
        if (!openssl_sign("{$head}.{$claims}", $der, $privatePem, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('Signing failed.');
        }

        return "{$head}.{$claims}." . self::b64e(self::derToRaw($der));
    }

    /** ECDSA signatures come as ASN.1 DER; JWT wants the two 32-byte numbers side by side. */
    public static function derToRaw(string $der): string
    {
        $o = 3 + (ord($der[1]) & 0x80 ? 1 : 0);
        $rLen = ord($der[$o]);
        $r = substr($der, $o + 1, $rLen);
        $o += 1 + $rLen + 1;
        $sLen = ord($der[$o]);
        $s = substr($der, $o + 1, $sLen);

        return str_pad(ltrim($r, "\0"), 32, "\0", STR_PAD_LEFT) . str_pad(ltrim($s, "\0"), 32, "\0", STR_PAD_LEFT);
    }

    // ------------------------------------------------------------------------------------------------ sending

    /**
     * Sends one message to one subscription.
     *
     * @param array{endpoint:string,p256dh:string,auth_secret:string} $sub
     * @return 'sent'|'gone'|'failed' gone = the device unsubscribed (remove it)
     */
    public static function send(array $sub, string $payloadJson, string $urgency = 'normal', int $ttl = 86400): string
    {
        if (!self::allowedEndpoint($sub['endpoint'])) {
            return 'gone';
        }
        try {
            $keys = self::keys();
            $origin = parse_url($sub['endpoint'], PHP_URL_SCHEME) . '://' . parse_url($sub['endpoint'], PHP_URL_HOST);
            $biz = BusinessProfile::get();
            $subject = filter_var($biz['email'] ?? '', FILTER_VALIDATE_EMAIL) ? 'mailto:' . $biz['email'] : (string) Config::get('app.url');
            $body = self::encrypt($payloadJson, self::b64d($sub['p256dh']), self::b64d($sub['auth_secret']));
            $r = Http::request('POST', $sub['endpoint'], [
                'Authorization' => 'vapid t=' . self::jwt($origin, $subject, $keys['private']) . ', k=' . $keys['public'], 'Content-Encoding' => 'aes128gcm',
                'Content-Type' => 'application/octet-stream', 'TTL' => (string) $ttl, 'Urgency' => $urgency,
            ], $body, 15);
        } catch (\Throwable $e) {
            Logger::error('Push send failed', ['error' => $e->getMessage()]);

            return 'failed';
        }
        if ($r['status'] === 404 || $r['status'] === 410) {
            return 'gone';
        }

        return $r['status'] >= 200 && $r['status'] < 300 ? 'sent' : 'failed';
    }
}
