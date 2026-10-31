<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\Config;

/**
 * Authenticated encryption (AES-256-GCM) for the few values that must be stored but never shown in clear to ordinary users (partner
 * payout details). The key is derived from APP_KEY with HKDF and a fixed purpose label. Without a usable APP_KEY nothing is stored.
 * Format: base64( version byte 0x01 | 12-byte nonce | 16-byte tag | ciphertext ).
 */
final class SecretBox
{
    private static function key(): string
    {
        $raw = (string) Config::get('app.key', '');
        if (str_starts_with($raw, 'base64:')) {
            $raw = (string) base64_decode(substr($raw, 7), true);
        }
        if (strlen($raw) < 16) {
            throw new \RuntimeException('APP_KEY is not set; payout details cannot be stored safely.');
        }

        return hash_hkdf('sha256', $raw, 32, 'vertex-secretbox-v1');
    }

    public static function seal(string $plain): string
    {
        $nonce = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $nonce, $tag, 'vertex');
        if ($cipher === false) {
            throw new \RuntimeException('Encryption failed.');
        }

        return base64_encode("\x01" . $nonce . $tag . $cipher);
    }

    /** @return string|null null when the value is damaged, was sealed with another key, or is not a SecretBox value */
    public static function open(string $sealed): ?string
    {
        $raw = base64_decode($sealed, true);
        if ($raw === false || strlen($raw) < 30 || $raw[0] !== "\x01") {
            return null;
        }
        $plain = openssl_decrypt(substr($raw, 29), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, substr($raw, 1, 12), substr($raw, 13, 16), 'vertex');

        return $plain === false ? null : $plain;
    }
}
