<?php

declare(strict_types=1);

namespace Vertex\Services;

/** RFC 6238 time-based one-time passwords (HMAC-SHA1, 6 digits, 30 s step), compatible with Google Authenticator, Microsoft Authenticator, Authy and 1Password. */
final class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    public static function base32Encode(string $bin): string
    {
        $bits = '';
        foreach (str_split($bin) as $ch) {
            $bits .= str_pad(decbin(ord($ch)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }

        return $out;
    }

    public static function base32Decode(string $text): ?string
    {
        $text = strtoupper(preg_replace('/[\s=-]/', '', $text) ?? '');
        if ($text === '' || strspn($text, self::ALPHABET) !== strlen($text)) {
            return null;
        }
        $bits = '';
        foreach (str_split($text) as $ch) {
            $bits .= str_pad(decbin((int) strpos(self::ALPHABET, $ch)), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }

        return $out;
    }

    public static function step(?int $time = null): int
    {
        return intdiv($time ?? time(), 30);
    }

    public static function code(string $secretBase32, int $step): string
    {
        $key = self::base32Decode($secretBase32) ?? throw new \InvalidArgumentException('Invalid TOTP secret.');
        $hash = hash_hmac('sha1', pack('N*', 0, $step), $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $value = ((ord($hash[$offset]) & 0x7F) << 24) | (ord($hash[$offset + 1]) << 16) | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);

        return str_pad((string) ($value % 1_000_000), 6, '0', STR_PAD_LEFT);
    }

    /**
     * @return int|null the matched time step (accepts one step either side for clock drift), or null when the code is wrong.
     *                  The caller must refuse a step that is not greater than the last one used (no replay of a captured code).
     */
    public static function verify(string $secretBase32, string $input, ?int $time = null): ?int
    {
        $input = preg_replace('/\s+/', '', $input) ?? '';
        if (!preg_match('/^\d{6}$/', $input)) {
            return null;
        }
        $now = self::step($time);
        $found = null;
        for ($i = -1; $i <= 1; $i++) {
            if (hash_equals(self::code($secretBase32, $now + $i), $input)) {
                $found = $now + $i;
            }
        }

        return $found;
    }

    public static function uri(string $secret, string $account, string $issuer): string
    {
        return 'otpauth://totp/' . rawurlencode($issuer . ':' . $account) . '?secret=' . $secret . '&issuer=' . rawurlencode($issuer) . '&algorithm=SHA1&digits=6&period=30';
    }
}
