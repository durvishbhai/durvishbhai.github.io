<?php

declare(strict_types=1);

namespace Vertex\Services;

use Vertex\Core\ValidationException;

/**
 * Server-side input rules shared by the CRM, client, project and task services. Each method either returns a clean value
 * or throws ValidationException with a message safe to show to the person who typed it. Control characters are stripped
 * from every text value; output is always escaped by the templates (e()).
 */
final class FieldRules
{
    public static function clean(mixed $v): string
    {
        return is_scalar($v) ? trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string) $v)) : '';
    }

    /** Single-line text. */
    public static function line(mixed $v, string $label, int $min, int $max, bool $required = true): ?string
    {
        $s = (string) preg_replace('/\s+/u', ' ', self::clean($v));
        if ($s === '') {
            if ($required) {
                throw new ValidationException(["{$label} is required."]);
            }

            return null;
        }
        if (mb_strlen($s) < $min || mb_strlen($s) > $max || preg_match('/[<>]/', $s)) {
            throw new ValidationException(["{$label} must be {$min}-{$max} characters and must not contain < or >."]);
        }

        return $s;
    }

    /** Multi-line text (newlines kept). */
    public static function text(mixed $v, string $label, int $min, int $max, bool $required = true): ?string
    {
        $s = str_replace("\r\n", "\n", self::clean($v));
        if ($s === '') {
            if ($required) {
                throw new ValidationException(["{$label} is required."]);
            }

            return null;
        }
        if (mb_strlen($s) < $min || mb_strlen($s) > $max) {
            throw new ValidationException(["{$label} must be {$min}-{$max} characters."]);
        }

        return $s;
    }

    public static function email(mixed $v, bool $required = true): ?string
    {
        $s = mb_strtolower(self::clean($v));
        if ($s === '') {
            if ($required) {
                throw new ValidationException(['Email address is required.']);
            }

            return null;
        }
        if (mb_strlen($s) > 190 || !filter_var($s, FILTER_VALIDATE_EMAIL)) {
            throw new ValidationException(['Enter a valid email address.']);
        }

        return $s;
    }

    public static function phone(mixed $v, bool $required = false): ?string
    {
        $s = self::clean($v);
        if ($s === '') {
            if ($required) {
                throw new ValidationException(['A phone number is required.']);
            }

            return null;
        }
        if (!preg_match('/^\+?[0-9][0-9 ()\-]{6,19}$/', $s)) {
            throw new ValidationException(['Enter a valid phone number (digits, spaces, + ( ) -).']);
        }

        return $s;
    }

    /** Last 10 digits, used for duplicate matching. */
    public static function phoneDigits(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }
        $d = substr((string) preg_replace('/\D/', '', $phone), -10);

        return strlen($d) >= 7 ? $d : null;
    }

    /** Lower-cased, punctuation-free company name for duplicate matching. */
    public static function companyNorm(?string $name): ?string
    {
        if ($name === null) {
            return null;
        }
        $n = trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($name)));

        return $n === '' ? null : $n;
    }

    /** @param array<string,string> $allowed code => label */
    public static function choice(mixed $v, array $allowed, string $label, bool $required = true, ?string $default = null): ?string
    {
        $s = self::clean($v);
        if ($s === '') {
            if ($required && $default === null) {
                throw new ValidationException(["{$label} is required."]);
            }

            return $default;
        }
        if (!array_key_exists($s, $allowed)) {
            throw new ValidationException(["Choose a valid {$label}."]);
        }

        return $s;
    }

    /** Calendar date Y-m-d. $notBefore (Y-m-d) rejects earlier dates. */
    public static function date(mixed $v, string $label, bool $required = false, ?string $notBefore = null): ?string
    {
        $s = self::clean($v);
        if ($s === '') {
            if ($required) {
                throw new ValidationException(["{$label} is required."]);
            }

            return null;
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $s);
        if ($d === false || $d->format('Y-m-d') !== $s || (int) $d->format('Y') < 2000 || (int) $d->format('Y') > 2100) {
            throw new ValidationException(["{$label} must be a valid date (YYYY-MM-DD)."]);
        }
        if ($notBefore !== null && $s < $notBefore) {
            throw new ValidationException(["{$label} cannot be in the past."]);
        }

        return $s;
    }

    public static function minutes(mixed $v, string $label, bool $required = false, int $max = 100000): ?int
    {
        $s = self::clean($v);
        if ($s === '') {
            if ($required) {
                throw new ValidationException(["{$label} is required."]);
            }

            return null;
        }
        if (!ctype_digit($s) || (int) $s > $max) {
            throw new ValidationException(["{$label} must be a whole number of minutes (max {$max})."]);
        }

        return (int) $s;
    }

    /** Money entered as rupees (e.g. "12500.50") -> integer paise. */
    public static function moneyMinor(mixed $v, string $label, bool $required = false): ?int
    {
        $s = str_replace([',', ' '], '', self::clean($v));
        if ($s === '') {
            if ($required) {
                throw new ValidationException(["{$label} is required."]);
            }

            return null;
        }
        if (!preg_match('/^\d{1,12}(\.\d{1,2})?$/', $s)) {
            throw new ValidationException(["{$label} must be an amount like 12500 or 12500.50."]);
        }
        [$whole, $frac] = array_pad(explode('.', $s), 2, '0');

        return (int) $whole * 100 + (int) str_pad($frac, 2, '0');
    }

    /** Integer paise -> "12,500.50" for display. */
    public static function formatMinor(?int $minor): string
    {
        if ($minor === null) {
            return '—';
        }
        $neg = $minor < 0;
        $minor = abs($minor);

        return ($neg ? '-' : '') . number_format(intdiv($minor, 100)) . '.' . str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }
}
